<?php
/**
 * Admissions domain services (admissions unit).
 *
 *  - Pipeline: enquiry -> application -> document_verification -> entrance_interview -> approval -> fee_payment -> confirmed
 *    (closed: waitlisted | rejected | withdrawn). admission_move() validates every transition and writes
 *    admission_stage_history.
 *  - Follow-ups for applications and enquiries (log, schedule next, complete).
 *  - Admission fee recording, conversion of an applicant into a student (students + parents + academic record,
 *    paid invoice/payment in the fees tables).
 *  - Contact-message inbox helpers and the feedback/complaint workflow.
 *
 * Used by app/modules/{admissions,enquiries,...}.php, api/routes/{admissions,enquiries,contact-messages,feedback}.php
 * and admin/print/{admission-form,offer-letter,admission-receipt}.php.
 */

/* ------------------------------------------------------------------
 * Reference data
 * ------------------------------------------------------------------ */

function admission_stages(): array
{
    return [
        'enquiry' => 'Enquiry', 'application' => 'Application', 'document_verification' => 'Document Verification',
        'entrance_interview' => 'Entrance / Interview', 'approval' => 'Approval', 'fee_payment' => 'Fee Payment', 'confirmed' => 'Admission Confirmed',
    ];
}

function admission_closed_stages(): array
{
    return ['waitlisted' => 'Waitlisted', 'rejected' => 'Rejected', 'withdrawn' => 'Withdrawn'];
}

function admission_all_stages(): array
{
    return admission_stages() + admission_closed_stages();
}

function admission_stage_label(?string $stage): string
{
    return admission_all_stages()[(string) $stage] ?? label_from_key((string) $stage);
}

function admission_sources(): array
{
    return ['website' => 'Website', 'walk_in' => 'Walk-in', 'phone' => 'Phone Call', 'social_media' => 'Social Media', 'referral' => 'Referral',
        'campaign' => 'Ad Campaign', 'education_fair' => 'Education Fair', 'whatsapp' => 'WhatsApp', 'other' => 'Other'];
}

function admission_doc_types(): array
{
    return ['photo' => 'Passport Photo', '10th_marksheet' => 'Class 10 Marksheet', '12th_marksheet' => 'Class 12 Marksheet', 'graduation' => 'Graduation Marksheet',
        'id_proof' => 'ID Proof (Aadhaar)', 'transfer_certificate' => 'Transfer Certificate', 'migration' => 'Migration Certificate',
        'caste_certificate' => 'Category Certificate', 'other' => 'Other Document'];
}

function admission_followup_types(): array
{
    return ['call' => 'Phone Call', 'email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'meeting' => 'Meeting', 'campus_visit' => 'Campus Visit', 'note' => 'Note'];
}

function admission_followup_outcomes(): array
{
    return ['interested' => 'Interested', 'callback' => 'Call Back Later', 'not_reachable' => 'Not Reachable', 'visited' => 'Visited Campus',
        'applied' => 'Applied', 'not_interested' => 'Not Interested'];
}

function admission_quotas(): array
{
    return ['general' => 'General Merit', 'management' => 'Management Quota', 'sports' => 'Sports Quota', 'nri' => 'NRI Quota', 'defence' => 'Defence / Ex-servicemen',
        'scholarship' => 'Scholarship', 'lateral' => 'Lateral Entry'];
}

function admission_categories(): array
{
    return ['General' => 'General', 'OBC' => 'OBC', 'SC' => 'SC', 'ST' => 'ST', 'EWS' => 'EWS'];
}

function admission_payment_modes(): array
{
    return ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Debit / Credit Card', 'bank_transfer' => 'Bank Transfer (NEFT/IMPS)', 'cheque' => 'Cheque', 'dd' => 'Demand Draft', 'online' => 'Online Gateway'];
}

function enquiry_statuses(): array
{
    return ['new' => 'New', 'contacted' => 'Contacted', 'interested' => 'Interested', 'not_interested' => 'Not Interested', 'converted' => 'Converted', 'closed' => 'Closed'];
}

function admission_full_name(array $a): string
{
    return trim(preg_replace('/\s+/', ' ', ($a['first_name'] ?? '') . ' ' . ($a['middle_name'] ?? '') . ' ' . ($a['last_name'] ?? '')));
}

/** True while the CRUD engine imports a spreadsheet (suppresses per-row notifications/emails). */
function adm_is_importing(): bool
{
    return in_array('crud_import', array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12), 'function'), true);
}

/** Last 10 digits of a phone number (for duplicate matching). */
function admission_phone_key(?string $phone): string
{
    $d = preg_replace('/\D+/', '', (string) $phone);
    return strlen($d) > 10 ? substr($d, -10) : $d;
}

/** SQL expression giving the last 10 digits of a phone column. */
function admission_phone_sql(string $col): string
{
    return "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), 10)";
}

function admission_session_name(?int $sessionId): string
{
    if ($sessionId) {
        $name = db_value('SELECT name FROM academic_sessions WHERE id = ?', [$sessionId]);
        if ($name) {
            return (string) $name;
        }
    }
    return (string) (current_session()['name'] ?? date('Y'));
}

/** Next application number (consumes the sequence): APP/2026-27/00301 */
function admission_next_application_no(?int $sessionId): string
{
    $name = admission_session_name($sessionId);
    do {
        $no = next_number('application:' . $name, 'APP/' . $name . '/{n:5}');
    } while (db_value('SELECT COUNT(*) FROM admissions WHERE application_no = ?', [$no]));
    return $no;
}

/** Preview of the next application number without consuming it. */
function admission_peek_application_no(?int $sessionId): string
{
    $name = admission_session_name($sessionId);
    $n = (int) db_value('SELECT current_value FROM sequences WHERE name = ?', ['application:' . $name]) + 1;
    return 'APP/' . $name . '/' . str_pad((string) $n, 5, '0', STR_PAD_LEFT);
}

/** Default admission fee: ADM head of the program's fee structure for the session, else the admissions setting. */
function admission_default_fee(?int $programId, ?int $sessionId): float
{
    if ($programId) {
        $amt = db_value(
            "SELECT fsi.amount FROM fee_structure_items fsi JOIN fee_structures fs ON fs.id = fsi.fee_structure_id JOIN fee_heads fh ON fh.id = fsi.fee_head_id
             WHERE fs.program_id = ? AND (fs.academic_session_id = ? OR ? IS NULL) AND fh.code = 'ADM' AND fs.status = 'active' ORDER BY fs.id DESC LIMIT 1",
            [$programId, $sessionId, $sessionId]
        );
        if ($amt !== null && (float) $amt > 0) {
            return (float) $amt;
        }
        $level = db_value('SELECT level FROM programs WHERE id = ?', [$programId]);
        if (in_array($level, ['Certificate', 'Diploma'], true)) {
            return (float) setting('admission_fee_short', 5000);
        }
        if ($level === 'PG') {
            return (float) setting('admission_fee_pg', 25000);
        }
    }
    return (float) setting('admission_fee_default', 15000);
}

/** Document types an applicant must submit (PG programs also need the graduation marksheet). */
function admission_required_docs(array $a): array
{
    $docs = ['photo', '10th_marksheet', '12th_marksheet', 'id_proof'];
    $level = !empty($a['program_id']) ? db_value('SELECT level FROM programs WHERE id = ?', [(int) $a['program_id']]) : null;
    if ($level === 'PG') {
        $docs[] = 'graduation';
    }
    return $docs;
}

/** Applications/enquiries/students sharing a phone or email. */
function admission_duplicates(?string $phone, ?string $email, ?int $excludeAdmissionId = null, ?int $excludeEnquiryId = null): array
{
    $key = admission_phone_key($phone);
    $email = $email ? strtolower(trim($email)) : '';
    if (strlen($key) < 7 && $email === '') {
        return [];
    }
    $out = [];
    $cond = [];
    $args = [];
    if (strlen($key) >= 7) {
        $cond[] = admission_phone_sql('a.phone') . ' = ?';
        $args[] = $key;
    }
    if ($email !== '') {
        $cond[] = 'LOWER(a.email) = ?';
        $args[] = $email;
    }
    $sql = "SELECT a.id, a.application_no, a.first_name, a.last_name, a.stage, a.phone, a.email, p.short_name AS program, s.name AS session_name
            FROM admissions a JOIN programs p ON p.id = a.program_id LEFT JOIN academic_sessions s ON s.id = a.academic_session_id
            WHERE (" . implode(' OR ', $cond) . ')' . ($excludeAdmissionId ? ' AND a.id <> ' . (int) $excludeAdmissionId : '') . ' ORDER BY a.id DESC LIMIT 5';
    foreach (db_all($sql, $args) as $r) {
        $out[] = ['type' => 'application', 'id' => (int) $r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'ref' => $r['application_no'],
            'detail' => $r['program'] . ' · ' . $r['session_name'] . ' · ' . admission_stage_label($r['stage']), 'status' => $r['stage'], 'url' => '/admissions/' . $r['id'],
            'match' => (strlen($key) >= 7 && admission_phone_key($r['phone']) === $key) ? 'phone' : 'email'];
    }
    $econd = [];
    if (strlen($key) >= 7) {
        $econd[] = admission_phone_sql('e.phone') . ' = ?';
    }
    if ($email !== '') {
        $econd[] = 'LOWER(e.email) = ?';
    }
    $sql = "SELECT e.id, e.name, e.status, e.phone, e.email, e.created_at FROM enquiries e WHERE (" . implode(' OR ', $econd) . ')'
        . ($excludeEnquiryId ? ' AND e.id <> ' . (int) $excludeEnquiryId : '') . " AND e.status <> 'converted' ORDER BY e.id DESC LIMIT 5";
    foreach (db_all($sql, $args) as $r) {
        $out[] = ['type' => 'enquiry', 'id' => (int) $r['id'], 'name' => $r['name'], 'ref' => 'Enquiry #' . $r['id'], 'detail' => 'Enquiry · ' . format_date($r['created_at']),
            'status' => $r['status'], 'url' => '/enquiries?q=' . rawurlencode((string) ($r['phone'] ?: $r['email'])),
            'match' => (strlen($key) >= 7 && admission_phone_key($r['phone']) === $key) ? 'phone' : 'email'];
    }
    $scond = [];
    $sargs = [];
    if (strlen($key) >= 7) {
        $scond[] = admission_phone_sql('st.mobile') . ' = ?';
        $sargs[] = $key;
    }
    if ($email !== '') {
        $scond[] = 'LOWER(st.email) = ?';
        $sargs[] = $email;
    }
    foreach (db_all('SELECT st.id, st.first_name, st.last_name, st.student_uid, st.status, st.mobile, st.email FROM students st WHERE (' . implode(' OR ', $scond) . ') LIMIT 3', $sargs) as $r) {
        $out[] = ['type' => 'student', 'id' => (int) $r['id'], 'name' => trim($r['first_name'] . ' ' . $r['last_name']), 'ref' => $r['student_uid'], 'detail' => 'Existing student · ' . label_from_key($r['status']),
            'status' => $r['status'], 'url' => '/students/' . $r['id'], 'match' => (strlen($key) >= 7 && admission_phone_key($r['mobile']) === $key) ? 'phone' : 'email'];
    }
    return $out;
}

/* ------------------------------------------------------------------
 * Pipeline transitions
 * ------------------------------------------------------------------ */

/** Stages an application may move to from $from (forward one step, back one step, close/reopen). */
function admission_transitions(string $from): array
{
    $order = array_keys(admission_stages());
    $i = array_search($from, $order, true);
    if ($i !== false) {
        if ($from === 'confirmed') {
            return [];
        }
        $t = [$order[$i + 1]];
        if ($i > 0) {
            $t[] = $order[$i - 1];
        }
        if ($from === 'approval') {
            $t[] = 'waitlisted';
        }
        $t[] = 'rejected';
        $t[] = 'withdrawn';
        return $t;
    }
    return match ($from) {
        'waitlisted' => ['fee_payment', 'approval', 'rejected', 'withdrawn'],
        'rejected', 'withdrawn' => ['application'],
        default => [],
    };
}

/** Business-rule problems that block moving $a to $to (empty = allowed). */
function admission_stage_blockers(array $a, string $to, ?string $remarks = null): array
{
    $from = $a['stage'];
    $order = array_keys(admission_stages());
    $fromIdx = array_search($from, $order, true);
    $toIdx = array_search($to, $order, true);
    $forward = $toIdx !== false && ($fromIdx === false || $toIdx > $fromIdx);
    $errors = [];
    if (!empty($a['student_id'])) {
        return ['This applicant is already enrolled as a student, so the stage can no longer change.'];
    }
    if ($forward) {
        $docs = db_all('SELECT doc_type, status FROM admission_documents WHERE admission_id = ?', [(int) $a['id']]);
        if ($to === 'document_verification' && !$docs) {
            $errors[] = 'Upload at least one document before starting document verification.';
        }
        if ($toIdx !== false && $toIdx >= array_search('entrance_interview', $order, true)) {
            $pending = count(array_filter($docs, fn ($d) => $d['status'] !== 'verified'));
            $have = array_column(array_filter($docs, fn ($d) => $d['status'] === 'verified'), 'doc_type');
            $missing = array_diff(admission_required_docs($a), $have);
            if ($pending) {
                $errors[] = $pending . ' document' . ($pending === 1 ? ' is' : 's are') . ' not verified yet. Verify or replace ' . ($pending === 1 ? 'it' : 'them') . ' first.';
            } elseif ($missing) {
                $labels = array_map(fn ($d) => admission_doc_types()[$d] ?? $d, $missing);
                $errors[] = 'Missing verified documents: ' . implode(', ', $labels) . '.';
            }
        }
        if ($toIdx !== false && $toIdx >= array_search('approval', $order, true) && $a['entrance_score'] === null && $a['interview_score'] === null) {
            $errors[] = 'Record the entrance or interview score before sending the application for approval.';
        }
        if ($to === 'confirmed') {
            $due = (float) ($a['admission_fee'] ?? 0);
            if ($due <= 0 || (float) $a['fee_paid'] < $due) {
                $errors[] = $due > 0
                    ? 'Record the admission fee (' . money($due - (float) $a['fee_paid']) . ' due) before confirming the admission.'
                    : 'Record the admission fee before confirming the admission.';
            }
        }
    }
    if (($to === 'fee_payment' && in_array($from, ['approval', 'waitlisted'], true)) || (in_array($to, ['rejected', 'waitlisted'], true) && in_array($from, ['approval', 'waitlisted', 'fee_payment'], true))) {
        if (function_exists('can') && !can('admissions', 'approve')) {
            $errors[] = 'Only users with the "approve admissions" permission can approve, waitlist or reject at this stage.';
        }
    }
    if ($to === 'rejected' && trim((string) $remarks) === '') {
        $errors[] = 'Enter the reason for rejecting this application.';
    }
    return $errors;
}

function admission_add_history(int $admissionId, ?string $from, string $to, ?string $remarks = null, ?string $at = null): void
{
    $row = ['admission_id' => $admissionId, 'from_stage' => $from, 'to_stage' => $to, 'remarks' => $remarks !== null ? mb_substr($remarks, 0, 500) : null, 'changed_by' => user_id()];
    if ($at) {
        $row['created_at'] = $at;
    }
    db_insert('admission_stage_history', $row);
}

/**
 * Move an application to another stage. Validates the transition + business rules, writes stage history,
 * activity log and notifications. Throws CrudException with a user-facing message.
 */
function admission_move(int $id, string $to, ?string $remarks = null): array
{
    if (!isset(admission_all_stages()[$to])) {
        throw new CrudException('Unknown stage "' . $to . '".');
    }
    $remarks = $remarks !== null ? trim($remarks) : null;
    $result = db_transaction(function () use ($id, $to, $remarks) {
        $a = db_row('SELECT * FROM admissions WHERE id = ? FOR UPDATE', [$id]);
        if (!$a) {
            throw new CrudException('Application not found. It may have been deleted.');
        }
        $from = $a['stage'];
        if ($from === $to) {
            throw new CrudException('The application is already at ' . admission_stage_label($to) . '.');
        }
        if (!in_array($to, admission_transitions($from), true)) {
            $allowed = array_map('admission_stage_label', admission_transitions($from));
            throw new CrudException('An application cannot move from ' . admission_stage_label($from) . ' to ' . admission_stage_label($to) . '.'
                . ($allowed ? ' Allowed next steps: ' . implode(', ', $allowed) . '.' : ''));
        }
        if ($errors = admission_stage_blockers($a, $to, $remarks)) {
            throw new CrudException(implode(' ', $errors));
        }
        $now = date('Y-m-d H:i:s');
        $upd = ['stage' => $to, 'stage_changed_at' => $now];
        if ($to === 'fee_payment' && in_array($from, ['approval', 'waitlisted'], true)) {
            $upd['approved_by'] = user_id();
            $upd['approved_at'] = $now;
            if (empty($a['offer_letter_no'])) {
                $upd['offer_letter_no'] = next_number('offer_letter', 'GIMT/OL/' . admission_session_name($a['academic_session_id'] ? (int) $a['academic_session_id'] : null) . '/{n:4}');
                $upd['offer_issued_at'] = $now;
            }
            if ($a['admission_fee'] === null) {
                $upd['admission_fee'] = admission_default_fee((int) $a['program_id'], $a['academic_session_id'] ? (int) $a['academic_session_id'] : null);
            }
        }
        if ($to === 'rejected') {
            $upd['rejection_reason'] = mb_substr((string) $remarks, 0, 255);
        }
        if ($to === 'application' && in_array($from, ['rejected', 'withdrawn'], true)) {
            $upd['rejection_reason'] = null;
        }
        if (in_array($to, ['application', 'document_verification', 'entrance_interview', 'approval'], true) && in_array($from, ['fee_payment', 'waitlisted'], true)) {
            $upd['approved_by'] = null;
            $upd['approved_at'] = null;
        }
        db_update('admissions', $upd, 'id = ?', [$id]);
        admission_add_history($id, $from, $to, $remarks);
        return array_merge($a, $upd, ['from_stage' => $from]);
    });
    $name = admission_full_name($result);
    $desc = sprintf('Moved %s (%s) from %s to %s', $name, $result['application_no'], admission_stage_label($result['from_stage']), admission_stage_label($to));
    log_activity(in_array($to, ['fee_payment'], true) && in_array($result['from_stage'], ['approval', 'waitlisted'], true) ? 'approve' : ($to === 'rejected' ? 'reject' : 'update'), 'admissions', $id, $desc . ($remarks ? ' — ' . $remarks : ''));
    $url = 'admin/admissions/' . $id;
    if ($to === 'fee_payment' && in_array($result['from_stage'], ['approval', 'waitlisted'], true)) {
        notify('perm:admissions', 'admission', 'Application approved', $name . ' (' . $result['application_no'] . ') was approved. Offer letter ' . $result['offer_letter_no'] . ' issued.', $url, 'badge-check');
        if (!empty($result['email'])) {
            $program = (string) db_value("SELECT CONCAT(short_name, ' — ', name) FROM programs WHERE id = ?", [(int) $result['program_id']]);
            send_mail($result['email'], 'Offer of admission — ' . $program,
                '<p>Dear ' . e($name) . ',</p><p>Congratulations! Your application <strong>' . e($result['application_no']) . '</strong> for <strong>' . e($program) . '</strong> has been approved. '
                . 'Your offer letter number is <strong>' . e((string) $result['offer_letter_no']) . '</strong>.</p><p>Please pay the admission fee of <strong>' . e(money((float) $result['admission_fee'])) . '</strong> to confirm your seat.</p>',
                ['related_type' => 'admission', 'related_id' => $id]);
        }
    } elseif ($to === 'confirmed') {
        notify('perm:admissions', 'admission', 'Admission confirmed', $name . ' (' . $result['application_no'] . ') completed fee payment and is confirmed.', $url, 'user-check');
    } elseif ($to === 'rejected' && !empty($result['assigned_to'])) {
        notify((int) $result['assigned_to'], 'application', 'Application rejected', $name . ' (' . $result['application_no'] . '): ' . $remarks, $url, 'user-x');
    }
    return db_row('SELECT * FROM admissions WHERE id = ?', [$id]) ?? $result;
}

/* ------------------------------------------------------------------
 * Follow-ups (applications + enquiries)
 * ------------------------------------------------------------------ */

/**
 * Log an interaction and optionally schedule the next follow-up. Open follow-ups of the same application/enquiry are
 * completed automatically (the new interaction fulfils them). Returns the new follow-up id.
 */
function followup_log(array $in): int
{
    $admissionId = !empty($in['admission_id']) ? (int) $in['admission_id'] : null;
    $enquiryId = !empty($in['enquiry_id']) ? (int) $in['enquiry_id'] : null;
    $errors = validate($in, [
        'type' => 'required|in:' . implode(',', array_keys(admission_followup_types())),
        'notes' => 'required|max:2000',
        'outcome' => 'in:' . implode(',', array_keys(admission_followup_outcomes())),
        'next_followup_date' => 'date',
    ], ['next_followup_date' => 'Next follow-up date']);
    if (!$admissionId && !$enquiryId) {
        $errors['notes'] = 'Choose the application or enquiry this follow-up belongs to.';
    }
    if (empty($errors['next_followup_date']) && !empty($in['next_followup_date']) && $in['next_followup_date'] < date('Y-m-d')) {
        $errors['next_followup_date'] = 'Next follow-up date cannot be in the past.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    return db_transaction(function () use ($in, $admissionId, $enquiryId) {
        $now = date('Y-m-d H:i:s');
        db_exec('UPDATE admission_followups SET completed_at = ?, completed_by = ? WHERE completed_at IS NULL AND next_followup_date IS NOT NULL AND '
            . ($admissionId ? 'admission_id = ?' : 'enquiry_id = ?'), [$now, user_id(), $admissionId ?: $enquiryId]);
        $id = db_insert('admission_followups', [
            'admission_id' => $admissionId, 'enquiry_id' => $enquiryId, 'type' => $in['type'], 'notes' => trim((string) $in['notes']),
            'outcome' => ($in['outcome'] ?? '') !== '' ? $in['outcome'] : null,
            'next_followup_date' => ($in['next_followup_date'] ?? '') !== '' ? $in['next_followup_date'] : null, 'created_by' => user_id(),
        ]);
        if ($enquiryId) {
            $e = db_row('SELECT * FROM enquiries WHERE id = ?', [$enquiryId]);
            $upd = ['follow_up_date' => ($in['next_followup_date'] ?? '') !== '' ? $in['next_followup_date'] : null];
            if (!empty($in['status']) && isset(enquiry_statuses()[$in['status']]) && $in['status'] !== 'converted') {
                $upd['status'] = $in['status'];
            } elseif ($e && $e['status'] === 'new') {
                $upd['status'] = ($in['outcome'] ?? '') === 'not_interested' ? 'not_interested' : (($in['outcome'] ?? '') === 'interested' ? 'interested' : 'contacted');
            } elseif ($e && ($in['outcome'] ?? '') === 'not_interested' && $e['status'] !== 'converted') {
                $upd['status'] = 'not_interested';
            }
            db_update('enquiries', $upd, 'id = ?', [$enquiryId]);
        }
        return $id;
    });
}

function followup_complete(int $id): array
{
    $f = db_row('SELECT * FROM admission_followups WHERE id = ?', [$id]);
    if (!$f) {
        throw new CrudException('Follow-up not found.');
    }
    if (!$f['next_followup_date']) {
        throw new CrudException('This entry has no scheduled follow-up to complete.');
    }
    if ($f['completed_at']) {
        throw new CrudException('This follow-up is already marked as done.');
    }
    db_update('admission_followups', ['completed_at' => date('Y-m-d H:i:s'), 'completed_by' => user_id()], 'id = ?', [$id]);
    if ($f['enquiry_id']) {
        db_exec('UPDATE enquiries SET follow_up_date = NULL WHERE id = ? AND follow_up_date = ?', [(int) $f['enquiry_id'], $f['next_followup_date']]);
    }
    return $f;
}

/** Open (scheduled, not completed) follow-ups up to $until with the related application/enquiry. */
function followups_due(string $scope = 'due', int $limit = 50, ?int $sessionId = null, int $offset = 0): array
{
    $today = date('Y-m-d');
    $cond = match ($scope) {
        'overdue' => 'f.next_followup_date < ?',
        'today' => 'f.next_followup_date = ?',
        'upcoming' => 'f.next_followup_date > ?',
        default => 'f.next_followup_date <= ?',
    };
    $args = [$today];
    $sessionSql = '';
    if ($sessionId) {
        $sessionSql = ' AND (f.enquiry_id IS NOT NULL OR a.academic_session_id = ?)';
        $args[] = $sessionId;
    }
    $base = "FROM admission_followups f
             LEFT JOIN admissions a ON a.id = f.admission_id
             LEFT JOIN enquiries e ON e.id = f.enquiry_id
             LEFT JOIN programs pa ON pa.id = a.program_id
             LEFT JOIN programs pe ON pe.id = e.program_id
             LEFT JOIN users u ON u.id = COALESCE(a.assigned_to, e.assigned_to)
             WHERE f.completed_at IS NULL AND f.next_followup_date IS NOT NULL AND $cond $sessionSql
               AND (a.id IS NULL OR a.stage NOT IN ('confirmed','rejected','withdrawn'))
               AND (e.id IS NULL OR e.status NOT IN ('converted','closed','not_interested'))";
    $total = (int) db_value("SELECT COUNT(*) $base", $args);
    $rows = db_all("SELECT f.id, f.type, f.notes, f.outcome, f.next_followup_date, f.created_at, f.admission_id, f.enquiry_id,
                       COALESCE(TRIM(CONCAT_WS(' ', a.first_name, a.last_name)), e.name) AS name,
                       COALESCE(a.phone, e.phone) AS phone, a.application_no, a.stage, e.status AS enquiry_status,
                       COALESCE(pa.short_name, pe.short_name, e.program_interest) AS program, u.name AS counsellor
                    $base ORDER BY f.next_followup_date ASC, f.id ASC LIMIT " . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset), $args);
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
        $r['admission_id'] = $r['admission_id'] ? (int) $r['admission_id'] : null;
        $r['enquiry_id'] = $r['enquiry_id'] ? (int) $r['enquiry_id'] : null;
        $r['days_overdue'] = max(0, (int) ((strtotime($today) - strtotime($r['next_followup_date'])) / 86400));
    }
    return ['rows' => $rows, 'total' => $total];
}

/* ------------------------------------------------------------------
 * Admission fee
 * ------------------------------------------------------------------ */

/**
 * Record (part of) the admission fee on an application. After conversion the payment is also posted to the fees
 * tables (paid invoice + payment + receipt).
 */
function admission_record_fee(int $id, array $in): array
{
    $a = db_row('SELECT * FROM admissions WHERE id = ?', [$id]);
    if (!$a) {
        throw new CrudException('Application not found.');
    }
    if (!in_array($a['stage'], ['fee_payment', 'confirmed'], true)) {
        throw new CrudException('The admission fee can be recorded once the application is approved (Fee Payment stage).');
    }
    $errors = validate($in, [
        'admission_fee' => 'numeric|min:1|max:10000000',
        'amount' => 'required|numeric|min:1|max:10000000',
        'mode' => 'required|in:' . implode(',', array_keys(admission_payment_modes())),
        'paid_on' => 'required|date',
        'reference' => 'max:100',
    ], ['paid_on' => 'Payment date', 'admission_fee' => 'Admission fee', 'mode' => 'Payment mode']);
    $due = isset($in['admission_fee']) && $in['admission_fee'] !== '' ? (float) $in['admission_fee'] : (float) ($a['admission_fee'] ?? 0);
    if ($due <= 0) {
        $due = admission_default_fee((int) $a['program_id'], $a['academic_session_id'] ? (int) $a['academic_session_id'] : null);
    }
    $balance = round($due - (float) $a['fee_paid'], 2);
    if (empty($errors['amount']) && $balance <= 0) {
        $errors['amount'] = 'The admission fee is already fully paid (receipt ' . ($a['fee_receipt_no'] ?: '—') . ').';
    } elseif (empty($errors['amount']) && (float) $in['amount'] > $balance) {
        $errors['amount'] = 'Amount cannot exceed the balance due of ' . money($balance) . '.';
    }
    if (empty($errors['paid_on']) && !empty($in['paid_on']) && $in['paid_on'] > date('Y-m-d')) {
        $errors['paid_on'] = 'Payment date cannot be in the future.';
    }
    if (empty($errors['mode']) && ($in['mode'] ?? '') !== 'cash' && trim((string) ($in['reference'] ?? '')) === '') {
        $errors['reference'] = 'Enter the transaction / UTR / cheque reference for non-cash payments.';
    }
    if ($errors) {
        throw new CrudValidationException($errors);
    }
    $amount = round((float) $in['amount'], 2);
    $receiptNo = next_number('admission_receipt', 'ADMR/' . admission_session_name($a['academic_session_id'] ? (int) $a['academic_session_id'] : null) . '/{n:5}');
    db_transaction(function () use ($id, $a, $in, $due, $amount, $receiptNo) {
        db_update('admissions', [
            'admission_fee' => $due, 'fee_paid' => round((float) $a['fee_paid'] + $amount, 2), 'fee_mode' => $in['mode'],
            'fee_reference' => trim((string) ($in['reference'] ?? '')) ?: null, 'fee_paid_on' => $in['paid_on'], 'fee_receipt_no' => $receiptNo, 'fee_collected_by' => user_id(),
        ], 'id = ?', [$id]);
        if (!empty($a['student_id'])) {
            admission_post_fee_to_student($id, $amount, $receiptNo, (string) $in['mode'], trim((string) ($in['reference'] ?? '')) ?: null, (string) $in['paid_on']);
        }
    });
    $name = admission_full_name($a);
    log_activity('create', 'admissions', $id, sprintf('Recorded admission fee %s from %s (%s) — receipt %s', money($amount), $name, $a['application_no'], $receiptNo));
    notify('perm:fees', 'fee', 'Admission fee received', sprintf('%s received from %s (%s), receipt %s.', money($amount), $name, $a['application_no'], $receiptNo), 'admin/admissions/' . $id, 'indian-rupee');
    return db_row('SELECT * FROM admissions WHERE id = ?', [$id]);
}

/** Post an admission-fee payment to the fees tables for a converted applicant (invoice is created once). */
function admission_post_fee_to_student(int $admissionId, float $amount, string $receiptNo, string $mode, ?string $reference, string $date): int
{
    $a = db_row('SELECT * FROM admissions WHERE id = ?', [$admissionId]);
    if (!$a || !$a['student_id']) {
        throw new CrudException('The applicant has not been converted to a student yet.');
    }
    $studentId = (int) $a['student_id'];
    $sessionName = admission_session_name($a['academic_session_id'] ? (int) $a['academic_session_id'] : null);
    $headId = db_value("SELECT id FROM fee_heads WHERE code = 'ADM'");
    $invoice = null;
    if ($a['payment_id']) {
        $invoice = db_row('SELECT sf.* FROM payments p JOIN student_fees sf ON sf.id = p.student_fee_id WHERE p.id = ?', [(int) $a['payment_id']]);
    }
    if (!$invoice) {
        $invoice = db_row("SELECT * FROM student_fees WHERE student_id = ? AND remarks = ? LIMIT 1", [$studentId, 'Admission fee — ' . $a['application_no']]);
    }
    if (!$invoice) {
        $invoiceNo = next_number('invoice', (string) setting('invoice_prefix', 'INV/{session}/{n:6}'));
        while (db_value('SELECT COUNT(*) FROM student_fees WHERE invoice_no = ?', [$invoiceNo])) {
            $invoiceNo = next_number('invoice', (string) setting('invoice_prefix', 'INV/{session}/{n:6}'));
        }
        $gross = (float) ($a['admission_fee'] ?: $amount);
        $invoiceId = db_insert('student_fees', [
            'invoice_no' => $invoiceNo, 'student_id' => $studentId, 'academic_session_id' => $a['academic_session_id'], 'title' => 'Admission Fee ' . $sessionName,
            'semester_no' => 1, 'gross_amount' => $gross, 'net_amount' => $gross, 'paid_amount' => 0, 'due_date' => $date, 'status' => 'pending',
            'remarks' => 'Admission fee — ' . $a['application_no'], 'created_by' => user_id(),
        ]);
        if ($headId) {
            db_insert('student_fee_items', ['student_fee_id' => $invoiceId, 'fee_head_id' => (int) $headId, 'amount' => $gross]);
        }
        $invoice = db_row('SELECT * FROM student_fees WHERE id = ?', [$invoiceId]);
    }
    if (db_value('SELECT COUNT(*) FROM payments WHERE receipt_no = ?', [$receiptNo])) {
        $receiptNo .= '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
    }
    $paymentId = db_insert('payments', [
        'receipt_no' => $receiptNo, 'student_id' => $studentId, 'admission_id' => $admissionId, 'student_fee_id' => (int) $invoice['id'], 'amount' => $amount,
        'payment_date' => $date, 'mode' => $mode, 'reference_no' => $reference, 'purpose' => 'admission', 'status' => 'success',
        'remarks' => 'Admission fee for application ' . $a['application_no'], 'collected_by' => user_id(),
    ]);
    db_insert('payment_receipts', ['payment_id' => $paymentId, 'receipt_no' => $receiptNo, 'issued_at' => date('Y-m-d H:i:s')]);
    $paid = round((float) $invoice['paid_amount'] + $amount, 2);
    db_update('student_fees', ['paid_amount' => $paid, 'status' => $paid >= (float) $invoice['net_amount'] ? 'paid' : 'partial'], 'id = ?', [(int) $invoice['id']]);
    db_update('admissions', ['payment_id' => $paymentId], 'id = ?', [$admissionId]);
    return $paymentId;
}

/* ------------------------------------------------------------------
 * Conversion to student
 * ------------------------------------------------------------------ */

/**
 * Convert an approved, fee-paid applicant into a student: students + student_parents + student_academic rows with
 * generated Student ID / admission no / roll no, verified documents copied, admission fee posted as a paid invoice.
 */
function admission_convert(int $id): array
{
    $a = db_row('SELECT a.*, p.code AS program_code, p.department_id, p.short_name AS program_short, p.name AS program_name FROM admissions a JOIN programs p ON p.id = a.program_id WHERE a.id = ?', [$id]);
    if (!$a) {
        throw new CrudException('Application not found.');
    }
    if ($a['student_id']) {
        throw new CrudException('This applicant is already enrolled as a student.');
    }
    if (!in_array($a['stage'], ['fee_payment', 'confirmed'], true)) {
        throw new CrudException('Only approved applications with the admission fee paid can be converted. Current stage: ' . admission_stage_label($a['stage']) . '.');
    }
    $due = (float) ($a['admission_fee'] ?? 0);
    $errors = [];
    if ($due <= 0 || (float) $a['fee_paid'] < $due) {
        $errors[] = 'Record the full admission fee' . ($due > 0 ? ' (' . money($due - (float) $a['fee_paid']) . ' due)' : '') . ' before converting.';
    }
    if (!in_array($a['gender'], ['male', 'female', 'other'], true)) {
        $errors[] = 'Gender is required for the student record — edit the application first.';
    }
    if ($a['email'] && ($sid = db_value('SELECT student_uid FROM students WHERE LOWER(email) = ?', [strtolower($a['email'])]))) {
        $errors[] = 'A student with the email ' . $a['email'] . ' already exists (' . $sid . ').';
    }
    if ($errors) {
        throw new CrudException(implode(' ', $errors));
    }
    $sessionId = $a['academic_session_id'] ? (int) $a['academic_session_id'] : current_session_id();
    $sessionName = admission_session_name($sessionId);
    $startYear = (int) substr($sessionName, 0, 4) ?: (int) date('Y');
    $yy = substr((string) $startYear, 2);

    $result = db_transaction(function () use ($a, $id, $sessionId, $startYear, $yy) {
        // Student ID / admission no / roll no per the ID formats in settings. Prefer the students module's generator
        // (shared sequences) so numbers never collide with students added from the Students screen.
        if (is_file(APP_ROOT . '/app/services/students.php')) {
            require_once APP_ROOT . '/app/services/students.php';
        }
        if (function_exists('students_generate_number')) {
            $uid = students_generate_number('student_uid', (int) $a['program_id'], $startYear);
            $admNo = students_generate_number('admission_no', (int) $a['program_id'], $startYear);
            $rollNo = students_generate_number('roll_no', (int) $a['program_id'], $startYear);
        } else {
            $prefix = setting('student_id_prefix', 'GIMT') . $yy . $a['program_code'];
            $max = (int) db_value('SELECT MAX(CAST(SUBSTRING(student_uid, ?) AS UNSIGNED)) FROM students WHERE student_uid LIKE ?', [strlen($prefix) + 1, $prefix . '%']);
            $uid = $prefix . str_pad((string) ($max + 1), 4, '0', STR_PAD_LEFT);
            do {
                $serial = (int) next_number('student_serial', '{n}');
                $admNo = 'ADM/' . $startYear . '/' . str_pad((string) $serial, 5, '0', STR_PAD_LEFT);
            } while (db_value('SELECT COUNT(*) FROM students WHERE admission_no = ?', [$admNo]));
            $fmt = (string) setting('roll_no_format', '{program}{year}{n:3}');
            $rollPrefix = str_replace(['{program}', '{year}'], [$a['program_code'], $yy], preg_replace('/\{n(?::\d+)?\}.*$/', '', $fmt));
            $pad = preg_match('/\{n:(\d+)\}/', $fmt, $mm) ? (int) $mm[1] : 3;
            $maxRoll = (int) db_value('SELECT MAX(CAST(SUBSTRING(roll_no, ?) AS UNSIGNED)) FROM students WHERE roll_no LIKE ? AND program_id = ?', [strlen($rollPrefix) + 1, $rollPrefix . '%', (int) $a['program_id']]);
            $rollNo = $rollPrefix . str_pad((string) ($maxRoll + 1), $pad, '0', STR_PAD_LEFT);
        }
        // Batch + least-filled section of semester 1 in the session
        $batchId = db_value('SELECT id FROM batches WHERE program_id = ? AND start_year = ?', [(int) $a['program_id'], $startYear]);
        $sectionId = db_value(
            "SELECT sc.id FROM sections sc WHERE sc.program_id = ? AND sc.semester_no = 1 AND (sc.academic_session_id = ? OR sc.academic_session_id IS NULL) AND sc.status = 'active'
             ORDER BY (SELECT COUNT(*) FROM students s WHERE s.section_id = sc.id AND s.status = 'active') ASC, sc.name ASC LIMIT 1",
            [(int) $a['program_id'], $sessionId]
        );
        $guardianName = $a['guardian_name'] ?: $a['father_name'];
        $studentId = db_insert('students', [
            'student_uid' => $uid, 'admission_no' => $admNo, 'roll_no' => $rollNo, 'admission_id' => $id,
            'first_name' => $a['first_name'], 'middle_name' => $a['middle_name'], 'last_name' => $a['last_name'], 'photo' => $a['photo'],
            'gender' => $a['gender'], 'dob' => $a['dob'], 'blood_group' => $a['blood_group'], 'category' => $a['category'], 'nationality' => $a['nationality'] ?: 'Indian',
            'aadhaar_no' => $a['aadhaar_no'], 'mobile' => $a['phone'], 'email' => $a['email'] ? strtolower($a['email']) : null, 'whatsapp' => $a['whatsapp'] ?: $a['phone'],
            'address' => $a['address'], 'city' => $a['city'], 'state' => $a['state'], 'country' => $a['country'] ?: 'India', 'pincode' => $a['pincode'],
            'father_name' => $a['father_name'], 'mother_name' => $a['mother_name'], 'guardian_name' => $guardianName,
            'guardian_relation' => $a['guardian_relation'] ?: ($a['guardian_name'] ? null : ($a['father_name'] ? 'Father' : null)),
            'guardian_phone' => $a['guardian_phone'] ?: $a['father_phone'], 'emergency_contact_name' => $guardianName, 'emergency_contact_phone' => $a['guardian_phone'] ?: ($a['father_phone'] ?: $a['mother_phone']),
            'department_id' => $a['department_id'], 'program_id' => $a['program_id'], 'course_id' => $a['course_id'], 'batch_id' => $batchId ?: null,
            'current_semester' => 1, 'section_id' => $sectionId ?: null, 'academic_session_id' => $sessionId, 'admission_date' => date('Y-m-d'),
            'admission_type' => match ($a['quota']) { 'management', 'nri' => 'management', 'scholarship', 'sports' => 'scholarship', 'lateral' => 'lateral', default => 'regular' },
            'previous_qualification' => $a['previous_qualification'], 'previous_percentage' => $a['previous_percentage'],
            'is_hosteller' => (int) $a['hostel_required'], 'uses_transport' => (int) $a['transport_required'], 'status' => 'active',
            'remarks' => 'Admitted through application ' . $a['application_no'], 'created_by' => user_id(),
        ]);
        foreach ([['father', $a['father_name'], $a['father_phone'] ?: $a['guardian_phone'], $a['father_occupation'], $a['family_income'], 1],
                  ['mother', $a['mother_name'], $a['mother_phone'], $a['mother_occupation'], null, 0]] as [$rel, $pname, $pphone, $occ, $inc, $emer]) {
            if ($pname) {
                db_insert('student_parents', ['student_id' => $studentId, 'relation' => $rel, 'name' => $pname, 'phone' => $pphone, 'occupation' => $occ, 'annual_income' => $inc,
                    'address' => $a['address'], 'is_emergency_contact' => $emer]);
            }
        }
        if ($a['guardian_name'] && $a['guardian_name'] !== $a['father_name'] && $a['guardian_name'] !== $a['mother_name']) {
            db_insert('student_parents', ['student_id' => $studentId, 'relation' => 'guardian', 'name' => $a['guardian_name'], 'phone' => $a['guardian_phone'], 'address' => $a['address'],
                'is_emergency_contact' => $a['father_name'] ? 0 : 1]);
        }
        db_insert('student_academic', ['student_id' => $studentId, 'academic_session_id' => $sessionId, 'program_id' => $a['program_id'], 'semester_no' => 1,
            'section_id' => $sectionId ?: null, 'roll_no' => $rollNo, 'status' => 'studying']);
        // Copy verified documents into the student's document vault
        foreach (db_all("SELECT * FROM admission_documents WHERE admission_id = ? AND status = 'verified'", [$id]) as $d) {
            $src = APP_ROOT . '/' . $d['file_path'];
            if (!is_file($src)) {
                continue;
            }
            $dir = 'storage/private/student-docs/' . date('Y/m');
            if (!is_dir(APP_ROOT . '/' . $dir)) {
                @mkdir(APP_ROOT . '/' . $dir, 0775, true);
            }
            $dest = $dir . '/' . bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($d['file_path'], PATHINFO_EXTENSION));
            if (@copy($src, APP_ROOT . '/' . $dest)) {
                db_insert('student_documents', ['student_id' => $studentId, 'doc_type' => $d['doc_type'], 'title' => admission_doc_types()[$d['doc_type']] ?? 'Document',
                    'file_path' => $dest, 'original_name' => $d['original_name'], 'mime' => $d['mime'], 'size_bytes' => $d['size_bytes'], 'is_verified' => 1,
                    'verified_by' => $d['verified_by'], 'verified_at' => $d['verified_at'], 'uploaded_by' => user_id()]);
            }
        }
        $now = date('Y-m-d H:i:s');
        $upd = ['student_id' => $studentId, 'converted_at' => $now];
        if ($a['stage'] !== 'confirmed') {
            $upd['stage'] = 'confirmed';
            $upd['stage_changed_at'] = $now;
            admission_add_history($id, $a['stage'], 'confirmed', 'Converted to student ' . $uid);
        } else {
            admission_add_history($id, 'confirmed', 'confirmed', 'Converted to student ' . $uid);
        }
        db_update('admissions', $upd, 'id = ?', [$id]);
        if ($a['enquiry_id']) {
            db_exec("UPDATE enquiries SET status = 'converted', admission_id = ? WHERE id = ?", [$id, (int) $a['enquiry_id']]);
        }
        if ((float) $a['fee_paid'] > 0) {
            admission_post_fee_to_student($id, (float) $a['fee_paid'], $a['fee_receipt_no'] ?: next_number('admission_receipt', 'ADMR/{session}/{n:5}'), $a['fee_mode'] ?: 'cash', $a['fee_reference'], $a['fee_paid_on'] ?: date('Y-m-d'));
        }
        return ['student_id' => $studentId, 'student_uid' => $uid, 'admission_no' => $admNo, 'roll_no' => $rollNo];
    });
    $name = admission_full_name($a);
    log_activity('create', 'students', $result['student_id'], sprintf('Converted applicant %s (%s) to student %s', $name, $a['application_no'], $result['student_uid']));
    log_activity('update', 'admissions', $id, sprintf('Admission confirmed: %s enrolled in %s as %s', $name, $a['program_short'], $result['student_uid']));
    notify('perm:students', 'admission', 'New admission', sprintf('%s joined %s (Student ID %s).', $name, $a['program_short'], $result['student_uid']), 'admin/students/' . $result['student_id'], 'user-plus');
    if ($a['email']) {
        send_template_mail('admission_confirmed', $a['email'], ['name' => $name, 'program' => $a['program_short'] . ' — ' . $a['program_name'], 'student_uid' => $result['student_uid']],
            ['related_type' => 'admission', 'related_id' => $id]);
    }
    return $result;
}

/* ------------------------------------------------------------------
 * Contact messages
 * ------------------------------------------------------------------ */

function contact_message_types(): array
{
    return ['admission' => 'Admission', 'academic' => 'Academic', 'placement' => 'Placement', 'general' => 'General', 'other' => 'Other'];
}

/** Convert a website contact message into an enquiry (once). Returns the enquiry id. */
function contact_message_to_enquiry(int $id): int
{
    $m = db_row('SELECT * FROM contact_messages WHERE id = ?', [$id]);
    if (!$m) {
        throw new CrudException('Message not found.');
    }
    if ($m['enquiry_id'] && db_value('SELECT COUNT(*) FROM enquiries WHERE id = ?', [(int) $m['enquiry_id']])) {
        throw new CrudException('This message was already converted to enquiry #' . $m['enquiry_id'] . '.');
    }
    if (trim((string) $m['phone']) === '') {
        throw new CrudException('The message has no phone number — enquiries need a phone number to follow up.');
    }
    $enqId = db_transaction(function () use ($m, $id) {
        $programId = null;
        $hay = strtolower($m['subject'] . ' ' . $m['message']);
        foreach (db_all("SELECT id, short_name, code FROM programs WHERE status = 'active' ORDER BY CHAR_LENGTH(short_name) DESC") as $p) {
            $sn = strtolower($p['short_name']);
            if (preg_match('/\b' . preg_quote($sn, '/') . '\b/', $hay)) {
                $programId = (int) $p['id'];
                break;
            }
        }
        $eid = db_insert('enquiries', [
            'name' => $m['name'], 'email' => $m['email'], 'phone' => $m['phone'], 'program_id' => $programId,
            'program_interest' => $programId ? null : mb_substr((string) ($m['subject'] ?: 'General enquiry'), 0, 190), 'source' => 'website',
            'message' => $m['message'], 'status' => 'new', 'priority' => $m['enquiry_type'] === 'admission' ? 'high' : 'medium', 'assigned_to' => null,
            'follow_up_date' => date('Y-m-d', strtotime('+1 day')), 'notes' => 'Created from website contact message #' . $id, 'ip_address' => $m['ip_address'],
        ]);
        db_update('contact_messages', ['enquiry_id' => $eid, 'status' => $m['status'] === 'new' ? 'read' : $m['status'], 'read_at' => $m['read_at'] ?: date('Y-m-d H:i:s')], 'id = ?', [$id]);
        return $eid;
    });
    log_activity('create', 'enquiries', $enqId, sprintf('Converted contact message #%d from %s into enquiry #%d', $id, $m['name'], $enqId));
    notify('perm:enquiries', 'enquiry', 'New enquiry', $m['name'] . ' (' . $m['phone'] . ') — converted from a website message.', 'admin/enquiries?q=' . rawurlencode((string) $m['phone']), 'message-circle');
    return $enqId;
}

/** Notify staff about a new website contact message (call from the public contact form handler). */
function contact_message_notify(int $id): void
{
    $m = db_row('SELECT name, subject, enquiry_type FROM contact_messages WHERE id = ?', [$id]);
    if ($m) {
        notify('perm:contact_messages', 'contact', 'New contact message', $m['name'] . ': ' . ($m['subject'] ?: label_from_key((string) $m['enquiry_type'])), 'admin/contact-messages?id=' . $id, 'inbox');
    }
}

/* ------------------------------------------------------------------
 * Feedback & complaints
 * ------------------------------------------------------------------ */

function feedback_types(): array
{
    return ['feedback' => 'Feedback', 'complaint' => 'Complaint', 'suggestion' => 'Suggestion', 'grievance' => 'Grievance'];
}

function feedback_categories(): array
{
    return ['academic' => 'Academic', 'infrastructure' => 'Infrastructure', 'hostel' => 'Hostel', 'transport' => 'Transport', 'fees' => 'Fees & Accounts',
        'faculty' => 'Faculty', 'administration' => 'Administration', 'library' => 'Library', 'canteen' => 'Canteen', 'ragging' => 'Ragging / Harassment', 'other' => 'Other'];
}

function feedback_statuses(): array
{
    return ['open' => 'Open', 'in_progress' => 'In Progress', 'resolved' => 'Resolved', 'closed' => 'Closed'];
}

/** SLA (hours to resolve) by priority. */
function feedback_sla_hours(string $priority): int
{
    return ['urgent' => 24, 'high' => 48, 'medium' => 96, 'low' => 168][$priority] ?? 96;
}

/** SQL expression: 1 when an unresolved ticket is past its SLA. */
function feedback_breach_sql(string $alias = 't'): string
{
    return "($alias.status IN ('open','in_progress') AND TIMESTAMPDIFF(HOUR, $alias.created_at, NOW()) > CASE $alias.priority WHEN 'urgent' THEN 24 WHEN 'high' THEN 48 WHEN 'low' THEN 168 ELSE 96 END)";
}

function feedback_transitions(string $from): array
{
    return match ($from) {
        'open' => ['in_progress', 'resolved', 'closed'],
        'in_progress' => ['resolved', 'open'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => ['open'],
        default => [],
    };
}

/** Workflow change with validation (resolution notes required to resolve/close). */
function feedback_set_status(int $id, string $to, ?string $resolution = null, ?string $remarks = null): array
{
    $f = db_row('SELECT * FROM feedback WHERE id = ?', [$id]);
    if (!$f) {
        throw new CrudException('Ticket not found.');
    }
    if (!isset(feedback_statuses()[$to])) {
        throw new CrudValidationException(['status' => 'Select a valid status.']);
    }
    if ($f['status'] === $to) {
        throw new CrudException('The ticket is already ' . strtolower(feedback_statuses()[$to]) . '.');
    }
    if (!in_array($to, feedback_transitions($f['status']), true)) {
        throw new CrudException('A ' . strtolower(feedback_statuses()[$f['status']]) . ' ticket cannot move to ' . strtolower(feedback_statuses()[$to]) . '. Allowed: '
            . implode(', ', array_map(fn ($s) => feedback_statuses()[$s], feedback_transitions($f['status']))) . '.');
    }
    $resolution = trim((string) $resolution);
    if (in_array($to, ['resolved', 'closed'], true) && $resolution === '' && trim((string) $f['resolution']) === '') {
        throw new CrudValidationException(['resolution' => 'Describe how the issue was resolved before ' . ($to === 'closed' ? 'closing' : 'resolving') . ' the ticket.']);
    }
    if (mb_strlen($resolution) > 5000) {
        throw new CrudValidationException(['resolution' => 'Resolution notes may not be longer than 5000 characters.']);
    }
    $now = date('Y-m-d H:i:s');
    $upd = ['status' => $to];
    if ($resolution !== '') {
        $upd['resolution'] = $resolution;
    }
    if ($to === 'in_progress' && !$f['started_at']) {
        $upd['started_at'] = $now;
    }
    if ($to === 'resolved' || ($to === 'closed' && !$f['resolved_at'])) {
        $upd['resolved_at'] = $now;
        $upd['resolved_by'] = user_id();
    }
    if ($to === 'closed') {
        $upd['closed_at'] = $now;
    }
    if ($to === 'open' || ($to === 'in_progress' && in_array($f['status'], ['resolved', 'closed'], true))) {
        $upd['resolved_at'] = null;
        $upd['resolved_by'] = null;
        $upd['closed_at'] = null;
    }
    db_update('feedback', $upd, 'id = ?', [$id]);
    $verb = ['in_progress' => 'Started work on', 'resolved' => 'Resolved', 'closed' => 'Closed', 'open' => 'Reopened'][$to];
    log_activity('update', 'feedback', $id, sprintf('%s ticket %s "%s"', $verb, $f['ticket_no'], str_limit($f['subject'], 60)) . ($remarks ? ' — ' . str_limit($remarks, 200) : ''), 'success',
        ['from' => $f['status'], 'to' => $to]);
    if ($f['assigned_to'] && (int) $f['assigned_to'] !== (int) user_id() && in_array($to, ['open'], true)) {
        notify((int) $f['assigned_to'], 'system', 'Ticket reopened', $f['ticket_no'] . ': ' . $f['subject'], 'admin/feedback?id=' . $id, 'message-square');
    }
    if (in_array($to, ['resolved'], true) && $f['email'] && !$f['is_anonymous']) {
        send_mail($f['email'], 'Your ' . $f['type'] . ' ' . $f['ticket_no'] . ' has been resolved',
            '<p>Dear ' . e($f['name']) . ',</p><p>Your ' . e($f['type']) . ' <strong>' . e($f['subject']) . '</strong> (ticket ' . e($f['ticket_no']) . ') has been resolved.</p><p><strong>Resolution:</strong><br>'
            . nl2br(e($resolution !== '' ? $resolution : (string) $f['resolution'])) . '</p>', ['related_type' => 'feedback', 'related_id' => $id]);
    }
    return db_row('SELECT * FROM feedback WHERE id = ?', [$id]);
}

/* ------------------------------------------------------------------
 * Misc
 * ------------------------------------------------------------------ */

/** Indian-system amount in words: 25000 -> "Twenty Five Thousand Rupees Only" */
function adm_amount_in_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
        'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = function (int $n) use ($ones, $tens): string {
        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $three = function (int $n) use ($two, $ones): string {
        $h = intdiv($n, 100);
        $r = $n % 100;
        return trim(($h ? $ones[$h] . ' Hundred' : '') . ($r ? ' ' . $two($r) : ''));
    };
    $rupees = (int) floor($amount);
    $paise = (int) round(($amount - $rupees) * 100);
    if ($rupees === 0 && $paise === 0) {
        return 'Zero Rupees Only';
    }
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $word]) {
        if ($rupees >= $div) {
            $parts[] = ($div === 10000000 ? adm_amount_in_words_int(intdiv($rupees, $div), $two, $three) : $two(intdiv($rupees, $div))) . ' ' . $word;
            $rupees %= $div;
        }
    }
    if ($rupees) {
        $parts[] = $three($rupees);
    }
    $out = trim(implode(' ', $parts)) . ' Rupees';
    if ($paise) {
        $out .= ' and ' . $two($paise) . ' Paise';
    }
    return $out . ' Only';
}

function adm_amount_in_words_int(int $n, callable $two, callable $three): string
{
    return $n < 100 ? $two($n) : $three($n);
}
