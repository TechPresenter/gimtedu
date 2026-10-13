<?php
/**
 * Student portal - area endpoints (/api/portal/student/...). Required by api/routes/portal.php.
 * The auth, dashboard, profile and notices routes live in api/routes/portal.php; data helpers in
 * app/services/portal_student.php.
 *
 * Rules for every route in this file:
 *   - registered with ['auth' => false] and guarded by portal_student_require() (401 signed out / expired,
 *     403 {code: password_change_required} while a temporary password is pending);
 *   - every query is scoped by the signed-in student; ids in the URL (exam, result, invoice, payment, drive,
 *     application, ticket, document) are re-checked against the student and answer 404 when they are not theirs;
 *   - non-GET routes keep the default CSRF check (419) and are rate limited per student.
 *
 *   GET  /portal/student/overview                      dashboard extras (CGPA trend, fee dues, loans due, admit cards, counts)
 *   GET  /portal/student/timetable                     weekly grid of the student's section (published, current session)
 *   GET  /portal/student/attendance?month=YYYY-MM      overall + subject-wise %, monthly calendar, trend, absences
 *   GET  /portal/student/exams                         date sheets, allocation, admit-card availability
 *   GET  /portal/student/results                       published results, summary, SGPA / CGPA trend
 *   GET  /portal/student/results/{id}                  subject-wise marks of one published result
 *   GET  /portal/student/fees                          totals, invoices (+ installments), payments, concessions, payment options
 *   GET  /portal/student/fees/invoices/{id}            invoice detail
 *   GET  /portal/student/library                       membership, current loans, history, fines
 *   GET  /portal/student/hostel                        allotment, roommates (first names), complaints
 *   POST /portal/student/hostel/complaints             {category, priority, subject, description}
 *   GET  /portal/student/transport                     route, stop, timings, vehicle + driver contact, stops
 *   GET  /portal/student/certificates                  issued certificates (verify link), requests, requestable types
 *   POST /portal/student/certificates/request          {type, purpose, note}  -> pending certificate for approval
 *   GET  /portal/student/placement                     eligible open drives, my applications (+ rounds), offers
 *   POST /portal/student/placement/drives/{id}/apply
 *   POST /portal/student/placement/applications/{id}/withdraw
 *   GET  /portal/student/documents                     own uploaded documents with verification status
 *   GET  /portal/student/documents/{id}/file[?download=1]  secure file stream
 *   GET  /portal/student/tickets                       my grievances / feedback (+ counts, types, categories)
 *   GET  /portal/student/tickets/{id}
 *   POST /portal/student/tickets                       {type, category, priority, subject, message, anonymous}
 *   POST /portal/student/tickets/{id}/rate             {rating 1-5}
 *   POST /portal/student/tickets/{id}/reopen           {reason}
 *   POST /portal/student/profile/correction            {field, correct_value, details}  -> administration ticket
 *   GET  /portal/student/print/admit-card/{examId}     A4 HTML print views (scoped; polite HTML error pages)
 *   GET  /portal/student/print/marksheet/{resultId}
 *   GET  /portal/student/print/invoice/{invoiceId}
 *   GET  /portal/student/print/receipt/{paymentId}
 */

require_once APP_ROOT . '/app/services/portal_student.php';

/** Per-student write throttle (in addition to the per-IP limits of the auth endpoints). */
function ps_throttle(array $me, string $what, int $max, int $window): void
{
    if (!rate_limit('portal-student-' . $what . ':' . $me['student_id'], $max, $window)) {
        api_error('You are doing that too often. Please wait a little and try again.', 429);
    }
}

/* ------------------------------------------------------------------ dashboard */

route('GET', '/portal/student/overview', function () {
    $me = portal_student_require();
    api_ok(ps_overview($me['student']));
}, ['auth' => false]);

/* ------------------------------------------------------------------ academics */

route('GET', '/portal/student/timetable', function () {
    $me = portal_student_require();
    api_ok(ps_timetable($me['student']));
}, ['auth' => false]);

route('GET', '/portal/student/attendance', function () {
    $me = portal_student_require();
    api_ok(ps_attendance($me['student'], trim((string) ($_GET['month'] ?? ''))));
}, ['auth' => false]);

route('GET', '/portal/student/exams', function () {
    $me = portal_student_require();
    api_ok(ps_exams($me['student']));
}, ['auth' => false]);

route('GET', '/portal/student/results', function () {
    $me = portal_student_require();
    api_ok(ps_results($me['student']));
}, ['auth' => false]);

route('GET', '/portal/student/results/{id:\d+}', function ($p) {
    $me = portal_student_require();
    $r = ps_result_detail($me['student'], (int) $p['id']);
    if (!$r) {
        api_error('Result not found.', 404);
    }
    api_ok($r);
}, ['auth' => false]);

/* ------------------------------------------------------------------ fees */

route('GET', '/portal/student/fees', function () {
    $me = portal_student_require();
    api_ok(ps_fees($me['student']));
}, ['auth' => false]);

route('GET', '/portal/student/fees/invoices/{id:\d+}', function ($p) {
    $me = portal_student_require();
    $inv = ps_invoice($me['student'], (int) $p['id']);
    if (!$inv) {
        api_error('Invoice not found.', 404);
    }
    $keep = ['id', 'invoice_no', 'title', 'semester_no', 'session_name', 'structure_name', 'gross_amount', 'discount_amount', 'scholarship_amount', 'fine_amount', 'net_amount',
        'paid_amount', 'balance_amount', 'overdue_amount', 'due_date', 'status', 'late_fee_upto', 'late_fee_due', 'late_fee_rate', 'created_at', 'cancelled_reason', 'cancelled_at'];
    $out = array_intersect_key($inv, array_flip($keep));
    foreach (['gross_amount', 'discount_amount', 'scholarship_amount', 'fine_amount', 'net_amount', 'paid_amount', 'balance_amount', 'overdue_amount', 'late_fee_due'] as $k) {
        $out[$k] = (float) ($out[$k] ?? 0);
    }
    $out['semester_no'] = $out['semester_no'] !== null ? (int) $out['semester_no'] : null;
    $out['items'] = array_map(fn ($i) => ['head' => $i['head_name'], 'code' => $i['head_code'], 'amount' => (float) $i['amount']], $inv['items']);
    $out['installments'] = array_map(fn ($i) => ['no' => (int) $i['installment_no'], 'amount' => (float) $i['amount'], 'paid' => (float) $i['paid_amount'], 'due_date' => $i['due_date'], 'status' => $i['status']], $inv['installments']);
    $out['concessions'] = array_map(fn ($c) => ['name' => $c['scholarship_name'] ?: label_from_key((string) $c['type']), 'amount' => (float) $c['amount'], 'reason' => $c['reason'], 'status' => $c['status']], $inv['concessions']);
    $out['payments'] = array_map(fn ($pm) => ['id' => (int) $pm['id'], 'receipt_no' => $pm['receipt_no'], 'date' => $pm['payment_date'], 'mode' => fees_mode_label($pm['mode']),
        'reference_no' => $pm['reference_no'], 'status' => $pm['status'], 'amount' => (float) $pm['applied_amount']], $inv['payments']);
    api_ok($out);
}, ['auth' => false]);

/* ------------------------------------------------------------------ campus */

route('GET', '/portal/student/library', function () {
    $me = portal_student_require();
    api_ok(ps_library($me['student']));
}, ['auth' => false]);

route('GET', '/portal/student/hostel', function () {
    $me = portal_student_require();
    api_ok(ps_hostel($me['student']));
}, ['auth' => false]);

route('POST', '/portal/student/hostel/complaints', function () {
    $me = portal_student_require();
    ps_throttle($me, 'hostel-complaint', 15, 3600);
    $r = ps_hostel_complaint($me['student'], api_input());
    api_ok($r, 'Complaint ' . $r['complaint_no'] . ' has been registered. The hostel office will update you here.');
}, ['auth' => false]);

route('GET', '/portal/student/transport', function () {
    $me = portal_student_require();
    api_ok(ps_transport($me['student']));
}, ['auth' => false]);

/* ------------------------------------------------------------------ certificates */

route('GET', '/portal/student/certificates', function () {
    $me = portal_student_require();
    api_ok(ps_certificates($me['student']));
}, ['auth' => false]);

route('POST', '/portal/student/certificates/request', function () {
    $me = portal_student_require();
    ps_throttle($me, 'certificate', 20, 3600);
    $r = ps_certificate_request($me['student'], api_input());
    api_ok($r, 'Your request for a ' . $r['type_label'] . ' (' . $r['certificate_no'] . ') has been sent for approval.');
}, ['auth' => false]);

/* ------------------------------------------------------------------ placement */

route('GET', '/portal/student/placement', function () {
    $me = portal_student_require();
    api_ok(ps_placement($me['student']));
}, ['auth' => false]);

route('POST', '/portal/student/placement/drives/{id:\d+}/apply', function ($p) {
    $me = portal_student_require();
    ps_throttle($me, 'placement', 30, 3600);
    $r = ps_placement_apply($me['student'], (int) $p['id']);
    if ($r['status'] !== 200) {
        api_error($r['message'], $r['status']);
    }
    api_ok(['id' => $r['id']], $r['message']);
}, ['auth' => false]);

route('POST', '/portal/student/placement/applications/{id:\d+}/withdraw', function ($p) {
    $me = portal_student_require();
    ps_throttle($me, 'placement', 30, 3600);
    $r = ps_placement_withdraw($me['student'], (int) $p['id']);
    if ($r['status'] !== 200) {
        api_error($r['message'], $r['status']);
    }
    api_ok(null, $r['message']);
}, ['auth' => false]);

/* ------------------------------------------------------------------ documents */

route('GET', '/portal/student/documents', function () {
    $me = portal_student_require();
    api_ok(ps_documents($me['student']));
}, ['auth' => false]);

route('GET', '/portal/student/documents/{id:\d+}/file', function ($p) {
    $me = portal_student_require();
    $doc = db_row('SELECT id, student_id, title, file_path, original_name FROM student_documents WHERE id = ? AND student_id = ?', [(int) $p['id'], $me['student_id']]);
    $path = (string) ($doc['file_path'] ?? '');
    $abs = APP_ROOT . '/' . $path;
    if (!$doc || $path === '' || str_contains($path, '..') || !(str_starts_with($path, 'storage/private/') || str_starts_with($path, 'assets/uploads/')) || !is_file($abs)) {
        api_error('Document not found.', 404);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($abs) ?: 'application/octet-stream';
    $ext = pathinfo($abs, PATHINFO_EXTENSION);
    $name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', (string) ($doc['original_name'] ?: ($doc['title'] . ($ext ? '.' . $ext : ''))));
    $download = !empty($_GET['download']);
    $inline = !$download && in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true);
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($abs));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $name . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($abs);
    exit;
}, ['auth' => false]);

/* ------------------------------------------------------------------ grievances / feedback */

route('GET', '/portal/student/tickets', function () {
    $me = portal_student_require();
    api_ok(ps_tickets($me['student']));
}, ['auth' => false]);

route('GET', '/portal/student/tickets/{id:\d+}', function ($p) {
    $me = portal_student_require();
    $f = ps_ticket_row($me['student'], (int) $p['id']);
    if (!$f) {
        api_error('Ticket not found.', 404);
    }
    api_ok(ps_ticket_public($f, true));
}, ['auth' => false]);

route('POST', '/portal/student/tickets', function () {
    $me = portal_student_require();
    ps_throttle($me, 'ticket', 20, 3600);
    $r = ps_ticket_create($me['student'], api_input());
    api_ok($r, 'Ticket ' . $r['ticket_no'] . ' has been submitted. You can track it here.');
}, ['auth' => false]);

route('POST', '/portal/student/tickets/{id:\d+}/rate', function ($p) {
    $me = portal_student_require();
    $f = ps_ticket_row($me['student'], (int) $p['id']);
    if (!$f) {
        api_error('Ticket not found.', 404);
    }
    if (!in_array($f['status'], ['resolved', 'closed'], true)) {
        api_error('You can rate a ticket once it has been resolved.', 422);
    }
    $rating = (int) api_param('rating', 0);
    if ($rating < 1 || $rating > 5) {
        api_error('Choose a rating from 1 to 5.', 422, ['rating' => 'Choose a rating from 1 to 5.']);
    }
    db_update('feedback', ['rating' => $rating], 'id = ?', [(int) $f['id']]);
    log_activity('update', 'feedback', $f['id'], 'Student rated ticket ' . $f['ticket_no'] . ' ' . $rating . '/5 via the student portal');
    api_ok(['rating' => $rating], 'Thank you for your feedback.');
}, ['auth' => false]);

route('POST', '/portal/student/tickets/{id:\d+}/reopen', function ($p) {
    $me = portal_student_require();
    ps_throttle($me, 'ticket-reopen', 5, 3600);
    $f = ps_ticket_row($me['student'], (int) $p['id']);
    if (!$f) {
        api_error('Ticket not found.', 404);
    }
    $reason = trim((string) api_param('reason', ''));
    if (mb_strlen($reason) < 10) {
        api_error('Tell us why the issue is not resolved (at least 10 characters).', 422, ['reason' => 'Tell us why the issue is not resolved (at least 10 characters).']);
    }
    if (mb_strlen($reason) > 1000) {
        api_error('Keep it under 1000 characters.', 422, ['reason' => 'Keep it under 1000 characters.']);
    }
    $t = ps_ticket_public($f, true);
    if (!$t['can_reopen']) {
        api_error('This ticket can no longer be reopened. Please raise a new one.', 422);
    }
    ps_need('admissions');
    feedback_set_status((int) $f['id'], $f['status'] === 'resolved' ? 'in_progress' : 'open', null, 'Reopened by the student: ' . $reason);
    db_update('feedback', ['message' => mb_substr($f['message'] . "\n\n— Reopened by the student on " . date('d M Y') . ":\n" . $reason, 0, 60000)], 'id = ?', [(int) $f['id']]);
    if ($f['assigned_to']) {
        notify((int) $f['assigned_to'], 'feedback', 'Ticket reopened by the student', $f['ticket_no'] . ': ' . str_limit($f['subject'], 80), 'admin/feedback?id=' . $f['id'], 'message-square');
    } else {
        notify('perm:feedback', 'feedback', 'Ticket reopened by the student', $f['ticket_no'] . ': ' . str_limit($f['subject'], 80), 'admin/feedback?id=' . $f['id'], 'message-square');
    }
    api_ok(ps_ticket_public(ps_ticket_row($me['student'], (int) $f['id']), true), 'Ticket ' . $f['ticket_no'] . ' has been reopened.');
}, ['auth' => false]);

route('POST', '/portal/student/profile/correction', function () {
    $me = portal_student_require();
    ps_throttle($me, 'correction', 10, 3600);
    $r = ps_profile_correction($me['student'], api_input());
    api_ok($r, 'Correction request ' . $r['ticket_no'] . ' sent to the academic office. Track it under Grievances & Feedback.');
}, ['auth' => false]);

/* ------------------------------------------------------------------ print views (HTML) */

route('GET', '/portal/student/print/admit-card/{id:\d+}', function ($p) {
    $me = ps_print_guard();
    $d = ps_admit_card_data($me['student'], (int) $p['id']);
    if (!$d) {
        ps_print_fail('Admit card not available', 'Your admit card for this examination is not available. It is issued once you are allocated as an eligible candidate and the date sheet is published.');
    }
    log_activity('print', 'examination', $d['exam']['id'], 'Student ' . $me['student']['student_uid'] . ' downloaded the admit card for "' . $d['exam']['name'] . '" from the portal');
    ps_print_admit_card($d);
    exit;
}, ['auth' => false]);

route('GET', '/portal/student/print/marksheet/{id:\d+}', function ($p) {
    $me = ps_print_guard();
    $d = ps_marksheet_data($me['student'], (int) $p['id']);
    if (!$d) {
        ps_print_fail('Marksheet not available', 'Only your own published results that are not withheld can be printed.');
    }
    log_activity('print', 'results', (int) $d['result']['exam_id'], 'Student ' . $me['student']['student_uid'] . ' downloaded ' . ($d['marksheet'] ? 'marksheet ' . $d['marksheet']['marksheet_no'] : 'a provisional statement of marks') . ' from the portal');
    ps_print_marksheet($d);
    exit;
}, ['auth' => false]);

route('GET', '/portal/student/print/invoice/{id:\d+}', function ($p) {
    $me = ps_print_guard();
    $inv = ps_invoice($me['student'], (int) $p['id']);
    if (!$inv) {
        ps_print_fail('Invoice not found', 'This invoice does not exist or does not belong to your account.');
    }
    ps_print_invoice($inv);
    exit;
}, ['auth' => false]);

route('GET', '/portal/student/print/receipt/{id:\d+}', function ($p) {
    $me = ps_print_guard();
    $pay = ps_payment($me['student'], (int) $p['id']);
    if (!$pay || !in_array($pay['status'], ['success', 'refunded', 'cancelled'], true)) {
        ps_print_fail('Receipt not found', 'This receipt does not exist or does not belong to your account.');
    }
    $balance = (float) (fees_student_totals((int) $me['student_id'])['balance'] ?? 0);
    ps_print_receipt($pay, max(0, $balance));
    exit;
}, ['auth' => false]);
