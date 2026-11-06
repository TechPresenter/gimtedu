<?php
/**
 * WhatsApp event engine — automatic event-based messaging (Module 4) + Event Manager (Module 10).
 *
 * Loaded by the core service (app/services/whatsapp.php) like every app/services/whatsapp_*.php file, so this file only
 * defines functions and registers hooks at include time (no queries).
 *
 *  - Listener wa_evt_handle() on the core event bus ('*'): for a GIMT_* business event it syncs the WhatsApp CRM
 *    contact (links + admission stage), finds the wa_events row, checks it is active, evaluates its conditions,
 *    resolves the recipient (contact | student | guardian | counsellor | custom payload field), builds the template
 *    variables (variable_map → payload / contact / literal, falling back to wa_variables_for_contact) and queues the
 *    template through the core outbox (sender_type event, delay, business hours, dedupe). The returned action summary
 *    is stored by the core in wa_event_logs.actions.
 *  - Tick handler wa_evt_tick(): daily, de-duplicated time-based events (fee instalment due / overdue, class and exam
 *    reminders, admission deadline, incomplete applications, pending payment links, counsellor follow-ups, event
 *    reminders).
 *  - Helpers for the Event Manager API (api/routes/whatsapp/events.php) and the ERP hook file app/whatsapp_hook.php.
 *
 * Prefix: wa_evt_. Event catalogue: database/seed/09_wa_2_events.php. Extra columns: database/schema/50_whatsapp_events.sql.
 */

if (function_exists('wa_on')) {
    wa_on('*', 'wa_evt_handle', 20);
}
if (function_exists('wa_on_tick')) {
    wa_on_tick('events', 'wa_evt_tick');
}

/* ------------------------------------------------------------------------------------------------ vocabularies */

function wa_evt_categories(): array
{
    return [
        'enquiry' => 'Website enquiries',
        'registration' => 'Registration & application',
        'admission' => 'Admissions',
        'course' => 'Courses & academics',
        'payment' => 'Payments & fees',
        'exam' => 'Examinations',
        'certificate' => 'Certificates & documents',
        'support' => 'Support',
        'staff' => 'Staff & admin',
        'custom' => 'Custom events',
    ];
}

function wa_evt_sources(): array
{
    return [
        'website' => 'Website form',
        'admission_portal' => 'Admission portal',
        'payment' => 'Payment gateway',
        'admin' => 'Admin (ERP)',
        'api' => 'External API',
        'webhook' => 'Webhook',
        'js' => 'Website JavaScript',
        'cron' => 'Scheduled (daily)',
    ];
}

function wa_evt_recipients(): array
{
    return [
        'contact' => 'Contact in the event (lead / applicant / student)',
        'student' => 'Student (WhatsApp or mobile)',
        'guardian' => 'Parent / guardian',
        'counsellor' => 'Assigned counsellor / staff member',
        'custom_field' => 'Phone from a payload field',
    ];
}

function wa_evt_ops(): array
{
    return [
        '=' => 'equals', '!=' => 'not equals', '>' => 'greater than', '<' => 'less than', '>=' => 'at least',
        '<=' => 'at most', 'contains' => 'contains', 'not_contains' => 'does not contain', 'in' => 'is one of (comma list)',
        'not_in' => 'is not one of', 'empty' => 'is empty', 'not_empty' => 'is not empty',
    ];
}

/** Contact fields usable in conditions and variable mapping (contact:<field>). */
function wa_evt_contact_fields(): array
{
    return [
        'name' => 'Name', 'phone' => 'Phone', 'email' => 'Email', 'contact_type' => 'Contact type', 'stage' => 'Admission stage',
        'program_name' => 'Program', 'batch' => 'Batch', 'city' => 'City', 'state' => 'State', 'application_no' => 'Application no',
        'admission_status' => 'Admission status', 'payment_status' => 'Payment status', 'language' => 'Language', 'opt_in' => 'Opted in',
    ];
}

/** Admission follow-up stage each business event moves the contact to (forward only; see wa_evt_sync_crm). */
function wa_evt_stage_map(): array
{
    $lead = ['GIMT_ENQUIRY_RECEIVED', 'GIMT_CONTACT_FORM', 'GIMT_CALLBACK_REQUEST', 'GIMT_COUNSELLING_REQUEST', 'GIMT_COURSE_ENQUIRY',
        'GIMT_BROCHURE_DOWNLOAD', 'GIMT_LANDING_LEAD', 'GIMT_CHATBOT_LEAD', 'GIMT_ENQUIRY_REPEAT', 'GIMT_ENQUIRY_CREATED_ADMIN',
        'GIMT_ENQUIRY_CREATED', 'GIMT_ENQUIRY_ASSIGNED', 'GIMT_EVENT_REGISTERED', 'GIMT_DEMO_CLASS_REQUEST', 'GIMT_COURSE_SELECTED'];
    $map = array_fill_keys($lead, 'new_enquiry');
    $map['GIMT_COUNSELLING_SCHEDULED'] = 'counselling_scheduled';
    foreach (['GIMT_APPLICATION_STARTED', 'GIMT_APPLICATION_INCOMPLETE', 'GIMT_STUDENT_REGISTERED', 'GIMT_MOBILE_VERIFIED',
        'GIMT_REGISTRATION_SUCCESS', 'GIMT_PROFILE_COMPLETED'] as $c) {
        $map[$c] = 'application_started';
    }
    foreach (['GIMT_APPLICATION_SUBMITTED', 'GIMT_APPLICATION_CREATED', 'GIMT_APPLICATION_NUMBER_GENERATED', 'GIMT_APPLICATION_UNDER_REVIEW',
        'GIMT_DOCUMENTS_PENDING', 'GIMT_DOCUMENT_VERIFIED', 'GIMT_DOCUMENT_REJECTED', 'GIMT_DOCUMENTS_VERIFIED', 'GIMT_INTERVIEW_SCHEDULED',
        'GIMT_ADMISSION_WAITLISTED'] as $c) {
        $map[$c] = 'application_submitted';
    }
    $map['GIMT_ADMISSION_APPROVED'] = 'payment_pending';
    $map['GIMT_OFFER_LETTER_GENERATED'] = 'payment_pending';
    $map['GIMT_ADMISSION_FEE_RECEIVED'] = 'admission_confirmed';
    $map['GIMT_ADMISSION_CONFIRMED'] = 'admission_confirmed';
    $map['GIMT_STUDENT_ENROLLED'] = 'admission_confirmed';
    $map['GIMT_ADMISSION_REJECTED'] = 'not_interested';
    return $map;
}

/**
 * Codes the contacts part syncs itself (app/services/whatsapp_contacts.php wa_crm_on_business_event). Read from the
 * listeners actually registered on the core event bus, so the two lists can never drift apart; the static list is
 * only the fallback while the contacts service is not loaded.
 */
function wa_evt_crm_owned_codes(): array
{
    $codes = [];
    foreach ($GLOBALS['__wa_listeners'] ?? [] as $code => $list) {
        if ($code === '*' || !is_array($list)) {
            continue;
        }
        foreach ($list as $l) {
            if (($l['fn'] ?? null) === 'wa_crm_on_business_event') {
                $codes[] = (string) $code;
                break;
            }
        }
    }
    return $codes ?: [
        'GIMT_ENQUIRY_RECEIVED', 'GIMT_ENQUIRY_REPEAT', 'GIMT_CALLBACK_REQUEST', 'GIMT_COUNSELLING_REQUEST', 'GIMT_LANDING_LEAD', 'GIMT_COURSE_ENQUIRY',
        'GIMT_CHATBOT_LEAD', 'GIMT_ENQUIRY_CREATED_ADMIN', 'GIMT_ENQUIRY_CREATED', 'GIMT_ENQUIRY_ASSIGNED', 'GIMT_ENQUIRY_STATUS_CHANGED', 'GIMT_FOLLOWUP_LOGGED',
        'GIMT_APPLICATION_SUBMITTED', 'GIMT_APPLICATION_CREATED', 'GIMT_APPLICATION_ASSIGNED', 'GIMT_ADMISSION_STAGE_CHANGED', 'GIMT_ADMISSION_FEE_RECEIVED',
        'GIMT_ADMISSION_CONFIRMED', 'GIMT_STUDENT_ENROLLED', 'GIMT_STUDENT_STATUS_CHANGED', 'GIMT_PAYMENT_SUCCESS', 'GIMT_ONLINE_PAYMENT_SUCCESS',
        'GIMT_FEE_INVOICE_RAISED',
    ];
}

/**
 * Does anything act on this code? The Event Manager (event active), a listener registered for this exact code by
 * another part (CRM sync, …) or an active workflow triggered by it. Bulk events nobody acts on are logged once.
 */
function wa_evt_has_consumers(string $code): bool
{
    $code = strtoupper($code);
    $evt = wa_evt_by_code($code);
    if ($evt && (int) $evt['is_active'] === 1) {
        return true;
    }
    if (!empty($GLOBALS['__wa_listeners'][$code])) {
        return true;
    }
    try {
        return (bool) db_value("SELECT 1 FROM wa_workflows WHERE status = 'active' AND trigger_type = 'event' AND trigger_event = ? LIMIT 1", [$code]);
    } catch (Throwable $e) {
        return false;
    }
}

function wa_evt_stage_rank(string $stage): int
{
    return [
        'new_enquiry' => 1, 'contacted' => 2, 'interested' => 3, 'counselling_scheduled' => 4, 'application_started' => 5,
        'application_submitted' => 6, 'payment_pending' => 7, 'admission_confirmed' => 8, 'not_interested' => 0,
    ][$stage] ?? 0;
}

/** Template name convention: GIMT_PAYMENT_SUCCESS → payment_success (aliases where the template library uses another name). */
function wa_evt_template_name(string $code): string
{
    return wa_evt_template_aliases()[$code] ?? strtolower(preg_replace('/^GIMT_/', '', $code));
}

/** Event code → template name where the name differs from the convention (09_wa_1_templates library names). */
function wa_evt_template_aliases(): array
{
    return [
        'GIMT_CALLBACK_REQUEST' => 'callback_requested',
        'GIMT_COUNSELLING_REQUEST' => 'callback_requested',
        'GIMT_COURSE_ENQUIRY' => 'enquiry_received',
        'GIMT_LANDING_LEAD' => 'enquiry_received',
        'GIMT_CHATBOT_LEAD' => 'enquiry_received',
        'GIMT_DEMO_CLASS_REQUEST' => 'callback_requested',
        'GIMT_ENQUIRY_CREATED_ADMIN' => 'enquiry_received',
        'GIMT_BROCHURE_DOWNLOAD' => 'brochure_requested',
        'GIMT_DOCUMENTS_VERIFIED' => 'documents_approved',
        'GIMT_DOCUMENT_REJECTED' => 'documents_rejected',
        'GIMT_ADMISSION_DEADLINE' => 'admission_deadline_reminder',
        'GIMT_PAYMENT_LINK_CREATED' => 'payment_link_generated',
        'GIMT_RECEIPT_GENERATED' => 'fee_receipt_generated',
        'GIMT_FEE_INSTALMENT_DUE' => 'fee_due_reminder',
        'GIMT_FEE_REMINDER' => 'fee_due_reminder',
        'GIMT_FEE_OVERDUE' => 'installment_overdue',
        'GIMT_REFUND_APPROVED' => 'refund_initiated',
        'GIMT_REFUND_PROCESSED' => 'refund_completed',
        'GIMT_TIMETABLE_PUBLISHED' => 'class_schedule_published',
        'GIMT_EXAM_SCHEDULED' => 'exam_schedule_released',
        'GIMT_CERTIFICATE_ISSUED' => 'certificate_ready',
        'GIMT_TICKET_RESOLVED' => 'complaint_resolved',
        'GIMT_HOLIDAY_ANNOUNCED' => 'holiday_notification',
        'GIMT_EVENT_ANNOUNCED' => 'event_invitation',
    ];
}

/** Template statuses that may be linked + auto-activated (the core refuses to send until it is approved). */
function wa_evt_linkable_status(?string $status): bool
{
    return !in_array((string) $status, ['rejected', 'disabled', 'archived', 'paused'], true);
}

/* ------------------------------------------------------------------------------------------------ small helpers */

function wa_evt_json($v): array
{
    if (is_array($v)) {
        return $v;
    }
    if (!is_string($v) || $v === '') {
        return [];
    }
    $d = json_decode($v, true);
    return is_array($d) ? $d : [];
}

function wa_evt_encode($v): ?string
{
    if ($v === null || $v === [] || $v === '') {
        return null;
    }
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Dot-path read ("student.name", "data.amount") from an array. */
function wa_evt_get(array $arr, string $path)
{
    if (array_key_exists($path, $arr)) {
        return $arr[$path];
    }
    $cur = $arr;
    foreach (explode('.', $path) as $seg) {
        if (!is_array($cur) || !array_key_exists($seg, $cur)) {
            return null;
        }
        $cur = $cur[$seg];
    }
    return $cur;
}

/** Scalar view of a payload (nested keys flattened with dots) — template variables and the mapping UI use it. */
function wa_evt_flatten(array $payload, string $prefix = '', int $depth = 0): array
{
    $out = [];
    foreach ($payload as $k => $v) {
        if (!is_string($k) || $k === '' || $k[0] === '_') {
            continue;
        }
        $key = $prefix . $k;
        if (is_array($v)) {
            if ($depth < 2 && $v && array_keys($v) !== range(0, count($v) - 1)) {
                $out += wa_evt_flatten($v, $key . '.', $depth + 1);
            } elseif ($v && array_keys($v) === range(0, count($v) - 1) && count(array_filter($v, 'is_scalar')) === count($v)) {
                $out[$key] = implode(', ', $v);
            }
            continue;
        }
        if (is_bool($v)) {
            $v = $v ? 'Yes' : 'No';
        }
        if ($v === null || is_scalar($v)) {
            $out[$key] = $v === null ? '' : (string) $v;
        }
    }
    return $out;
}

function wa_evt_phone_digits(?string $raw): ?string
{
    if ($raw === null || trim($raw) === '') {
        return null;
    }
    if (function_exists('wa_normalize_phone')) {
        $n = wa_normalize_phone($raw);
        return $n['phone'] ?? null;
    }
    $d = preg_replace('/\D+/', '', $raw);
    if (strlen($d) === 10) {
        $d = '91' . $d;
    }
    return strlen($d) >= 11 && strlen($d) <= 15 ? $d : null;
}

function wa_evt_mask_phone(?string $p): string
{
    $p = (string) $p;
    return strlen($p) > 6 ? substr($p, 0, 4) . str_repeat('•', max(0, strlen($p) - 7)) . substr($p, -3) : $p;
}

function wa_evt_money($amount): string
{
    $n = (float) $amount;
    if (function_exists('money')) {
        return money($n, fmod($n, 1.0) > 0 ? 2 : 0);
    }
    return '₹' . number_format($n, fmod($n, 1.0) > 0 ? 2 : 0);
}

/** wa_events row by code (cached per request; $fresh re-reads). */
function wa_evt_by_code(string $code, bool $fresh = false): ?array
{
    static $cache = [];
    if ($fresh) {
        unset($cache[$code]);
    }
    if (!array_key_exists($code, $cache)) {
        try {
            $cache[$code] = db_row('SELECT * FROM wa_events WHERE code = ?', [$code]);
        } catch (Throwable $e) {
            $cache[$code] = null;
        }
    }
    return $cache[$code];
}

function wa_evt_find(int $id): ?array
{
    return db_row('SELECT * FROM wa_events WHERE id = ?', [$id]);
}

/** Template for an event: linked template_id, else the naming convention (lowercased code without GIMT_). */
function wa_evt_template_for(array $evt): ?array
{
    $tpl = null;
    if (!empty($evt['template_id'])) {
        $tpl = function_exists('wa_template_find') ? wa_template_find((int) $evt['template_id'])
            : db_row('SELECT * FROM wa_templates WHERE id = ?', [(int) $evt['template_id']]);
    }
    if (!$tpl) {
        $name = wa_evt_template_name((string) $evt['code']);
        $tpl = function_exists('wa_template_find') ? wa_template_find($name)
            : db_row("SELECT * FROM wa_templates WHERE name = ? AND status <> 'archived' ORDER BY (status = 'approved') DESC, id LIMIT 1", [$name]);
    }
    return $tpl ?: null;
}

/** Template variable keys (GIMT named keys) declared in wa_templates.variables, in send order. */
function wa_evt_template_keys(?array $tpl): array
{
    if (!$tpl) {
        return [];
    }
    $v = wa_evt_json($tpl['variables'] ?? null);
    $keys = [];
    foreach (['header', 'body'] as $part) {
        foreach ((array) ($v[$part] ?? []) as $item) {
            if (is_array($item) && !empty($item['key'])) {
                $keys[] = ['key' => (string) $item['key'], 'part' => $part, 'pos' => (int) ($item['pos'] ?? 0), 'sample' => (string) ($item['sample'] ?? '')];
            }
        }
    }
    foreach ((array) ($v['buttons'] ?? []) as $idx => $item) {
        if (is_array($item) && !empty($item['key'])) {
            $keys[] = ['key' => (string) $item['key'], 'part' => 'button', 'pos' => (int) $idx, 'sample' => (string) ($item['sample'] ?? '')];
        }
    }
    if (!$keys) {
        // templates without a mapping: positional {{n}} in the body
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', (string) ($tpl['body'] ?? ''), $m);
        foreach (array_unique($m[1] ?? []) as $n) {
            $keys[] = ['key' => (string) $n, 'part' => 'body', 'pos' => (int) $n, 'sample' => ''];
        }
    }
    return $keys;
}

/* ------------------------------------------------------------------------------------------------ payload enrichment */

/** Student context (name, phones, program, batch, section) for payloads. */
function wa_evt_student(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    return db_row(
        "SELECT s.id, s.student_uid, s.admission_no, s.roll_no, s.first_name, s.middle_name, s.last_name, s.mobile, s.whatsapp,
                s.email, s.guardian_name, s.guardian_phone, s.father_name, s.current_semester, s.status, s.city, s.state,
                s.program_id, s.batch_id, s.section_id, s.admission_id,
                p.name AS program_name, p.short_name AS program_short, b.name AS batch_name, sec.name AS section_name
           FROM students s
           LEFT JOIN programs p ON p.id = s.program_id
           LEFT JOIN batches b ON b.id = s.batch_id
           LEFT JOIN sections sec ON sec.id = s.section_id
          WHERE s.id = ?",
        [$id]
    );
}

/**
 * Fill the standard payload keys (name, phone, email, program, links, counsellor …) from the ids the ERP hook passed,
 * so call sites stay one line. Keys already present are never overwritten.
 */
function wa_evt_enrich(string $code, array $p): array
{
    $set = function (string $k, $v) use (&$p): void {
        if ($v !== null && $v !== '' && (!isset($p[$k]) || $p[$k] === '' || $p[$k] === null)) {
            $p[$k] = is_string($v) ? trim($v) : $v;
        }
    };
    try {
        if (!empty($p['ticket_id']) && empty($p['ticket_no']) && ($f = db_row('SELECT * FROM feedback WHERE id = ?', [(int) $p['ticket_id']]))) {
            $set('ticket_no', $f['ticket_no']);
            $set('ticket_number', $f['ticket_no']);
            $set('ticket_type', $f['type']);
            $set('subject', $f['subject']);
            $set('issue_summary', mb_substr((string) $f['subject'], 0, 120));
            $set('category', $f['category']);
            $set('resolution', $f['resolution']);
            $set('student_id', $f['student_id']);
            $set('name', $f['name']);
            $set('phone', $f['phone']);
            $set('email', $f['email']);
        }
        if (!empty($p['certificate_id']) && empty($p['certificate_no']) && ($c = db_row('SELECT id, certificate_no, type, title, purpose, issue_date, verification_hash, status, revoked_reason, student_id FROM certificates WHERE id = ?', [(int) $p['certificate_id']]))) {
            $set('certificate_no', $c['certificate_no']);
            $set('certificate_type', function_exists('cert_type_label') ? cert_type_label($c['type'])
                : preg_replace('/(?<!certificate)$/i', ' Certificate', ucwords(str_replace('_', ' ', (string) $c['type'])), 1)); // "Bonafide Certificate"
            $set('purpose', $c['purpose']);
            $set('issue_date', $c['issue_date'] ? date('d M Y', strtotime($c['issue_date'])) : '');
            $link = function_exists('cert_verify_url') ? cert_verify_url($c['certificate_no'], $c['verification_hash']) : absolute_url('verify-certificate/' . rawurlencode($c['certificate_no']));
            $set('verify_link', $link);
            $set('certificate_link', $link);
            $set('reason', $c['revoked_reason']);
            $set('student_id', $c['student_id']);
        }
        if (!empty($p['exam_id']) && empty($p['exam_name']) && ($ex =db_row('SELECT name, start_date, end_date, result_date FROM exams WHERE id = ?', [(int) $p['exam_id']]))) {
            $set('exam_name', $ex['name']);
            $set('exam_date', $ex['start_date'] ? date('d M Y', strtotime($ex['start_date'])) : '');
            $set('start_date', $ex['start_date'] ? date('d M Y', strtotime($ex['start_date'])) : '');
            $set('end_date', $ex['end_date'] ? date('d M Y', strtotime($ex['end_date'])) : '');
        }
        if (!empty($p['exam_id']) && !empty($p['student_id'])) {
            if (in_array($code, ['GIMT_RESULT_PUBLISHED', 'GIMT_MARKS_UPDATED', 'GIMT_MARKSHEET_GENERATED'], true)
                && ($r = db_row('SELECT id, sgpa, cgpa, percentage, result_status, class_rank FROM results WHERE exam_id = ? AND student_id = ?', [(int) $p['exam_id'], (int) $p['student_id']]))) {
                $set('result_status', ucfirst(strtolower((string) $r['result_status'])));
                $set('sgpa', $r['sgpa'] !== null ? number_format((float) $r['sgpa'], 2) : '');
                $set('cgpa', $r['cgpa'] !== null ? number_format((float) $r['cgpa'], 2) : '');
                $set('percentage', $r['percentage'] !== null ? number_format((float) $r['percentage'], 1) : '');
                if ($code === 'GIMT_MARKSHEET_GENERATED' && ($m = db_row('SELECT marksheet_no FROM marksheets WHERE result_id = ? ORDER BY id DESC LIMIT 1', [(int) $r['id']]))) {
                    $set('marksheet_no', $m['marksheet_no']);
                }
            }
            if (in_array($code, ['GIMT_ADMIT_CARD_GENERATED', 'GIMT_EXAM_REMINDER', 'GIMT_EXAM_SCHEDULED'], true)) {
                $set('hall_ticket_no', db_value('SELECT hall_ticket_no FROM exam_students WHERE exam_id = ? AND student_id = ?', [(int) $p['exam_id'], (int) $p['student_id']]));
            }
            $set('result_link', absolute_url('portal/student'));
            $set('admit_card_link', absolute_url('portal/student'));
            $set('marksheet_link', absolute_url('portal/student'));
        }
        if (!empty($p['admission_id']) && in_array($code, ['GIMT_DOCUMENTS_PENDING', 'GIMT_DOCUMENT_REJECTED'], true) && empty($p['document_list'])) {
            $set('document_list', wa_evt_pending_docs((int) $p['admission_id']));
            $set('pending_documents', $p['document_list'] ?? null);
        }
        if (!empty($p['student_id']) && ($s = wa_evt_student((int) $p['student_id']))) {
            $name = trim(preg_replace('/\s+/', ' ', $s['first_name'] . ' ' . ($s['middle_name'] ?? '') . ' ' . ($s['last_name'] ?? '')));
            $set('name', $name);
            $set('student_name', $name);
            $set('first_name', $s['first_name']);
            $set('phone', $s['whatsapp'] ?: $s['mobile']);
            $set('email', $s['email']);
            $set('student_uid', $s['student_uid']);
            $set('admission_no', $s['admission_no']);
            $set('roll_no', $s['roll_no']);
            $set('program_id', $s['program_id']);
            $set('program_name', $s['program_name']);
            $set('course_name', $s['program_name']);
            $set('batch_name', $s['batch_name']);
            $set('section_name', $s['section_name']);
            $set('semester', $s['current_semester']);
            $set('guardian_name', $s['guardian_name'] ?: $s['father_name']);
            $set('guardian_phone', $s['guardian_phone']);
            $set('city', $s['city']);
            $set('portal_link', absolute_url('portal/student'));
        }
        if (!empty($p['admission_id']) && ($a = db_row(
            'SELECT a.*, p.name AS program_name FROM admissions a LEFT JOIN programs p ON p.id = a.program_id WHERE a.id = ?',
            [(int) $p['admission_id']]
        ))) {
            $name = trim(preg_replace('/\s+/', ' ', $a['first_name'] . ' ' . ($a['middle_name'] ?? '') . ' ' . ($a['last_name'] ?? '')));
            $set('name', $name);
            $set('student_name', $name);
            $set('first_name', $a['first_name']);
            $set('phone', $a['whatsapp'] ?: $a['phone']);
            $set('email', $a['email']);
            $set('application_no', $a['application_no']);
            $set('program_id', $a['program_id']);
            $set('program_name', $a['program_name']);
            $set('course_name', $a['program_name']);
            $set('enquiry_id', $a['enquiry_id']);
            $set('counsellor_id', $a['assigned_to']);
            $set('admission_stage', $a['stage']);
            $set('guardian_name', $a['guardian_name'] ?: ($a['father_name'] ?: $a['mother_name']));
            $set('guardian_phone', $a['guardian_phone'] ?: ($a['father_phone'] ?: $a['mother_phone']));
            $set('city', $a['city']);
            $set('state', $a['state']);
            if (!empty($a['offer_letter_no'])) {
                $set('offer_letter_no', $a['offer_letter_no']);
            }
            if ($a['admission_fee'] !== null && (float) $a['admission_fee'] > 0) {
                $set('admission_fee', (float) $a['admission_fee']);
                $set('admission_fee_text', wa_evt_money($a['admission_fee']));
            }
        }
        if (!empty($p['enquiry_id']) && empty($p['phone']) && ($e = db_row(
            'SELECT e.*, p.name AS program_name FROM enquiries e LEFT JOIN programs p ON p.id = e.program_id WHERE e.id = ?',
            [(int) $p['enquiry_id']]
        ))) {
            $set('name', $e['name']);
            $set('student_name', $e['name']);
            $set('phone', $e['phone']);
            $set('email', $e['email']);
            $set('city', $e['city']);
            $set('program_id', $e['program_id']);
            $set('program_name', $e['program_name'] ?: $e['program_interest']);
            $set('course_name', $e['program_name'] ?: $e['program_interest']);
            $set('counsellor_id', $e['assigned_to']);
        }
        if (!empty($p['counsellor_id']) && (empty($p['counsellor_name']) || empty($p['counsellor_phone']))) {
            if ($u = db_row('SELECT name, phone, email FROM users WHERE id = ?', [(int) $p['counsellor_id']])) {
                $set('counsellor_name', $u['name']);
                $set('counsellor_phone', $u['phone']);
            }
        }
        if (isset($p['amount']) && is_numeric($p['amount'])) {
            $set('amount_text', wa_evt_money($p['amount']));
            $set('fee_amount', wa_evt_money($p['amount']));
        }
        if (!empty($p['application_no'])) {
            $set('application_number', $p['application_no']);
        }
        if (!empty($p['receipt_no'])) {
            $set('receipt_number', $p['receipt_no']);
        }
        if (!empty($p['ticket_no'])) {
            $set('ticket_number', $p['ticket_no']);
        }
        if (!empty($p['new_status']) && is_string($p['new_status'])) {
            // student / enquiry status codes ("passed_out") as a readable label for templates ("Passed Out")
            $set('status_label', ucwords(str_replace('_', ' ', $p['new_status'])));
        }
        if (!empty($p['name'])) {
            $set('first_name', strtok((string) $p['name'], ' '));
        }
        if ($code === 'GIMT_BROCHURE_DOWNLOAD' && empty($p['brochure_link'])) {
            $file = !empty($p['program_id']) ? db_value('SELECT brochure FROM programs WHERE id = ?', [(int) $p['program_id']]) : null;
            $set('brochure_link', $file ? (preg_match('#^https?://#', (string) $file) ? $file : absolute_url(ltrim((string) $file, '/'))) : absolute_url('programs'));
        }
        if (!empty($p['program_id']) && empty($p['course_duration'])) {
            $set('course_duration', db_value('SELECT COALESCE(NULLIF(duration_label, \'\'), CONCAT(duration_years, \' years\')) FROM programs WHERE id = ?', [(int) $p['program_id']]));
        }
        if (in_array(wa_evt_by_code($code)['category'] ?? '', ['enquiry', 'registration', 'admission'], true)) {
            $deadline = wa_evt_deadline();
            $set('last_date', $deadline ? date('d M Y', $deadline) : 'the last date');
        }
        $set('institute_name', (string) setting('site_name', setting('institute_name', 'GIMT')));
        $set('support_number', (string) setting('phone', '') ?: (string) setting('whatsapp_number', ''));
    } catch (Throwable $e) {
        error_log('[GIMT] WhatsApp event payload enrichment failed for ' . $code . ': ' . $e->getMessage());
    }
    return $p;
}

/** Required documents of an application that are missing, pending or rejected ("12th Marksheet, ID Proof"). */
function wa_evt_pending_docs(int $admissionId): string
{
    $a = db_row('SELECT id, program_id FROM admissions WHERE id = ?', [$admissionId]);
    if (!$a) {
        return '';
    }
    $labels = function_exists('admission_doc_types') ? admission_doc_types() : [];
    $required = function_exists('admission_required_docs') ? admission_required_docs($a) : ['photo', '10th_marksheet', '12th_marksheet', 'id_proof'];
    $have = db_pairs('SELECT doc_type, status FROM admission_documents WHERE admission_id = ? ORDER BY id', [$admissionId]);
    $out = [];
    foreach (array_unique(array_merge($required, array_keys($have))) as $t) {
        $st = $have[$t] ?? null;
        if ($st === 'verified' || ($st === null && !in_array($t, $required, true))) {
            continue;
        }
        $label = $labels[$t] ?? ucwords(str_replace('_', ' ', (string) $t));
        $out[] = is_array($label) ? ($label['label'] ?? $t) : $label;
    }
    return $out ? implode(', ', $out) : 'none — all documents received';
}

/* ------------------------------------------------------------------------------------------------ conditions */

/**
 * Evaluate [{field, op, value}] (all must match). Fields: "amount", "payload.amount", "data.x" (dot path into the
 * payload) or "contact.stage" (recipient contact field). Returns [bool, ?string failed condition].
 */
function wa_evt_conditions_match(array $conditions, array $payload, ?array $contact): array
{
    foreach ($conditions as $c) {
        if (!is_array($c) || empty($c['field'])) {
            continue;
        }
        $field = trim((string) $c['field']);
        $op = (string) ($c['op'] ?? '=');
        $expected = $c['value'] ?? '';
        if (str_starts_with($field, 'contact.')) {
            $actual = $contact[substr($field, 8)] ?? null;
        } else {
            $actual = wa_evt_get($payload, str_starts_with($field, 'payload.') ? substr($field, 8) : $field);
        }
        if (is_array($actual)) {
            $actual = implode(',', array_filter($actual, 'is_scalar'));
        }
        $a = $actual === null ? '' : trim((string) $actual);
        $e = is_array($expected) ? implode(',', $expected) : trim((string) $expected);
        $list = array_values(array_filter(array_map(fn ($x) => mb_strtolower(trim($x)), explode(',', $e)), fn ($x) => $x !== ''));
        $num = is_numeric($a) && is_numeric($e);
        $ok = match ($op) {
            '=' => $num ? (float) $a == (float) $e : mb_strtolower($a) === mb_strtolower($e),
            '!=' => $num ? (float) $a != (float) $e : mb_strtolower($a) !== mb_strtolower($e),
            '>' => $num && (float) $a > (float) $e,
            '<' => $num && (float) $a < (float) $e,
            '>=' => $num && (float) $a >= (float) $e,
            '<=' => $num && (float) $a <= (float) $e,
            'contains' => $e === '' || str_contains(mb_strtolower($a), mb_strtolower($e)),
            'not_contains' => $e === '' || !str_contains(mb_strtolower($a), mb_strtolower($e)),
            'in' => in_array(mb_strtolower($a), $list, true),
            'not_in' => !in_array(mb_strtolower($a), $list, true),
            'empty' => $a === '',
            'not_empty' => $a !== '',
            default => true,
        };
        if (!$ok) {
            $opLabel = wa_evt_ops()[$op] ?? $op;
            return [false, $field . ' ' . $opLabel . (in_array($op, ['empty', 'not_empty'], true) ? '' : ' "' . $e . '"') . ' (was "' . mb_substr($a, 0, 60) . '")'];
        }
    }
    return [true, null];
}

/* ------------------------------------------------------------------------------------------------ recipient + variables */

/** Contact row for a phone (created/updated when needed). */
function wa_evt_contact_for_phone(string $rawPhone, array $data = []): ?array
{
    $phone = wa_evt_phone_digits($rawPhone);
    if (!$phone || !function_exists('wa_contact_upsert')) {
        return null;
    }
    $existing = function_exists('wa_contact_by_phone') ? wa_contact_by_phone($phone) : null;
    if ($existing && !$data) {
        return $existing;
    }
    $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
    $id = wa_contact_upsert(['phone' => $phone] + $data);
    return $id ? wa_contact($id) : $existing;
}

/**
 * Who receives the message. Returns ['contact' => ?array, 'phone' => ?string, 'error' => ?string, 'via' => string].
 */
function wa_evt_resolve_recipient(array $evt, array $payload, ?array $contact): array
{
    $type = (string) ($evt['recipient'] ?: 'contact');
    $out = ['contact' => null, 'phone' => null, 'error' => null, 'via' => $type];
    $student = !empty($payload['student_id']) ? wa_evt_student((int) $payload['student_id']) : null;
    $phone = null;
    $data = [];
    switch ($type) {
        case 'student':
            $phone = $payload['student_phone'] ?? null;
            if (!$phone && $student) {
                $phone = $student['whatsapp'] ?: $student['mobile'];
            }
            if (!$phone && $contact) {
                $out['contact'] = $contact;
                $out['phone'] = $contact['phone'];
                return $out;
            }
            $data = ['name' => $payload['student_name'] ?? $payload['name'] ?? null, 'contact_type' => 'student', 'student_id' => $student['id'] ?? null];
            break;
        case 'guardian':
            $phone = $payload['guardian_phone'] ?? $payload['parent_phone'] ?? null;
            if (!$phone && $student) {
                $phone = $student['guardian_phone'] ?: db_value("SELECT phone FROM student_parents WHERE student_id = ? AND phone IS NOT NULL AND phone <> '' ORDER BY is_emergency_contact DESC, FIELD(relation, 'guardian', 'father', 'mother', 'other') LIMIT 1", [(int) $student['id']]);
            }
            if (!$phone && !empty($payload['admission_id'])) {
                $a = db_row('SELECT guardian_phone, father_phone, mother_phone FROM admissions WHERE id = ?', [(int) $payload['admission_id']]);
                $phone = $a ? ($a['guardian_phone'] ?: ($a['father_phone'] ?: $a['mother_phone'])) : null;
            }
            $data = ['name' => $payload['guardian_name'] ?? (isset($payload['student_name']) ? 'Parent of ' . $payload['student_name'] : null), 'contact_type' => 'guardian'];
            break;
        case 'counsellor':
            $phone = $payload['counsellor_phone'] ?? null;
            $uid = (int) ($payload['counsellor_id'] ?? $payload['assigned_to'] ?? ($contact['counsellor_id'] ?? 0));
            $u = $uid ? db_row('SELECT id, name, phone FROM users WHERE id = ?', [$uid]) : null;
            if (!$phone && $u) {
                $phone = $u['phone'];
            }
            $data = ['name' => $payload['counsellor_name'] ?? ($u['name'] ?? null), 'contact_type' => 'staff', 'user_id' => $u['id'] ?? null];
            break;
        case 'custom_field':
            $field = trim((string) ($evt['recipient_field'] ?? ''));
            if ($field === '') {
                $out['error'] = 'Recipient field is not set';
                return $out;
            }
            $phone = wa_evt_get($payload, $field);
            $phone = is_scalar($phone) ? (string) $phone : null;
            break;
        default:
            if ($contact && !empty($contact['id'])) {
                $out['contact'] = $contact;
                $out['phone'] = $contact['phone'] ?? null;
                return $out;
            }
            $phone = $payload['phone'] ?? $payload['mobile'] ?? $payload['whatsapp'] ?? null;
            $data = ['name' => $payload['name'] ?? null, 'email' => $payload['email'] ?? null];
    }
    if (!$phone || !is_string($phone) || trim($phone) === '') {
        $out['error'] = 'No phone number for the ' . ($type === 'custom_field' ? 'payload field "' . ($evt['recipient_field'] ?? '') . '"' : $type);
        return $out;
    }
    $digits = wa_evt_phone_digits($phone);
    if (!$digits) {
        $out['error'] = 'Invalid phone number for the ' . $type;
        return $out;
    }
    $out['phone'] = $digits;
    $out['contact'] = wa_evt_contact_for_phone($digits, $type === 'contact' || $type === 'custom_field' ? [] : $data);
    if (!$out['contact']) {
        $out['error'] = 'Could not create a WhatsApp contact for ' . wa_evt_mask_phone($digits);
    }
    return $out;
}

/**
 * Split a variable_map entry into [source, fallback]. An entry is "payload:key" | "contact:field" | "text:literal" |
 * a bare payload key, optionally followed by "|default:<text>" — the text used when the source, the payload key of the
 * same name and the contact's CRM / ERP data all leave the variable empty. "default:<text>" alone = Automatic + that
 * fallback. A "text:" literal is taken whole (it is never empty, so it needs no fallback).
 * e.g. "payload:program_name|default:our programmes" → ['payload:program_name', 'our programmes'].
 */
function wa_evt_map_parts(string $raw): array
{
    $raw = trim($raw);
    if (str_starts_with($raw, 'text:')) {
        return [$raw, null];
    }
    if (str_starts_with($raw, 'default:')) {
        return ['', trim(substr($raw, 8))];
    }
    $at = strpos($raw, '|default:');
    if ($at === false) {
        return [$raw, null];
    }
    return [trim(substr($raw, 0, $at)), trim(substr($raw, $at + 9))];
}

/** Resolve one variable_map source: "payload:key" | "contact:field" | "text:literal" | bare payload key | literal. */
function wa_evt_map_value(string $source, array $payload, array $flat, ?array $contact): ?string
{
    $source = trim($source);
    if ($source === '') {
        return null;
    }
    if (preg_match('/^(payload|contact|text):(.*)$/s', $source, $m)) {
        [$kind, $ref] = [$m[1], trim($m[2])];
        if ($kind === 'text') {
            return $ref;
        }
        if ($kind === 'contact') {
            $v = $contact[$ref] ?? null;
            return $v === null ? null : (string) $v;
        }
        $v = $flat[$ref] ?? wa_evt_get($payload, $ref);
        return is_scalar($v) ? (string) $v : null;
    }
    if (array_key_exists($source, $flat)) {
        return $flat[$source];
    }
    $v = wa_evt_get($payload, $source);
    if ($v !== null && is_scalar($v)) {
        return (string) $v;
    }
    return $source; // literal
}

/** Named template variables for one send. */
function wa_evt_build_vars(array $evt, ?array $tpl, array $payload, ?array $contact): array
{
    $flat = wa_evt_flatten($payload);
    $vars = [];
    if ($contact && function_exists('wa_variables_for_contact')) {
        try {
            $vars = (array) wa_variables_for_contact($contact, $flat);
        } catch (Throwable $e) {
            $vars = [];
        }
    }
    foreach ($flat as $k => $v) {
        if ($v !== '') {
            $vars[$k] = $v;
        }
    }
    $fallbacks = [];
    foreach (wa_evt_json($evt['variable_map'] ?? null) as $key => $raw) {
        if (!is_string($key) || $key === '' || !is_scalar($raw)) {
            continue;
        }
        [$source, $fallback] = wa_evt_map_parts((string) $raw);
        if ($fallback !== null && $fallback !== '') {
            $fallbacks[$key] = $fallback;
        }
        $v = $source === '' ? null : wa_evt_map_value($source, $payload, $flat, $contact);
        if ($v !== null && $v !== '') {
            $vars[$key] = $v;
        }
    }
    // the event's own defaults ("|default:" in the mapping) fill what the payload and the contact left empty, e.g. a
    // website enquiry without a programme: {{course_name}} = "our programmes" instead of a refused send
    foreach ($fallbacks as $key => $fallback) {
        if (!isset($vars[$key]) || trim((string) $vars[$key]) === '') {
            $vars[$key] = $fallback;
        }
    }
    return $vars;
}

/* ------------------------------------------------------------------------------------------------ CRM sync */

/**
 * Link / create the WhatsApp CRM contact of a business event and move its admission stage forward.
 * Returns a short summary or null when the event carries no person.
 */
function wa_evt_sync_crm(string $code, array $payload, ?array $contact): ?array
{
    if (!empty($payload['_test']) || !empty($payload['fanout']) || !function_exists('wa_contact_upsert')) {
        return null;
    }
    // the contacts part (wa_crm_on_business_event) re-derives these from the ERP rows itself
    if (function_exists('wa_crm_on_business_event') && in_array($code, wa_evt_crm_owned_codes(), true)) {
        return null;
    }
    $phone = wa_evt_phone_digits(is_scalar($payload['phone'] ?? null) ? (string) $payload['phone'] : null);
    if (!$phone) {
        return null;
    }
    $studentId = (int) ($payload['student_id'] ?? 0) ?: null;
    $admissionId = (int) ($payload['admission_id'] ?? 0) ?: null;
    $enquiryId = (int) ($payload['enquiry_id'] ?? 0) ?: null;
    $stageMap = wa_evt_stage_map();
    $isLeadEvent = isset($stageMap[$code]);
    if (!$studentId && !$admissionId && !$enquiryId && !$isLeadEvent) {
        return null; // e.g. an external event: the core already resolved the contact by phone
    }
    $existing = ($contact && ($contact['phone'] ?? null) === $phone) ? $contact : (function_exists('wa_contact_by_phone') ? wa_contact_by_phone($phone) : null);
    $typeRank = ['lead' => 1, 'applicant' => 2, 'student' => 3];
    $wantType = $studentId ? 'student' : ($admissionId ? 'applicant' : 'lead');
    $curType = (string) ($existing['contact_type'] ?? '');
    $data = [
        'phone' => $phone,
        'name' => $payload['student_name'] ?? $payload['name'] ?? null,
        'email' => $payload['email'] ?? null,
        'student_id' => $studentId,
        'admission_id' => $admissionId,
        'enquiry_id' => $enquiryId,
        'application_no' => $payload['application_no'] ?? null,
        'program_id' => (int) ($payload['program_id'] ?? 0) ?: null,
        'program_name' => $payload['program_name'] ?? null,
        'batch' => $payload['batch_name'] ?? null,
        'city' => $payload['city'] ?? null,
        'state' => $payload['state'] ?? null,
        'counsellor_id' => $isLeadEvent ? ((int) ($payload['counsellor_id'] ?? 0) ?: null) : null,
        'source' => $existing ? null : (in_array($payload['source'] ?? '', ['website', 'landing', 'apply', 'import', 'inbound', 'manual', 'sync'], true) ? $payload['source'] : 'sync'),
    ];
    if (!$existing || !isset($typeRank[$curType]) || $typeRank[$wantType] > $typeRank[$curType]) {
        $data['contact_type'] = $wantType;
    }
    if (isset($payload['admission_stage'])) {
        $data['admission_status'] = $payload['admission_stage'];
    }
    if (in_array($code, ['GIMT_PAYMENT_SUCCESS', 'GIMT_ADMISSION_FEE_RECEIVED'], true)) {
        $data['payment_status'] = 'paid';
    } elseif (in_array($code, ['GIMT_PAYMENT_FAILED', 'GIMT_PAYMENT_PENDING', 'GIMT_FEE_OVERDUE'], true)) {
        $data['payment_status'] = $code === 'GIMT_FEE_OVERDUE' ? 'overdue' : 'pending';
    }
    $data = array_filter($data, fn ($v) => $v !== null && $v !== '');
    // the student / applicant may share a parent's number: do not re-link an existing contact owned by another student
    if ($existing && $studentId && !empty($existing['student_id']) && (int) $existing['student_id'] !== $studentId) {
        unset($data['student_id'], $data['name'], $data['contact_type']);
    }
    $cid = wa_contact_upsert($data);
    if (!$cid) {
        return null;
    }
    $res = ['contact_id' => $cid, 'created' => !$existing];
    $target = $stageMap[$code] ?? ($studentId ? 'admission_confirmed' : null);
    if ($target && function_exists('wa_contact_set_stage')) {
        $cur = (string) ($existing['stage'] ?? 'new_enquiry');
        $fresh = !$existing;
        $forward = wa_evt_stage_rank($target) > wa_evt_stage_rank($cur) && $cur !== 'not_interested';
        $lost = $target === 'not_interested' && $cur !== 'admission_confirmed' && $cur !== 'not_interested';
        $reopen = $cur === 'not_interested' && in_array($target, ['new_enquiry', 'application_started', 'application_submitted', 'payment_pending', 'admission_confirmed'], true);
        if (($fresh && $target !== 'new_enquiry') || $forward || $lost || $reopen) {
            wa_contact_set_stage($cid, $target, ['source' => 'event', 'event' => $code]);
            $res['stage'] = ['from' => $existing ? $cur : null, 'to' => $target];
        }
    }
    return $res;
}

/* ------------------------------------------------------------------------------------------------ the listener */

/**
 * Core event-bus listener ('*'). $event is the wa_event_logs row (id, event_code, source, dedupe_key …).
 * Returns the action summary the core stores in wa_event_logs.actions (null = nothing to do for this code).
 */
function wa_evt_handle(array $event, array $payload, ?array $contact): ?array
{
    $code = (string) ($event['event_code'] ?? $event['code'] ?? '');
    if ($code === '' || str_starts_with($code, 'WA_')) {
        return null;
    }
    $logId = (int) ($event['id'] ?? $event['log_id'] ?? 0) ?: null;
    // a test only when fired by the Event Manager test (source "test"); a payload flag alone never bypasses the
    // active flag or the opt-in check (external payloads are not trusted)
    $isTest = ($event['source'] ?? '') === 'test';
    $summary = ['handler' => 'event_manager'];

    if (!$isTest) {
        try {
            if ($crm = wa_evt_sync_crm($code, $payload, $contact)) {
                $summary['crm'] = $crm;
                if (empty($contact['id']) || (int) $contact['id'] === (int) $crm['contact_id']) {
                    $contact = function_exists('wa_contact') ? (wa_contact((int) $crm['contact_id']) ?: $contact) : $contact;
                }
            }
        } catch (Throwable $e) {
            $summary['crm_error'] = mb_substr($e->getMessage(), 0, 190);
        }
    }

    $evt = wa_evt_by_code($code, $isTest);
    if (!$evt) {
        return isset($summary['crm']) ? $summary + ['status' => 'skipped', 'reason' => 'Not in the event catalogue'] : null;
    }
    $summary['event_id'] = (int) $evt['id'];
    try {
        // run counters are not an edit of the event: keep updated_at (its ON UPDATE would otherwise track the last run)
        db_exec('UPDATE wa_events SET last_executed_at = NOW(), execution_count = execution_count + 1, updated_at = updated_at WHERE id = ?', [(int) $evt['id']]);
    } catch (Throwable $e) {
        // counters are informative only
    }
    if (!empty($payload['fanout']) && !$isTest) {
        // one summary row of a bulk event nobody acted on (wa_evt_fanout): never a message
        return $summary + ['status' => 'skipped', 'reason' => ((int) $evt['is_active'] ? 'Bulk event summary' : 'Event is inactive')
            . ' — ' . (int) ($payload['recipients_count'] ?? 0) . ' recipient(s) logged once. Activate the event, then retry this row to send them.'];
    }
    if (!(int) $evt['is_active'] && !$isTest) {
        return $summary + ['status' => 'skipped', 'reason' => 'Event is inactive'];
    }
    if (empty($evt['template_id']) && !wa_evt_template_for($evt)) {
        return $summary + ['status' => 'skipped', 'reason' => 'No template linked'];
    }

    // recipient (a test always goes to the test phone = the payload phone resolved by the core)
    $rec = $isTest ? ['contact' => $contact, 'phone' => $contact['phone'] ?? null, 'error' => $contact ? null : 'Test phone is invalid', 'via' => 'test']
        : wa_evt_resolve_recipient($evt, $payload, $contact);
    if ($rec['error'] || !$rec['contact']) {
        return $summary + ['status' => 'skipped', 'reason' => $rec['error'] ?: 'No recipient'];
    }
    $to = $rec['contact'];
    $summary['recipient'] = ['type' => $rec['via'], 'contact_id' => (int) $to['id'], 'phone' => wa_evt_mask_phone($to['phone'] ?? $rec['phone']), 'name' => $to['name'] ?? null];

    [$ok, $failed] = wa_evt_conditions_match(wa_evt_json($evt['conditions'] ?? null), $payload, $to);
    if (!$ok) {
        return $summary + ['status' => 'skipped', 'reason' => 'Condition not met: ' . $failed];
    }

    $tpl = wa_evt_template_for($evt);
    if (!$tpl) {
        return $summary + ['status' => 'skipped', 'reason' => 'Template not found'];
    }
    $summary['template'] = ['id' => (int) $tpl['id'], 'name' => $tpl['name'], 'status' => $tpl['status'] ?? null];
    $vars = wa_evt_build_vars($evt, $tpl, $payload, $to);

    $at = null;
    $delay = (int) ($evt['delay_minutes'] ?? 0);
    if ($delay > 0 && !$isTest) {
        $at = date('Y-m-d H:i:s', time() + $delay * 60);
    }
    $business = (int) ($evt['respect_business_hours'] ?? 0) === 1 && !$isTest;
    if ($business && function_exists('wa_in_business_hours') && function_exists('wa_next_business_time')) {
        $when = new DateTimeImmutable($at ?? 'now');
        if (!wa_in_business_hours($when)) {
            $at = wa_next_business_time($when)->format('Y-m-d H:i:s');
        }
    }
    if (!function_exists('wa_send')) {
        return $summary + ['status' => 'failed', 'reason' => 'WhatsApp core is not loaded'];
    }
    $res = wa_send(
        [
            'contact_id' => (int) $to['id'],
            'type' => 'template',
            'template' => (int) $tpl['id'],
            'vars' => $vars,
            'sender_type' => 'event',
            'event_log_id' => $logId,
        ],
        array_filter([
            'at' => $at,
            'priority' => $isTest ? 2 : 4,
            'respect_business_hours' => $business,
            // a test may reach a staff number that never opted in, but never one that replied STOP (wa_evt_test refuses
            // those for marketing templates; without the skip the core applies its own opt-in rules)
            'skip_opt_in_check' => $isTest && !(function_exists('wa_contact_opted_out') && wa_contact_opted_out($to)) ?: null,
            'use_samples' => $isTest ?: null,
            'dedupe_key' => $logId ? 'evt:' . $logId . ':' . $evt['id'] : null,
        ], fn ($v) => $v !== null && $v !== false)
    );
    $summary['message_id'] = $res['message_id'] ?? null;
    $summary['send_status'] = $res['status'] ?? null;
    if (($res['code'] ?? '') === 'duplicate_recent') {
        // the same template + text already went to this contact minutes ago (e.g. a workflow welcome): message_id is that
        // earlier message, nothing new was queued
        $summary['duplicate_of'] = (int) ($res['duplicate_of'] ?? $res['message_id'] ?? 0) ?: null;
        $summary['note'] = $res['note'] ?? null;
    }
    if ($at) {
        $summary['scheduled_at'] = $at;
    }
    if (empty($res['ok'])) {
        return $summary + ['status' => 'failed', 'reason' => (string) ($res['error'] ?? $res['code'] ?? 'Send refused'), 'code' => $res['code'] ?? null];
    }
    return $summary + ['status' => 'queued'];
}

/* ------------------------------------------------------------------------------------------------ fan-out */

/** Most ids one bulk event may address (a fan-out job). */
if (!defined('WA_EVT_FANOUT_MAX')) {
    define('WA_EVT_FANOUT_MAX', 5000);
}

/** wa_event_fanouts exists (schema 50)? Cached per request. */
function wa_evt_fanout_table(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = db_table_exists('wa_event_fanouts');
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Dedupe key of one fan-out item: "{id}" in the pattern is replaced, otherwise ":<id>" is appended. */
function wa_evt_item_dedupe(string $pattern, int $id, int $jobId = 0): string
{
    if ($pattern === '') {
        return 'fo' . $jobId . ':' . $id;
    }
    return str_contains($pattern, '{id}') ? str_replace('{id}', (string) $id, $pattern) : $pattern . ':' . $id;
}

/** Normalise a code list (string or array) to unique upper-case GIMT-style codes. */
function wa_evt_codes($codes): array
{
    $out = [];
    foreach ((array) $codes as $c) {
        $c = strtoupper(trim((string) $c));
        if ($c !== '' && preg_match('/^[A-Z0-9_]{2,80}$/', $c)) {
            $out[$c] = true;
        }
    }
    return array_keys($out);
}

/**
 * Bulk event: one event per id (results / admit cards / timetable / notice / holiday / promotion, bulk assignments …).
 * The ERP request only records ONE job row (wa_event_fanouts); the worker tick expands it in budgeted batches
 * (wa_evt_process_fanouts), so publishing results for 3,000 students stays fast. Codes nobody acts on (event inactive,
 * no listener, no active workflow) are logged once as a summary row instead.
 *
 * @param string|string[] $codes
 * @param string $dedupe per-item dedupe pattern ("result:41" → "result:41:<id>", or "enq:{id}:u5:…")
 * @param array $opts source, id_key (payload key of each id; default student_id)
 */
function wa_evt_fanout($codes, array $common, array $ids, string $dedupe = '', array $opts = []): array
{
    $codes = wa_evt_codes($codes);
    $idKey = (string) ($opts['id_key'] ?? 'student_id');
    if (!preg_match('/^[a-z][a-z0-9_]{1,39}$/', $idKey)) {
        $idKey = 'student_id';
    }
    unset($opts['id_key']);
    $opts = array_intersect_key($opts, ['source' => 1]);
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($x) => $x > 0)));
    if (!$ids || !$codes || !function_exists('wa_event')) {
        return ['queued' => 0];
    }
    $truncated = count($ids) > WA_EVT_FANOUT_MAX;
    $ids = array_slice($ids, 0, WA_EVT_FANOUT_MAX);
    $live = array_values(array_filter($codes, 'wa_evt_has_consumers'));
    foreach (array_diff($codes, $live) as $code) {
        wa_evt_fanout_summary($code, $common, $ids, $idKey, $dedupe, $opts);
    }
    if (!$live) {
        return ['queued' => 0, 'aggregated' => true];
    }
    if (!wa_evt_fanout_table()) {
        // schema 50 extension missing: old synchronous behaviour, capped so the request stays bounded
        $fired = 0;
        foreach (array_slice($ids, 0, 500) as $id) {
            foreach ($live as $code) {
                wa_event($code, wa_evt_enrich($code, $common + [$idKey => $id]), $opts + ['dedupe_key' => $dedupe !== '' ? wa_evt_item_dedupe($dedupe, $id) : null]);
                $fired++;
            }
        }
        return ['fired' => $fired, 'sync' => true];
    }
    $jobId = db_insert('wa_event_fanouts', [
        'event_code' => $live[0],
        'codes' => implode(',', $live),
        'id_key' => $idKey,
        'common_payload' => wa_evt_encode(wa_evt_redact($common)),
        'ids' => json_encode($ids),
        'item_dedupe' => $dedupe !== '' ? mb_substr($dedupe, 0, 150) : null,
        'opts' => wa_evt_encode($opts),
        'total' => count($ids),
        'status' => 'pending',
        'error' => $truncated ? 'Only the first ' . WA_EVT_FANOUT_MAX . ' recipients were queued.' : null,
        // wa_user_id(): null for scanner jobs queued by the worker, also during a light tick inside a staff request
        'created_by' => function_exists('wa_user_id') ? wa_user_id() : (function_exists('user_id') ? (user_id() ?: null) : null),
    ]);
    return ['queued' => count($ids), 'job_id' => $jobId, 'codes' => $live];
}

/** Redact secrets from a payload (core helper when loaded). */
function wa_evt_redact(array $payload): array
{
    return function_exists('wa_event_redact') ? wa_event_redact($payload) : $payload;
}

/** One summary log row for a bulk event nobody acts on (no contact is created; the ids are kept for a later retry). */
function wa_evt_fanout_summary(string $code, array $common, array $ids, string $idKey, string $dedupe, array $opts): ?int
{
    $key = $dedupe !== '' ? (str_contains($dedupe, '{id}') ? str_replace('{id}', 'all', $dedupe) : $dedupe . ':all') : null;
    return wa_event($code, $common + [
        'fanout' => true, 'recipients_count' => count($ids), 'fanout_id_key' => $idKey, '_fanout_ids' => implode(',', $ids),
        '_fanout_dedupe' => $dedupe,
    ], $opts + ['dedupe_key' => $key, 'create_contact' => false]);
}

/**
 * Worker: expand pending fan-out jobs within $budget seconds. A job is claimed with a 2-minute lease, processed from
 * its cursor in batches (progress saved every 20 ids) and released as pending when the budget runs out, so the next
 * tick resumes it. Each item is de-duplicated (dedupe key per id), so an interrupted batch never sends twice.
 */
function wa_evt_process_fanouts(float $budget = 5.0, ?int $onlyId = null): array
{
    $t0 = microtime(true);
    $stats = ['jobs' => 0, 'items' => 0, 'fired' => 0, 'duplicates' => 0, 'completed' => 0];
    if (!wa_evt_fanout_table() || !function_exists('wa_event')) {
        return $stats;
    }
    $claimSql = "(status = 'pending' OR (status = 'running' AND (locked_until IS NULL OR locked_until < NOW())))";
    $seen = [];
    while (microtime(true) - $t0 < $budget) {
        $args = [];
        $sql = "SELECT * FROM wa_event_fanouts WHERE $claimSql";
        if ($onlyId) {
            $sql .= ' AND id = ?';
            $args[] = $onlyId;
        }
        if ($seen) {
            $sql .= ' AND id NOT IN (' . implode(',', array_map('intval', array_keys($seen))) . ')';
        }
        $job = db_row($sql . ' ORDER BY id LIMIT 1', $args);
        if (!$job) {
            break;
        }
        $seen[(int) $job['id']] = true;
        // attempts counts interrupted runs only (a running job whose lease expired)
        $claimed = db_exec(
            "UPDATE wa_event_fanouts SET attempts = attempts + IF(status = 'running', 1, 0), status = 'running',
                    locked_until = NOW() + INTERVAL 2 MINUTE, started_at = COALESCE(started_at, NOW())
              WHERE id = ? AND $claimSql",
            [(int) $job['id']]
        );
        if ($claimed !== 1) {
            continue;
        }
        if ((int) $job['attempts'] >= 3 && $job['status'] === 'running') {
            db_update('wa_event_fanouts', ['status' => 'failed', 'locked_until' => null, 'finished_at' => date('Y-m-d H:i:s'),
                'error' => 'Stopped after repeated interruptions at ' . (int) $job['processed'] . ' of ' . (int) $job['total'] . '.'], 'id = ?', [(int) $job['id']]);
            continue;
        }
        $stats['jobs']++;
        try {
            $r = wa_evt_run_fanout_job($job, max(0.5, $budget - (microtime(true) - $t0)));
        } catch (Throwable $e) {
            error_log('[GIMT] WhatsApp fan-out job #' . $job['id'] . ' failed: ' . $e->getMessage());
            db_update('wa_event_fanouts', ['status' => 'pending', 'locked_until' => null, 'error' => mb_substr($e->getMessage(), 0, 500)], 'id = ?', [(int) $job['id']]);
            continue;
        }
        foreach (['items', 'fired', 'duplicates'] as $k) {
            $stats[$k] += $r[$k];
        }
        $stats['completed'] += $r['done'] ? 1 : 0;
    }
    return $stats;
}

/** Process one claimed job from its cursor. Returns [items, fired, duplicates, done]. */
function wa_evt_run_fanout_job(array $job, float $budget): array
{
    $t0 = microtime(true);
    $id = (int) $job['id'];
    $ids = array_values(array_map('intval', (array) (json_decode((string) $job['ids'], true) ?: [])));
    $codes = wa_evt_codes(explode(',', (string) $job['codes']));
    $common = wa_evt_json($job['common_payload']);
    $opts = array_intersect_key(wa_evt_json($job['opts']), ['source' => 1]) + ['source' => 'admin'];
    $idKey = (string) $job['id_key'] ?: 'student_id';
    $pattern = (string) ($job['item_dedupe'] ?? '');
    $pos = (int) $job['processed'];
    $n = count($ids);
    $out = ['items' => 0, 'fired' => 0, 'duplicates' => 0, 'done' => false];
    // the event may have been switched off since the job was queued: log one summary per idle code and drop it
    if ($pos === 0) {
        $idle = array_values(array_filter($codes, fn ($c) => !wa_evt_has_consumers($c)));
        foreach ($idle as $c) {
            wa_evt_fanout_summary($c, $common, $ids, $idKey, $pattern, $opts);
        }
        $codes = array_values(array_diff($codes, $idle));
    }
    // progress is only written while the job is still ours (status running): a cancel from the Event Manager wins
    $save = function (bool $final) use ($id, &$pos, &$out, $n, $job): void {
        $data = ['processed' => $pos, 'fired' => (int) $job['fired'] + $out['fired'], 'duplicates' => (int) $job['duplicates'] + $out['duplicates']];
        if ($final) {
            $done = $pos >= $n;
            $data += ['status' => $done ? 'done' : 'pending', 'locked_until' => null] + ($done ? ['finished_at' => date('Y-m-d H:i:s')] : []);
        } else {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + 120);
        }
        db_update('wa_event_fanouts', $data, "id = ? AND status = 'running'", [$id]);
    };
    $batch = 0;
    while ($pos < $n && $codes) {
        if (microtime(true) - $t0 > $budget) {
            break;
        }
        if ($batch > 0 && $batch % 20 === 0 && db_value('SELECT status FROM wa_event_fanouts WHERE id = ?', [$id]) !== 'running') {
            return $out; // cancelled meanwhile
        }
        $itemId = $ids[$pos];
        $key = wa_evt_item_dedupe($pattern, $itemId, $id);
        foreach ($codes as $code) {
            if (db_value('SELECT 1 FROM wa_event_logs WHERE dedupe_key = ?', [mb_substr($code . ':' . $key, 0, 190)])) {
                $out['duplicates']++;
                continue;
            }
            $logId = wa_event($code, wa_evt_enrich($code, $common + [$idKey => $itemId]), $opts + ['dedupe_key' => $key]);
            $logId ? $out['fired']++ : $out['duplicates']++;
        }
        $pos++;
        $out['items']++;
        if (++$batch % 20 === 0) {
            $save(false);
        }
    }
    if (!$codes) {
        $pos = $n;
    }
    $save(true);
    $out['done'] = $pos >= $n;
    return $out;
}

/** Recent fan-out jobs for the Event Manager (open ones + those finished in the last 24 hours). */
function wa_evt_fanout_jobs(int $limit = 8): array
{
    if (!wa_evt_fanout_table()) {
        return [];
    }
    $rows = db_all(
        "SELECT f.id, f.event_code, f.codes, f.id_key, f.total, f.processed, f.fired, f.duplicates, f.status, f.error, f.created_at,
                f.started_at, f.finished_at, e.id AS event_id, e.name AS event_name, u.name AS created_by_name
           FROM wa_event_fanouts f
           LEFT JOIN wa_events e ON e.code = f.event_code
           LEFT JOIN users u ON u.id = f.created_by
          WHERE f.status IN ('pending', 'running') OR f.created_at >= NOW() - INTERVAL 1 DAY
          ORDER BY (f.status IN ('pending', 'running')) DESC, f.id DESC
          LIMIT " . max(1, min(50, $limit))
    );
    return array_map(function ($r) {
        foreach (['id', 'event_id', 'total', 'processed', 'fired', 'duplicates'] as $k) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
        $r['codes'] = array_values(array_filter(explode(',', (string) $r['codes'])));
        return $r;
    }, $rows);
}

/* ------------------------------------------------------------------------------------------------ scheduled events */

function wa_evt_schedule(array $evt): array
{
    $cfg = wa_evt_json($evt['schedule_config'] ?? null);
    $defaults = [
        'GIMT_FEE_INSTALMENT_DUE' => ['days' => [3, 1]],
        'GIMT_FEE_OVERDUE' => ['days' => [1, 7, 15]],
        'GIMT_ADMISSION_DEADLINE' => ['days' => [7, 3, 1]],
        'GIMT_APPLICATION_INCOMPLETE' => ['days' => [1, 3, 7]],
        'GIMT_PAYMENT_PENDING' => ['minutes' => 60],
        'GIMT_CLASS_REMINDER' => ['time' => '18:00'],
        'GIMT_EXAM_REMINDER' => ['time' => '17:00'],
        'GIMT_EVENT_REMINDER' => ['time' => '17:00'],
        'GIMT_EXAM_COMPLETED' => ['time' => '10:00'],
        'GIMT_SUPPORT_FOLLOWUP' => ['days' => [2], 'time' => '10:00'],
    ][$evt['code']] ?? [];
    $cfg += $defaults + ['time' => '09:00'];
    $cfg['days'] = array_values(array_filter(array_map('intval', (array) ($cfg['days'] ?? [])), fn ($d) => $d >= 0 && $d <= 90));
    return $cfg;
}

/** Codes generated by the daily scanner → scanner function. */
function wa_evt_scanners(): array
{
    return [
        'GIMT_FEE_INSTALMENT_DUE' => 'wa_evt_scan_fee_due',
        'GIMT_FEE_OVERDUE' => 'wa_evt_scan_fee_overdue',
        'GIMT_CLASS_REMINDER' => 'wa_evt_scan_class_reminder',
        'GIMT_EXAM_REMINDER' => 'wa_evt_scan_exam_reminder',
        'GIMT_ADMISSION_DEADLINE' => 'wa_evt_scan_admission_deadline',
        'GIMT_APPLICATION_INCOMPLETE' => 'wa_evt_scan_incomplete_applications',
        'GIMT_PAYMENT_PENDING' => 'wa_evt_scan_payment_pending',
        'GIMT_FOLLOWUP_PENDING' => 'wa_evt_scan_followups',
        'GIMT_EVENT_REMINDER' => 'wa_evt_scan_event_reminder',
        'GIMT_EXAM_COMPLETED' => 'wa_evt_scan_exam_completed',
        'GIMT_SUPPORT_FOLLOWUP' => 'wa_evt_scan_support_followups',
    ];
}

/**
 * Worker tick: every 10 minutes, run the scanner of each active time-based event once a day after its configured
 * time (payment-pending runs every tick). Each item fires wa_event() with a per-day dedupe key.
 */
function wa_evt_tick(float $budget = 5.0): array
{
    $start = microtime(true);
    $stats = [];
    // 1. bulk events queued by ERP requests (every run, most of the budget)
    try {
        $stats['fanouts'] = wa_evt_process_fanouts(max(0.5, $budget * 0.6));
    } catch (Throwable $e) {
        $stats['fanouts'] = ['error' => $e->getMessage()];
        error_log('[GIMT] WhatsApp fan-out processing: ' . $e->getMessage());
    }
    $left = $budget - (microtime(true) - $start);
    if ($left < 0.5) {
        return $stats + ['scan' => 'no time left'];
    }
    // 2. daily time-based scanners (at most every 10 minutes)
    try {
        $last = (string) db_value("SELECT value FROM settings WHERE `key` = 'wa_events_last_scan'");
        if ($last !== '' && strtotime($last) > time() - 600) {
            return $stats + ['scan' => 'ran recently'];
        }
        if (function_exists('save_setting')) {
            save_setting('wa_events_last_scan', date('Y-m-d H:i:s'), 'whatsapp');
        }
        // install where 09_wa_2_events ran before the template seeder (older seed order): link + activate the core events once the
        // template library exists (only once; afterwards the admin decides)
        if ((string) db_value("SELECT value FROM settings WHERE `key` = 'wa_events_autolinked'") !== '1') {
            [$linked, $activated] = wa_evt_link_templates(true);
            if (($linked > 0 || (int) db_value('SELECT COUNT(*) FROM wa_templates') > 0) && function_exists('save_setting')) {
                save_setting('wa_events_autolinked', '1', 'whatsapp');
            }
            $stats['autolink'] = ['linked' => $linked, 'activated' => $activated];
        }
        $stats += wa_evt_run_scanners(false, $left, microtime(true));
        if (wa_evt_fanout_table()) {
            db_exec("DELETE FROM wa_event_fanouts WHERE status IN ('done', 'failed', 'cancelled') AND created_at < NOW() - INTERVAL 30 DAY LIMIT 500");
        }
    } catch (Throwable $e) {
        $stats['error'] = $e->getMessage();
        error_log('[GIMT] WhatsApp event scanner: ' . $e->getMessage());
    }
    return $stats;
}

/** Run the daily scanners now ($force ignores the once-a-day marker and the send time; used by "Run scan now"). */
function wa_evt_run_scanners(bool $force = false, float $budget = 15.0, ?float $start = null, ?string $onlyCode = null): array
{
    $start = $start ?? microtime(true);
    $stats = ['scanned' => [], 'fired' => 0];
    $today = date('Y-m-d');
    foreach (wa_evt_scanners() as $code => $fn) {
        if ($onlyCode && $onlyCode !== $code) {
            continue;
        }
        if (microtime(true) - $start > $budget) {
            $stats['partial'] = true;
            break;
        }
        $evt = wa_evt_by_code($code, true);
        if (!$evt || !(int) $evt['is_active']) {
            continue;
        }
        $cfg = wa_evt_schedule($evt);
        $continuous = $code === 'GIMT_PAYMENT_PENDING';
        if (!$force && !$continuous) {
            if (!empty($evt['last_scan_at']) && substr((string) $evt['last_scan_at'], 0, 10) === $today) {
                continue;
            }
            if (date('H:i') < (string) $cfg['time']) {
                continue;
            }
        }
        $left = max(1.0, $budget - (microtime(true) - $start));
        $r = $fn($evt, $cfg, $left);
        $stats['scanned'][$code] = $r;
        $stats['fired'] += (int) ($r['fired'] ?? 0);
        if (empty($r['partial'])) {
            db_exec('UPDATE wa_events SET last_scan_at = NOW(), updated_at = updated_at WHERE id = ?', [(int) $evt['id']]);
        }
    }
    return $stats;
}

/** Fire one scheduled item (deduped). Returns true when a new log row was written. */
function wa_evt_fire_scheduled(string $code, array $payload, string $dedupe): bool
{
    if (!function_exists('wa_event')) {
        return false;
    }
    if (db_value('SELECT 1 FROM wa_event_logs WHERE dedupe_key = ?', [mb_substr($code . ':' . $dedupe, 0, 190)])) {
        return false;
    }
    $payload = wa_evt_enrich($code, $payload);
    return (bool) wa_event($code, $payload, ['dedupe_key' => $dedupe, 'source' => 'cron']);
}

function wa_evt_scan_fee_due(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $fired = 0;
    foreach ($cfg['days'] ?: [3] as $d) {
        $due = date('Y-m-d', strtotime("+$d days"));
        $rows = db_all(
            "SELECT fi.id, fi.installment_no, fi.amount - fi.paid_amount AS due_amount, fi.due_date, sf.id AS fee_id, sf.invoice_no,
                    sf.title, sf.student_id
               FROM fee_installments fi
               JOIN student_fees sf ON sf.id = fi.student_fee_id
               JOIN students s ON s.id = sf.student_id AND s.status = 'active'
              WHERE fi.due_date = ? AND fi.paid_amount < fi.amount AND sf.status NOT IN ('paid', 'cancelled', 'waived')
             UNION ALL
             SELECT 0, 1, sf.balance_amount, sf.due_date, sf.id, sf.invoice_no, sf.title, sf.student_id
               FROM student_fees sf
               JOIN students s ON s.id = sf.student_id AND s.status = 'active'
              WHERE sf.due_date = ? AND sf.balance_amount > 0 AND sf.status NOT IN ('paid', 'cancelled', 'waived')
                AND NOT EXISTS (SELECT 1 FROM fee_installments x WHERE x.student_fee_id = sf.id)
              LIMIT 2000",
            [$due, $due]
        );
        foreach ($rows as $r) {
            if (microtime(true) - $t0 > $budget) {
                return ['fired' => $fired, 'partial' => true];
            }
            $fired += (int) wa_evt_fire_scheduled('GIMT_FEE_INSTALMENT_DUE', [
                'student_id' => (int) $r['student_id'], 'invoice_no' => $r['invoice_no'], 'fee_title' => $r['title'],
                'installment_no' => (int) $r['installment_no'], 'amount' => round((float) $r['due_amount'], 2),
                'due_date' => date('d M Y', strtotime($r['due_date'])), 'days_left' => $d,
                'payment_link' => absolute_url('portal/student/fees'),
            ], ($r['id'] ? 'inst:' . $r['id'] : 'inv:' . $r['fee_id']) . ':' . $r['due_date'] . ':d' . $d);
        }
    }
    return ['fired' => $fired];
}

function wa_evt_scan_fee_overdue(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $fired = 0;
    foreach ($cfg['days'] ?: [1] as $d) {
        $due = date('Y-m-d', strtotime("-$d days"));
        $rows = db_all(
            "SELECT fi.id, fi.installment_no, fi.amount - fi.paid_amount AS due_amount, fi.due_date, sf.id AS fee_id, sf.invoice_no,
                    sf.title, sf.student_id
               FROM fee_installments fi
               JOIN student_fees sf ON sf.id = fi.student_fee_id
               JOIN students s ON s.id = sf.student_id AND s.status = 'active'
              WHERE fi.due_date = ? AND fi.paid_amount < fi.amount AND sf.status NOT IN ('paid', 'cancelled', 'waived')
             UNION ALL
             SELECT 0, 1, sf.balance_amount, sf.due_date, sf.id, sf.invoice_no, sf.title, sf.student_id
               FROM student_fees sf
               JOIN students s ON s.id = sf.student_id AND s.status = 'active'
              WHERE sf.due_date = ? AND sf.balance_amount > 0 AND sf.status NOT IN ('paid', 'cancelled', 'waived')
                AND NOT EXISTS (SELECT 1 FROM fee_installments x WHERE x.student_fee_id = sf.id)
              LIMIT 2000",
            [$due, $due]
        );
        foreach ($rows as $r) {
            if (microtime(true) - $t0 > $budget) {
                return ['fired' => $fired, 'partial' => true];
            }
            $fired += (int) wa_evt_fire_scheduled('GIMT_FEE_OVERDUE', [
                'student_id' => (int) $r['student_id'], 'invoice_no' => $r['invoice_no'], 'fee_title' => $r['title'],
                'installment_no' => (int) $r['installment_no'], 'amount' => round((float) $r['due_amount'], 2),
                'due_date' => date('d M Y', strtotime($r['due_date'])), 'days_overdue' => $d,
                'payment_link' => absolute_url('portal/student/fees'),
            ], ($r['id'] ? 'inst:' . $r['id'] : 'inv:' . $r['fee_id']) . ':' . $r['due_date'] . ':d' . $d);
        }
    }
    return ['fired' => $fired];
}

/** Next-day classes: one reminder per student with the first class and the number of classes (skips holidays/Sundays). */
function wa_evt_scan_class_reminder(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $date = date('Y-m-d', strtotime('+1 day'));
    $dow = (int) date('N', strtotime($date));
    if (db_value('SELECT 1 FROM holidays WHERE holiday_date <= ? AND COALESCE(end_date, holiday_date) >= ? LIMIT 1', [$date, $date])) {
        return ['fired' => 0, 'note' => 'holiday'];
    }
    $sessionId = function_exists('current_session_id') ? current_session_id() : null;
    $sections = db_all(
        "SELECT t.section_id, COUNT(*) AS classes, MIN(ts.start_time) AS first_time,
                SUBSTRING_INDEX(GROUP_CONCAT(sub.name ORDER BY ts.start_time SEPARATOR '||'), '||', 1) AS first_subject
           FROM timetables t
           JOIN time_slots ts ON ts.id = t.time_slot_id
           LEFT JOIN subjects sub ON sub.id = t.subject_id
          WHERE t.status = 'published' AND t.day_of_week = ? AND t.section_id IS NOT NULL
            AND (? IS NULL OR t.academic_session_id = ?)
          GROUP BY t.section_id",
        [$dow, $sessionId, $sessionId]
    );
    $fired = 0;
    foreach ($sections as $sec) {
        $students = db_column("SELECT id FROM students WHERE section_id = ? AND status = 'active'", [(int) $sec['section_id']]);
        foreach ($students as $sid) {
            if (microtime(true) - $t0 > $budget) {
                return ['fired' => $fired, 'partial' => true];
            }
            $fired += (int) wa_evt_fire_scheduled('GIMT_CLASS_REMINDER', [
                'student_id' => (int) $sid, 'class_date' => date('l, d M Y', strtotime($date)),
                'first_class_time' => date('h:i A', strtotime((string) $sec['first_time'])), 'first_subject' => (string) $sec['first_subject'],
                'class_time' => date('h:i A', strtotime((string) $sec['first_time'])), 'subject_name' => (string) $sec['first_subject'],
                'classes_count' => (int) $sec['classes'],
            ], 'st:' . $sid . ':' . $date);
        }
    }
    return ['fired' => $fired, 'sections' => count($sections)];
}

/** Exam tomorrow: one reminder per eligible student with the first paper of the day. */
function wa_evt_scan_exam_reminder(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $date = date('Y-m-d', strtotime('+1 day'));
    $papers = db_all(
        "SELECT es.exam_id, MIN(es.start_time) AS start_time,
                SUBSTRING_INDEX(GROUP_CONCAT(sub.name ORDER BY es.start_time SEPARATOR '||'), '||', 1) AS subject,
                e.name AS exam_name, e.program_id, e.semester_no
           FROM exam_schedules es
           JOIN exams e ON e.id = es.exam_id AND e.status NOT IN ('draft', 'cancelled')
           LEFT JOIN subjects sub ON sub.id = es.subject_id
          WHERE es.exam_date = ?
          GROUP BY es.exam_id, e.name, e.program_id, e.semester_no",
        [$date]
    );
    $fired = 0;
    foreach ($papers as $p) {
        $students = db_all('SELECT student_id, hall_ticket_no FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [(int) $p['exam_id']]);
        if (!$students && $p['program_id']) {
            $students = db_all("SELECT id AS student_id, NULL AS hall_ticket_no FROM students WHERE program_id = ? AND current_semester = ? AND status = 'active'", [(int) $p['program_id'], (int) $p['semester_no']]);
        }
        foreach ($students as $s) {
            if (microtime(true) - $t0 > $budget) {
                return ['fired' => $fired, 'partial' => true];
            }
            $fired += (int) wa_evt_fire_scheduled('GIMT_EXAM_REMINDER', [
                'student_id' => (int) $s['student_id'], 'exam_id' => (int) $p['exam_id'], 'exam_name' => $p['exam_name'],
                'exam_date' => date('d M Y', strtotime($date)), 'subject' => (string) $p['subject'], 'subject_name' => (string) $p['subject'],
                'exam_time' => $p['start_time'] ? date('h:i A', strtotime((string) $p['start_time'])) : '',
                'hall_ticket_no' => (string) ($s['hall_ticket_no'] ?? ''),
            ], 'exam:' . $p['exam_id'] . ':st:' . $s['student_id'] . ':' . $date);
        }
    }
    return ['fired' => $fired, 'exams' => count($papers)];
}

function wa_evt_deadline(): ?int
{
    foreach (['admissions_deadline', 'admission_last_date'] as $key) {
        $v = trim((string) setting($key, ''));
        if ($v === '' || !($ts = strtotime($v))) {
            continue;
        }
        if (!preg_match('/\d{1,2}:\d{2}/', $v)) {
            $ts = strtotime(date('Y-m-d 23:59:59', $ts));
        }
        return $ts > time() ? $ts : null;
    }
    return null;
}

/** Admission deadline countdown to early-stage leads (opted-in, active). */
function wa_evt_scan_admission_deadline(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $deadline = wa_evt_deadline();
    if (!$deadline) {
        return ['fired' => 0, 'note' => 'no upcoming deadline'];
    }
    $daysLeft = (int) floor((strtotime(date('Y-m-d', $deadline)) - strtotime(date('Y-m-d'))) / 86400);
    if (!in_array($daysLeft, $cfg['days'] ?: [7, 3, 1], true)) {
        return ['fired' => 0, 'days_left' => $daysLeft];
    }
    $contacts = db_all(
        "SELECT id, phone, name, program_name, application_no FROM wa_contacts
          WHERE status = 'active' AND opt_in = 1 AND contact_type IN ('lead', 'applicant')
            AND stage IN ('new_enquiry', 'contacted', 'interested', 'counselling_scheduled', 'application_started')
          ORDER BY id LIMIT 1000"
    );
    $fired = 0;
    foreach ($contacts as $c) {
        if (microtime(true) - $t0 > $budget) {
            return ['fired' => $fired, 'partial' => true];
        }
        $fired += (int) wa_evt_fire_scheduled('GIMT_ADMISSION_DEADLINE', [
            'phone' => $c['phone'], 'name' => $c['name'], 'program_name' => $c['program_name'], 'course_name' => $c['program_name'],
            'deadline' => date('d M Y', $deadline), 'last_date' => date('d M Y', $deadline), 'days_left' => $daysLeft, 'apply_link' => absolute_url('apply'),
            'application_number' => $c['application_no'] ?: 'not started yet',
        ], 'c:' . $c['id'] . ':' . date('Y-m-d', $deadline) . ':d' . $daysLeft);
    }
    return ['fired' => $fired, 'days_left' => $daysLeft];
}

/** Online applications started but not submitted (stage enquiry, declaration not accepted). */
function wa_evt_scan_incomplete_applications(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $fired = 0;
    foreach ($cfg['days'] ?: [1] as $d) {
        $day = date('Y-m-d', strtotime("-$d days"));
        $rows = db_all(
            "SELECT id FROM admissions WHERE stage = 'enquiry' AND COALESCE(declaration_accepted, 0) = 0
                AND created_at >= ? AND created_at < ? + INTERVAL 1 DAY LIMIT 500",
            [$day, $day]
        );
        foreach ($rows as $r) {
            if (microtime(true) - $t0 > $budget) {
                return ['fired' => $fired, 'partial' => true];
            }
            $fired += (int) wa_evt_fire_scheduled('GIMT_APPLICATION_INCOMPLETE', [
                'admission_id' => (int) $r['id'], 'days_since' => $d, 'apply_link' => absolute_url('apply'),
            ], 'adm:' . $r['id'] . ':d' . $d);
        }
    }
    return ['fired' => $fired];
}

/** Cashfree payment links created more than N minutes ago and still unpaid. */
function wa_evt_scan_payment_pending(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $minutes = max(10, (int) ($cfg['minutes'] ?? 60));
    $rows = db_all(
        "SELECT id, order_ref, student_id, student_fee_id, amount, link_url FROM gateway_orders
          WHERE status = 'created' AND created_at <= NOW() - INTERVAL ? MINUTE AND created_at >= NOW() - INTERVAL 3 DAY
          ORDER BY id LIMIT 300",
        [$minutes]
    );
    $fired = 0;
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > $budget) {
            return ['fired' => $fired, 'partial' => true];
        }
        $fired += (int) wa_evt_fire_scheduled('GIMT_PAYMENT_PENDING', [
            'student_id' => (int) $r['student_id'], 'order_ref' => $r['order_ref'], 'amount' => (float) $r['amount'],
            'payment_link' => $r['link_url'] ?: absolute_url('portal/student/fees'),
            'invoice_no' => $r['student_fee_id'] ? (string) db_value('SELECT invoice_no FROM student_fees WHERE id = ?', [(int) $r['student_fee_id']]) : '',
        ], 'order:' . $r['id']);
    }
    return ['fired' => $fired];
}

/** Counsellor digest: open follow-ups due today or overdue, one message per counsellor per day. */
function wa_evt_scan_followups(array $evt, array $cfg, float $budget): array
{
    $today = date('Y-m-d');
    $rows = db_all(
        "SELECT COALESCE(a.assigned_to, e.assigned_to) AS counsellor_id,
                SUM(f.next_followup_date = ?) AS due_today, SUM(f.next_followup_date < ?) AS overdue,
                SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(e.name, CONCAT(a.first_name, ' ', COALESCE(a.last_name, ''))) ORDER BY f.next_followup_date SEPARATOR ', '), ', ', 3) AS names
           FROM admission_followups f
           LEFT JOIN admissions a ON a.id = f.admission_id
           LEFT JOIN enquiries e ON e.id = f.enquiry_id
          WHERE f.completed_at IS NULL AND f.next_followup_date IS NOT NULL AND f.next_followup_date <= ?
            AND f.id = (SELECT MAX(f2.id) FROM admission_followups f2
                         WHERE (f2.enquiry_id <=> f.enquiry_id) AND (f2.admission_id <=> f.admission_id))
          GROUP BY COALESCE(a.assigned_to, e.assigned_to)
         HAVING counsellor_id IS NOT NULL",
        [$today, $today, $today]
    );
    $fired = 0;
    foreach ($rows as $r) {
        $total = (int) $r['due_today'] + (int) $r['overdue'];
        if ($total < 1) {
            continue;
        }
        $fired += (int) wa_evt_fire_scheduled('GIMT_FOLLOWUP_PENDING', [
            'counsellor_id' => (int) $r['counsellor_id'], 'due_today' => (int) $r['due_today'], 'overdue' => (int) $r['overdue'],
            'pending_count' => $total, 'top_names' => trim((string) $r['names']), 'date' => date('d M Y'),
            'followups_link' => absolute_url('admin/admissions'),
        ], 'user:' . $r['counsellor_id'] . ':' . $today);
    }
    return ['fired' => $fired];
}

/** Website events tomorrow: reminder to every registration with a phone (template event_reminder). */
function wa_evt_scan_event_reminder(array $evt, array $cfg, float $budget): array
{
    $t0 = microtime(true);
    $date = date('Y-m-d', strtotime('+1 day'));
    if (!db_table_exists('event_registrations')) {
        return ['fired' => 0];
    }
    // cancelled registrations get no reminder
    $rows = db_all(
        "SELECT r.id, r.name, r.phone, r.email, ev.id AS event_id, ev.title, ev.venue, ev.start_datetime, ev.slug
           FROM event_registrations r
           JOIN events ev ON ev.id = r.event_id AND ev.status = 'published'
          WHERE ev.start_datetime >= ? AND ev.start_datetime < ? + INTERVAL 1 DAY
            AND r.phone IS NOT NULL AND r.phone <> '' AND r.status <> 'cancelled'
          ORDER BY r.id
          LIMIT 1000",
        [$date, $date]
    );
    $fired = 0;
    foreach ($rows as $r) {
        if (microtime(true) - $t0 > $budget) {
            return ['fired' => $fired, 'partial' => true];
        }
        $fired += (int) wa_evt_fire_scheduled('GIMT_EVENT_REMINDER', [
            'event_registration_id' => (int) $r['id'], 'event_id' => (int) $r['event_id'],
            'phone' => $r['phone'], 'name' => $r['name'], 'email' => $r['email'], 'event_title' => $r['title'],
            'event_date' => date('d M Y, h:i A', strtotime($r['start_datetime'])), 'venue' => (string) $r['venue'],
            'event_link' => absolute_url('events/' . $r['slug']),
        ], 'reg:' . $r['id']);
    }
    return ['fired' => $fired];
}

/** Examination finished yesterday (last paper / end date): one message per candidate, expanded by the fan-out queue. */
function wa_evt_scan_exam_completed(array $evt, array $cfg, float $budget): array
{
    $day = date('Y-m-d', strtotime('-1 day'));
    $exams = db_all(
        "SELECT e.id, e.name, e.program_id, e.semester_no, e.result_date
           FROM exams e
          WHERE e.status NOT IN ('draft', 'cancelled')
            AND COALESCE((SELECT MAX(es.exam_date) FROM exam_schedules es WHERE es.exam_id = e.id), e.end_date) = ?
          LIMIT 50",
        [$day]
    );
    $queued = 0;
    foreach ($exams as $e) {
        $ids = db_column('SELECT student_id FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [(int) $e['id']]);
        if (!$ids && $e['program_id']) {
            $ids = db_column("SELECT id FROM students WHERE program_id = ? AND current_semester = ? AND status = 'active'", [(int) $e['program_id'], (int) $e['semester_no']]);
        }
        $r = wa_evt_fanout('GIMT_EXAM_COMPLETED', [
            'exam_id' => (int) $e['id'], 'exam_name' => $e['name'], 'completed_on' => date('d M Y', strtotime($day)),
            'result_date' => $e['result_date'] ? date('d M Y', strtotime($e['result_date'])) : '', 'portal_link' => absolute_url('portal/student'),
        ], $ids, 'examdone:' . $e['id'], ['source' => 'cron']);
        $queued += (int) ($r['queued'] ?? 0);
    }
    return ['fired' => $queued, 'exams' => count($exams)];
}

/** Support follow-up digest: open tickets with no update for N days, one reminder per assignee per day. */
function wa_evt_scan_support_followups(array $evt, array $cfg, float $budget): array
{
    $days = max(1, (int) (($cfg['days'] ?? [2])[0] ?? 2));
    $today = date('Y-m-d');
    $rows = db_all(
        "SELECT assigned_to AS counsellor_id, COUNT(*) AS pending_count, MIN(COALESCE(updated_at, created_at)) AS oldest,
                SUBSTRING_INDEX(GROUP_CONCAT(ticket_no ORDER BY COALESCE(updated_at, created_at) SEPARATOR ', '), ', ', 3) AS tickets
           FROM feedback
          WHERE status IN ('open', 'in_progress') AND assigned_to IS NOT NULL
            AND COALESCE(updated_at, created_at) < NOW() - INTERVAL ? DAY
          GROUP BY assigned_to",
        [$days]
    );
    $fired = 0;
    foreach ($rows as $r) {
        $fired += (int) wa_evt_fire_scheduled('GIMT_SUPPORT_FOLLOWUP', [
            'counsellor_id' => (int) $r['counsellor_id'], 'pending_count' => (int) $r['pending_count'], 'ticket_numbers' => (string) $r['tickets'],
            'days_waiting' => $days, 'oldest_since' => date('d M Y', strtotime((string) $r['oldest'])), 'date' => date('d M Y'),
            'tickets_link' => absolute_url('admin/feedback'),
        ], 'user:' . $r['counsellor_id'] . ':' . $today);
    }
    return ['fired' => $fired];
}

/* ------------------------------------------------------------------------------------------------ CRUD tap */

/**
 * Generic CRUD tap (app/crud.php after a successful save): website events published, holidays added, student status /
 * batch / section changes made in the CRUD screens. Explicit hooks cover everything else.
 */
function wa_evt_crud_saved(string $module, int $id, ?array $old, ?array $row): void
{
    if (!$row || !function_exists('wa_event')) {
        return;
    }
    $changed = fn (string $f) => $old !== null && (string) ($old[$f] ?? '') !== (string) ($row[$f] ?? '');
    $fire = function ($codes, array $payload, string $dedupe): void {
        foreach ((array) $codes as $code) {
            wa_event($code, wa_evt_enrich($code, $payload), ['dedupe_key' => $dedupe, 'source' => 'admin']);
        }
    };
    $stamp = date('YmdHi');
    switch ($module) {
        case 'enquiries':
            if ($old === null) {
                $fire('GIMT_ENQUIRY_CREATED_ADMIN', ['enquiry_id' => $id, 'source' => $row['source'] ?? 'walk_in', 'message' => (string) ($row['message'] ?? '')], 'enq:' . $id);
            } elseif ($changed('status')) {
                $fire('GIMT_ENQUIRY_STATUS_CHANGED', ['enquiry_id' => $id, 'old_status' => $old['status'] ?? '', 'new_status' => $row['status'] ?? ''], 'enq:' . $id . ':' . ($row['status'] ?? '') . ':' . date('Y-m-d'));
            }
            if (!empty($row['assigned_to']) && ($old === null || $changed('assigned_to'))) {
                $fire(['GIMT_ENQUIRY_ASSIGNED', 'GIMT_LEAD_TRANSFERRED'], ['enquiry_id' => $id, 'counsellor_id' => (int) $row['assigned_to'], 'assigned_by' => (string) (db_value('SELECT name FROM users WHERE id = ?', [(int) user_id()]) ?: '')], 'enq:' . $id . ':u' . $row['assigned_to'] . ':' . $stamp);
            }
            break;
        case 'admissions':
            if ($old === null) {
                $fire(!empty($row['application_no']) ? ['GIMT_APPLICATION_CREATED', 'GIMT_APPLICATION_NUMBER_GENERATED'] : 'GIMT_APPLICATION_CREATED',
                    ['admission_id' => $id, 'source' => $row['source'] ?? 'admin'], 'adm:' . $id);
            }
            if (!empty($row['assigned_to']) && ($old === null || $changed('assigned_to'))) {
                $fire(['GIMT_APPLICATION_ASSIGNED', 'GIMT_LEAD_TRANSFERRED'], ['admission_id' => $id, 'counsellor_id' => (int) $row['assigned_to']], 'adm:' . $id . ':u' . $row['assigned_to'] . ':' . $stamp);
            }
            break;
        case 'feedback':
            $ticket = ['ticket_id' => $id, 'ticket_no' => $row['ticket_no'] ?? '', 'ticket_type' => $row['type'] ?? '', 'subject' => (string) ($row['subject'] ?? ''),
                'issue_summary' => mb_substr((string) ($row['subject'] ?? ''), 0, 120), 'category' => (string) ($row['category'] ?? ''),
                'student_id' => (int) ($row['student_id'] ?? 0) ?: null, 'name' => $row['name'] ?? null, 'phone' => $row['phone'] ?? null, 'email' => $row['email'] ?? null];
            $ticket = array_filter($ticket, fn ($v) => $v !== null && $v !== '');
            if (!empty($row['is_anonymous'])) {
                break;
            }
            if ($old === null) {
                $fire(($row['type'] ?? '') === 'feedback' ? ['GIMT_TICKET_CREATED', 'GIMT_FEEDBACK_RECEIVED'] : 'GIMT_TICKET_CREATED', $ticket + (!empty($row['rating']) ? ['rating' => (int) $row['rating']] : []), 'fb:' . $id);
            }
            if (!empty($row['assigned_to']) && ($old === null || $changed('assigned_to'))) {
                $who = db_row('SELECT name, phone FROM users WHERE id = ?', [(int) $row['assigned_to']]);
                $fire('GIMT_TICKET_ASSIGNED', $ticket + ['assignee_name' => $who['name'] ?? ''], 'fb:' . $id . ':u' . $row['assigned_to']);
                $fire('GIMT_TICKET_ASSIGNED_STAFF', array_diff_key($ticket, ['phone' => 1, 'student_id' => 1]) + ['counsellor_id' => (int) $row['assigned_to'], 'student_phone' => $row['phone'] ?? ''], 'fb:' . $id . ':u' . $row['assigned_to']);
            }
            if ($changed('status') && in_array($row['status'] ?? '', ['resolved', 'closed'], true)) {
                $fire(['GIMT_TICKET_RESOLVED', 'GIMT_FEEDBACK_REQUEST'], $ticket + ['ticket_status' => $row['status'], 'resolution' => (string) ($row['resolution'] ?? '')], 'fb:' . $id . ':' . $row['status'] . ':' . date('Y-m-d'));
            }
            break;
        case 'holidays':
            if ($old === null) {
                $last = !empty($row['end_date']) ? (string) $row['end_date'] : (string) $row['holiday_date'];
                $reopen = strtotime($last . ' +1 day');
                if ((int) date('N', $reopen) === 7) {
                    $reopen = strtotime('+1 day', $reopen);
                }
                $p = ['holiday_id' => $id, 'title' => $row['title'] ?? '', 'holiday_name' => $row['title'] ?? '', 'holiday_date' => date('d M Y', strtotime((string) $row['holiday_date'])),
                    'end_date' => !empty($row['end_date']) ? date('d M Y', strtotime((string) $row['end_date'])) : '', 'reopen_date' => date('d M Y', $reopen), 'holiday_type' => $row['type'] ?? ''];
                wa_evt_fanout('GIMT_HOLIDAY_ANNOUNCED', $p, db_column("SELECT id FROM students WHERE status = 'active'"), 'hol:' . $id);
            }
            break;
        case 'events':
            if (($row['status'] ?? '') === 'published' && ($old === null || ($old['status'] ?? '') !== 'published')) {
                $p = ['event_id' => $id, 'event_title' => $row['title'] ?? '', 'event_date' => !empty($row['start_datetime']) ? date('d M Y, h:i A', strtotime((string) $row['start_datetime'])) : '',
                    'venue' => (string) ($row['venue'] ?? ''), 'event_link' => absolute_url('events/' . ($row['slug'] ?? ''))];
                wa_evt_fanout('GIMT_EVENT_ANNOUNCED', $p, db_column("SELECT id FROM students WHERE status = 'active'"), 'ev:' . $id);
            }
            break;
        case 'students':
            if ($old === null) {
                $fire('GIMT_STUDENT_ENROLLED', ['student_id' => $id], 'st:' . $id);
                return;
            }
            if ($changed('status')) {
                $codes = function_exists('gimt_wa_student_status_codes') ? gimt_wa_student_status_codes((string) ($old['status'] ?? ''), (string) ($row['status'] ?? '')) : ['GIMT_STUDENT_STATUS_CHANGED'];
                $fire($codes, ['student_id' => $id, 'old_status' => $old['status'] ?? '', 'new_status' => $row['status'] ?? '', 'reason' => (string) ($row['status_reason'] ?? '')], 'st:' . $id . ':' . ($row['status'] ?? '') . ':' . $stamp);
            }
            if ($changed('batch_id') && !empty($row['batch_id'])) {
                $fire('GIMT_BATCH_ALLOCATED', ['student_id' => $id], 'st:' . $id . ':b' . $row['batch_id']);
            }
            if ($changed('section_id') && !empty($row['section_id'])) {
                $fire('GIMT_SECTION_ALLOCATED', ['student_id' => $id], 'st:' . $id . ':s' . $row['section_id']);
            }
            break;
        case 'exams':
            $live = !in_array((string) ($row['status'] ?? 'draft'), ['draft', 'cancelled'], true);
            $cohort = function () use ($id, $row): array {
                $ids = db_column('SELECT student_id FROM exam_students WHERE exam_id = ? AND is_eligible = 1', [$id]);
                return $ids ?: db_column("SELECT id FROM students WHERE program_id = ? AND current_semester = ? AND status = 'active'", [(int) ($row['program_id'] ?? 0), (int) ($row['semester_no'] ?? 0)]);
            };
            $p = ['exam_id' => $id, 'exam_name' => (string) ($row['name'] ?? ''), 'portal_link' => absolute_url('portal/student'),
                'start_date' => !empty($row['start_date']) ? date('d M Y', strtotime((string) $row['start_date'])) : '',
                'end_date' => !empty($row['end_date']) ? date('d M Y', strtotime((string) $row['end_date'])) : ''];
            if ($live && ($old === null || ($old['status'] ?? '') === 'draft')) {
                wa_evt_fanout('GIMT_EXAM_ANNOUNCED', $p + ['exam_date' => $p['start_date']], $cohort(), 'examann:' . $id, ['source' => 'admin']);
            } elseif ($live && $old !== null && !empty($old['start_date']) && ($changed('start_date') || $changed('end_date'))) {
                $later = strtotime((string) $row['start_date']) > strtotime((string) $old['start_date']);
                wa_evt_fanout('GIMT_EXAM_POSTPONED', $p + [
                    'old_start_date' => date('d M Y', strtotime((string) $old['start_date'])), 'exam_date' => $p['start_date'],
                    'change' => $later ? 'postponed' : 'rescheduled',
                ], $cohort(), 'examdt:' . $id . ':' . ($row['start_date'] ?? '') . ':' . ($row['end_date'] ?? ''), ['source' => 'admin']);
            }
            break;
        case 'programs':
            $watch = ['fee_per_year', 'fee_label', 'duration_years', 'duration_label', 'eligibility', 'intake_capacity', 'total_semesters'];
            $diff = $old !== null ? array_values(array_filter($watch, $changed)) : [];
            if ($diff && ($row['status'] ?? 'active') === 'active' && db_table_exists('wa_contacts')) {
                // opted-in leads / applicants interested in this programme (never everyone)
                $ids = db_column("SELECT id FROM wa_contacts WHERE program_id = ? AND status = 'active' AND opt_in = 1 AND contact_type IN ('lead', 'applicant') LIMIT " . WA_EVT_FANOUT_MAX, [$id]);
                wa_evt_fanout('GIMT_COURSE_UPDATED', [
                    'program_id' => $id, 'program_name' => (string) ($row['name'] ?? ''), 'course_name' => (string) ($row['name'] ?? ''),
                    'changed_fields' => implode(', ', array_map(fn ($f) => str_replace('_', ' ', $f), $diff)),
                    'course_duration' => (string) (($row['duration_label'] ?? '') ?: (($row['duration_years'] ?? '') !== '' ? $row['duration_years'] . ' years' : '')),
                    'fee_amount' => (string) (($row['fee_label'] ?? '') ?: (!empty($row['fee_per_year']) ? wa_evt_money($row['fee_per_year']) . ' per year' : '')),
                    'course_link' => absolute_url('programs/' . ($row['slug'] ?? '')),
                ], $ids, 'prog:' . $id . ':' . date('Ymd'), ['source' => 'admin', 'id_key' => 'contact_id']);
            }
            break;
    }
}

/* ------------------------------------------------------------------------------------------------ Event Manager */

/** Events with template, 30-day counts and messages (list screen). */
function wa_evt_list(): array
{
    $rows = db_all(
        "SELECT e.*, t.name AS template_name, t.display_name AS template_display, t.status AS template_status, t.category AS template_category
           FROM wa_events e LEFT JOIN wa_templates t ON t.id = e.template_id
          ORDER BY FIELD(e.category, 'enquiry', 'registration', 'admission', 'course', 'payment', 'exam', 'certificate', 'support', 'staff', 'custom'), e.name"
    );
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    $counts = [];
    foreach (db_all(
        "SELECT event_code, COUNT(*) AS fired, SUM(" . wa_evt_failed_sql() . ") AS failed, SUM(status = 'duplicate') AS duplicates
           FROM wa_event_logs WHERE created_at >= ? GROUP BY event_code",
        [$since]
    ) as $c) {
        $counts[$c['event_code']] = $c;
    }
    $msgs = [];
    foreach (db_all(
        "SELECT l.event_code, COUNT(m.id) AS messages
           FROM wa_messages m JOIN wa_event_logs l ON l.id = m.event_log_id
          WHERE m.created_at >= ? AND m.sender_type = 'event' GROUP BY l.event_code",
        [$since]
    ) as $m) {
        $msgs[$m['event_code']] = (int) $m['messages'];
    }
    $byName = [];
    foreach (db_all("SELECT id, name, display_name, status FROM wa_templates WHERE status <> 'archived'") as $t) {
        $byName[$t['name']] = $byName[$t['name']] ?? $t;
    }
    return array_map(function ($r) use ($counts, $msgs, $byName) {
        $r = wa_evt_present($r);
        if (!$r['template_id'] && isset($byName[wa_evt_template_name($r['code'])])) {
            $t = $byName[wa_evt_template_name($r['code'])];
            $r['suggested_template'] = ['id' => (int) $t['id'], 'name' => $t['name'], 'status' => $t['status']];
        }
        $c = $counts[$r['code']] ?? null;
        $r['fired_30d'] = (int) ($c['fired'] ?? 0);
        $r['failed_30d'] = (int) ($c['failed'] ?? 0);
        $r['messages_30d'] = $msgs[$r['code']] ?? 0;
        // active workflows started by the same code that send a template (duplicate-message warning in the list)
        if ($overlaps = wa_evt_workflow_overlaps((string) $r['code'], $r['template_name'] ?? ($r['suggested_template']['name'] ?? wa_evt_template_name((string) $r['code'])))) {
            $r['workflow_overlaps'] = $overlaps;
        }
        return $r;
    }, $rows);
}

/** Decode JSON columns + typed flags for the API. */
function wa_evt_present(array $r): array
{
    foreach (['conditions', 'variable_map', 'sample_payload', 'schedule_config'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = wa_evt_json($r[$k]);
        }
    }
    foreach (['id', 'template_id', 'delay_minutes', 'execution_count', 'created_by'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = $r[$k] === null ? null : (int) $r[$k];
        }
    }
    foreach (['is_active', 'is_system', 'respect_business_hours'] as $k) {
        if (array_key_exists($k, $r)) {
            $r[$k] = (bool) (int) $r[$k];
        }
    }
    $r['template_convention'] = wa_evt_template_name((string) ($r['code'] ?? ''));
    $r['is_scheduled'] = isset(wa_evt_scanners()[$r['code'] ?? '']);
    return $r;
}

/** Validate + normalise an editor payload. Returns [data, errors]. */
function wa_evt_clean(array $in, ?array $old): array
{
    $errors = [];
    $data = [];
    $isSystem = $old && (int) $old['is_system'] === 1;
    if (!$old || array_key_exists('name', $in)) {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'Name may not be longer than 150 characters.';
        }
        $data['name'] = $name;
    }
    if (!$old) {
        $code = strtoupper(trim((string) ($in['code'] ?? '')));
        if ($code === '' && !empty($data['name'])) {
            $code = 'GIMT_CUSTOM_' . strtoupper(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $data['name']), '_'));
        }
        $code = substr($code, 0, 80);
        if (!preg_match('/^[A-Z][A-Z0-9_]{2,79}$/', $code)) {
            $errors['code'] = 'Use capital letters, digits and underscores (e.g. GIMT_CUSTOM_OPEN_DAY).';
        } elseif (str_starts_with($code, 'WA_')) {
            $errors['code'] = 'Codes starting with WA_ are reserved for internal WhatsApp events.';
        } elseif (db_value('SELECT 1 FROM wa_events WHERE code = ?', [$code])) {
            $errors['code'] = 'An event with this code already exists.';
        }
        $data['code'] = $code;
        $data['is_system'] = 0;
    }
    if (array_key_exists('description', $in)) {
        $data['description'] = trim((string) $in['description']) ?: null;
    }
    if (!$isSystem) {
        if (!$old || array_key_exists('category', $in)) {
            $cat = (string) ($in['category'] ?? 'custom');
            if (!isset(wa_evt_categories()[$cat])) {
                $errors['category'] = 'Choose a category.';
            }
            $data['category'] = $cat;
        }
        if (!$old || array_key_exists('source', $in)) {
            $src = (string) ($in['source'] ?? 'api');
            if (!isset(wa_evt_sources()[$src])) {
                $errors['source'] = 'Choose a source.';
            } elseif ($src === 'cron') {
                $errors['source'] = 'Only built-in events are scheduled by GIMT. Fire a custom event from the API, the website or a workflow.';
            }
            $data['source'] = $src;
        }
    }
    if (array_key_exists('template_id', $in)) {
        $tid = (int) ($in['template_id'] ?? 0);
        if ($tid && !db_value("SELECT 1 FROM wa_templates WHERE id = ? AND status <> 'archived'", [$tid])) {
            $errors['template_id'] = 'The selected template does not exist.';
        }
        $data['template_id'] = $tid ?: null;
    }
    if (array_key_exists('recipient', $in)) {
        $rec = (string) ($in['recipient'] ?? 'contact');
        if (!isset(wa_evt_recipients()[$rec])) {
            $errors['recipient'] = 'Choose who receives the message.';
        }
        $data['recipient'] = $rec;
        $field = trim((string) ($in['recipient_field'] ?? ''));
        if ($rec === 'custom_field' && !preg_match('/^[A-Za-z0-9_.]{1,80}$/', $field)) {
            $errors['recipient_field'] = 'Enter the payload key that holds the phone number (e.g. parent_phone).';
        }
        $data['recipient_field'] = $rec === 'custom_field' ? $field : null;
    }
    if (array_key_exists('conditions', $in)) {
        $conds = [];
        foreach ((array) ($in['conditions'] ?? []) as $i => $c) {
            if (!is_array($c)) {
                continue;
            }
            $field = trim((string) ($c['field'] ?? ''));
            $op = (string) ($c['op'] ?? '=');
            if ($field === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_.]{1,100}$/', $field)) {
                $errors['conditions'] = 'Condition ' . ($i + 1) . ': the field may only contain letters, digits, "_" and ".".';
            }
            if (!isset(wa_evt_ops()[$op])) {
                $errors['conditions'] = 'Condition ' . ($i + 1) . ': unknown operator.';
            }
            $conds[] = ['field' => $field, 'op' => $op, 'value' => mb_substr(trim((string) (is_scalar($c['value'] ?? '') ? ($c['value'] ?? '') : '')), 0, 500)];
        }
        $data['conditions'] = wa_evt_encode($conds);
    }
    if (array_key_exists('variable_map', $in)) {
        $map = [];
        foreach ((array) ($in['variable_map'] ?? []) as $k => $v) {
            $k = trim((string) $k);
            if ($k === '' || !is_scalar($v) || trim((string) $v) === '') {
                continue;
            }
            if (!preg_match('/^[A-Za-z0-9_.]{1,80}$/', $k)) {
                $errors['variable_map'] = 'Variable "' . mb_substr($k, 0, 40) . '" is not a valid key.';
                continue;
            }
            [$source, $fallback] = wa_evt_map_parts((string) $v);
            if ($fallback !== null) {
                // one line of plain text: WhatsApp template parameters cannot hold line breaks or {{placeholders}}
                $fallback = trim(preg_replace('/\s+/u', ' ', $fallback));
                if (str_contains($fallback, '{{') || str_contains($fallback, '}}')) {
                    $errors['variable_map'] = 'The "If empty" text of ' . $k . ' cannot contain {{ }} placeholders.';
                    continue;
                }
                if (mb_strlen($fallback) > 200) {
                    $errors['variable_map'] = 'The "If empty" text of ' . $k . ' may not be longer than 200 characters.';
                    continue;
                }
            }
            if ($fallback === null || $fallback === '') {
                if ($source === '') {
                    continue; // Automatic without a fallback = no entry
                }
                $map[$k] = mb_substr($source, 0, 500);
            } else {
                $map[$k] = $source === '' ? 'default:' . $fallback : mb_substr($source, 0, 290) . '|default:' . $fallback;
            }
        }
        $data['variable_map'] = wa_evt_encode($map);
    }
    if (array_key_exists('delay_minutes', $in)) {
        $d = $in['delay_minutes'];
        if ($d !== null && $d !== '' && (!is_numeric($d) || (int) $d < 0 || (int) $d > 43200)) {
            $errors['delay_minutes'] = 'Delay must be between 0 and 43,200 minutes (30 days).';
        }
        $data['delay_minutes'] = max(0, (int) $d);
    }
    if (array_key_exists('respect_business_hours', $in)) {
        $data['respect_business_hours'] = !empty($in['respect_business_hours']) ? 1 : 0;
    }
    if (array_key_exists('sample_payload', $in)) {
        $sp = $in['sample_payload'];
        if (is_string($sp)) {
            $sp = trim($sp);
            $decoded = $sp === '' ? [] : json_decode($sp, true);
            if ($sp !== '' && !is_array($decoded)) {
                $errors['sample_payload'] = 'Sample payload must be a JSON object.';
                $decoded = [];
            }
            $sp = $decoded;
        }
        $data['sample_payload'] = wa_evt_encode(is_array($sp) ? $sp : []);
    }
    if (array_key_exists('schedule_config', $in)) {
        $sc = (array) ($in['schedule_config'] ?? []);
        $clean = [];
        if (isset($sc['days'])) {
            $days = is_array($sc['days']) ? $sc['days'] : explode(',', (string) $sc['days']);
            $clean['days'] = array_values(array_unique(array_filter(array_map('intval', $days), fn ($x) => $x >= 0 && $x <= 90)));
        }
        if (!empty($sc['time'])) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $sc['time'])) {
                $errors['schedule_config'] = 'Send time must be HH:MM (24-hour).';
            }
            $clean['time'] = (string) $sc['time'];
        }
        if (isset($sc['minutes']) && $sc['minutes'] !== '') {
            $clean['minutes'] = max(10, min(4320, (int) $sc['minutes']));
        }
        $data['schedule_config'] = wa_evt_encode($clean);
    }
    return [$data, $errors];
}

/** Per-event stats: 30-day daily trend, log statuses and message statuses. */
function wa_evt_stats(array $evt, int $days = 30): array
{
    $days = max(7, min(90, $days));
    $since = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
    $daily = db_pairs(
        'SELECT DATE(created_at) AS d, COUNT(*) FROM wa_event_logs WHERE event_code = ? AND created_at >= ? GROUP BY DATE(created_at)',
        [$evt['code'], $since]
    );
    $labels = [];
    $series = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($since . " +$i days"));
        $labels[] = date('d M', strtotime($d));
        $series[] = (int) ($daily[$d] ?? 0);
    }
    $statuses = db_pairs(
        'SELECT status, COUNT(*) FROM wa_event_logs WHERE event_code = ? AND created_at >= ? GROUP BY status',
        [$evt['code'], $since]
    );
    $messages = db_pairs(
        "SELECT m.status, COUNT(*) FROM wa_messages m JOIN wa_event_logs l ON l.id = m.event_log_id
          WHERE l.event_code = ? AND l.created_at >= ? GROUP BY m.status",
        [$evt['code'], $since]
    );
    return [
        'days' => $days,
        'trend' => ['labels' => $labels, 'fired' => $series],
        'statuses' => array_map('intval', $statuses),
        'messages' => array_map('intval', $messages),
        'total' => array_sum($series),
    ];
}

/** SQL condition: a log whose dispatch failed (listener error) or whose message the Event Manager could not queue. */
function wa_evt_failed_sql(string $alias = ''): string
{
    $a = $alias !== '' ? $alias . '.' : '';
    return "({$a}status = 'failed' OR ({$a}actions LIKE '%\"handler\":\"event_manager\"%' AND {$a}actions LIKE '%\"status\":\"failed\"%'))";
}

/** KPI row of the Event Manager. */
function wa_evt_summary(): array
{
    $today = date('Y-m-d');
    $week = date('Y-m-d H:i:s', strtotime('-7 days'));
    $ev = db_row("SELECT COUNT(*) AS total, SUM(is_active = 1) AS active, SUM(is_system = 0) AS custom,
                         SUM(template_id IS NULL) AS no_template FROM wa_events") ?: [];
    $logs = db_row(
        // duplicates: the same event fired twice (dedupe key) or a message the repeat guard did not send again
        "SELECT SUM(created_at >= ?) AS today, SUM(" . wa_evt_failed_sql() . ") AS failed,
                SUM(status = 'duplicate' OR actions LIKE '%\"send_status\":\"duplicate_recent\"%') AS duplicates,
                SUM(status = 'skipped' OR actions LIKE '%\"status\":\"skipped\"%') AS skipped, COUNT(*) AS week
           FROM wa_event_logs WHERE created_at >= ?",
        [$today, $week]
    ) ?: [];
    $msgs = (int) db_value("SELECT COUNT(*) FROM wa_messages WHERE sender_type = 'event' AND created_at >= ?", [$week]);
    return [
        'events' => (int) ($ev['total'] ?? 0),
        'active' => (int) ($ev['active'] ?? 0),
        'custom' => (int) ($ev['custom'] ?? 0),
        'no_template' => (int) ($ev['no_template'] ?? 0),
        'fired_today' => (int) ($logs['today'] ?? 0),
        'fired_7d' => (int) ($logs['week'] ?? 0),
        'failed_7d' => (int) ($logs['failed'] ?? 0),
        'duplicates_7d' => (int) ($logs['duplicates'] ?? 0),
        'skipped_7d' => (int) ($logs['skipped'] ?? 0),
        'messages_7d' => $msgs,
        'last_scan' => db_value("SELECT value FROM settings WHERE `key` = 'wa_events_last_scan'") ?: null,
    ];
}

/** Link every event without a template to the template named by convention. Returns [linked, activated]. */
function wa_evt_link_templates(bool $activateCore = false): array
{
    $linked = 0;
    $activated = 0;
    foreach (db_all('SELECT id, code, is_active, updated_by FROM wa_events WHERE template_id IS NULL') as $e) {
        $t = db_row(
            "SELECT id, status FROM wa_templates WHERE name = ? AND status <> 'archived' ORDER BY (status = 'approved') DESC, id LIMIT 1",
            [wa_evt_template_name($e['code'])]
        );
        if (!$t) {
            continue;
        }
        db_exec('UPDATE wa_events SET template_id = ? WHERE id = ?', [(int) $t['id'], (int) $e['id']]);
        $linked++;
        // only events an admin never edited are switched on automatically (an admin's "off" is respected)
        if ($activateCore && !(int) $e['is_active'] && empty($e['updated_by']) && in_array($e['code'], wa_evt_core_codes(), true) && wa_evt_linkable_status($t['status'])) {
            db_exec('UPDATE wa_events SET is_active = 1 WHERE id = ?', [(int) $e['id']]);
            $activated++;
        }
    }
    return [$linked, $activated];
}

/**
 * Core transactional events switched on by default once their template exists. The starter templates of the other
 * brief events (09_wa_1) are linked but left off: several fire together with one of these (offer letter + admission
 * approved, enrolled + admission confirmed …), staff alerts need Users › phone, and website-JavaScript events
 * (brochure download …) are not posted by any GIMT page yet.
 */
function wa_evt_core_codes(): array
{
    return [
        'GIMT_ENQUIRY_RECEIVED', 'GIMT_CALLBACK_REQUEST', 'GIMT_COUNSELLING_REQUEST', 'GIMT_COURSE_ENQUIRY', 'GIMT_LANDING_LEAD',
        'GIMT_CHATBOT_LEAD', 'GIMT_APPLICATION_SUBMITTED', 'GIMT_DOCUMENTS_PENDING', 'GIMT_DOCUMENT_REJECTED',
        'GIMT_COUNSELLING_SCHEDULED', 'GIMT_ADMISSION_APPROVED', 'GIMT_ADMISSION_CONFIRMED', 'GIMT_ADMISSION_REJECTED',
        'GIMT_PAYMENT_SUCCESS', 'GIMT_PAYMENT_FAILED', 'GIMT_FEE_INSTALMENT_DUE', 'GIMT_FEE_OVERDUE', 'GIMT_REFUND_APPROVED',
        'GIMT_REFUND_PROCESSED', 'GIMT_ADMIT_CARD_GENERATED', 'GIMT_EXAM_REMINDER', 'GIMT_RESULT_PUBLISHED', 'GIMT_CERTIFICATE_ISSUED',
        'GIMT_TICKET_CREATED', 'GIMT_TICKET_RESOLVED',
    ];
}

/* ------------------------------------------------------------------------------------------------ workflow overlap */

/** Active workflows started by an event (trigger_type event), grouped by upper-case code. Read-only; cached per request. */
function wa_evt_active_event_workflows(bool $fresh = false): array
{
    static $byCode = null;
    if ($byCode === null || $fresh) {
        $byCode = [];
        try {
            foreach (db_all("SELECT id, name, trigger_event, definition FROM wa_workflows
                              WHERE status = 'active' AND trigger_type = 'event' AND trigger_event IS NOT NULL AND trigger_event <> '' ORDER BY id") as $w) {
                $byCode[strtoupper((string) $w['trigger_event'])][] = $w;
            }
        } catch (Throwable $e) {
            $byCode = [];
        }
    }
    return $byCode;
}

/**
 * Active automation workflows started by this event code that send a WhatsApp template (read-only check of
 * wa_workflows and its template nodes). The Event Manager warns — it never blocks — when the event itself also sends a
 * message, because the contact then gets both (e.g. the Event Manager's enquiry_received and the welcome step of the
 * "New Admission Enquiry" workflow for the same website enquiry).
 * Returns [['id', 'name', 'templates' => template names in node order, 'same_template' => bool]].
 */
function wa_evt_workflow_overlaps(string $code, ?string $eventTemplate = null, bool $fresh = false): array
{
    $code = strtoupper(trim($code));
    $workflows = $code !== '' ? (wa_evt_active_event_workflows($fresh)[$code] ?? []) : [];
    $out = [];
    foreach ($workflows as $w) {
        $names = [];
        foreach ((array) (wa_evt_json($w['definition'] ?? null)['nodes'] ?? []) as $node) {
            if (!is_array($node) || ($node['type'] ?? '') !== 'template') {
                continue;
            }
            $ref = trim((string) (is_array($node['data'] ?? null) ? ($node['data']['template'] ?? '') : ''));
            if ($ref === '') {
                continue;
            }
            if (ctype_digit($ref)) {
                $ref = (string) (db_value('SELECT name FROM wa_templates WHERE id = ?', [(int) $ref]) ?: $ref);
            }
            $names[$ref] = true;
        }
        if (!$names) {
            continue;
        }
        $names = array_keys($names);
        $out[] = [
            'id' => (int) $w['id'],
            'name' => (string) $w['name'],
            'templates' => $names,
            'same_template' => $eventTemplate !== null && $eventTemplate !== '' && in_array($eventTemplate, $names, true),
        ];
    }
    return $out;
}

/** One-sentence warning for an event that a workflow also answers (null when there is no overlap). */
function wa_evt_overlap_warning(array $evt, ?array $overlaps = null): ?string
{
    if ($overlaps === null) {
        $tpl = wa_evt_template_for($evt);
        $overlaps = wa_evt_workflow_overlaps((string) $evt['code'], $tpl['name'] ?? null);
    }
    if (!$overlaps) {
        return null;
    }
    $names = array_map(fn ($o) => '"' . $o['name'] . '"', $overlaps);
    $one = count($overlaps) === 1;
    $msg = ($one ? 'The active workflow ' : 'The active workflows ') . implode(', ', $names) . ($one ? ' also sends' : ' also send')
        . ' WhatsApp messages when ' . $evt['code'] . ' fires, so contacts get this event\'s message as well.';
    $same = array_values(array_filter($overlaps, fn ($o) => $o['same_template']));
    if ($same) {
        $msg .= ' "' . $same[0]['name'] . '" sends the same template as this event — keep only one of the two (deactivate this event or remove that step from the workflow).';
    }
    return $msg;
}

/** Log row for the API (payload + actions decoded, contact, linked messages). */
function wa_evt_log_detail(int $id): ?array
{
    $l = db_row(
        "SELECT l.*, c.name AS contact_name, c.phone AS contact_phone, e.id AS event_id, e.name AS event_name
           FROM wa_event_logs l
           LEFT JOIN wa_contacts c ON c.id = l.contact_id
           LEFT JOIN wa_events e ON e.code = l.event_code
          WHERE l.id = ?",
        [$id]
    );
    if (!$l) {
        return null;
    }
    $l['payload'] = wa_evt_json($l['payload']);
    unset($l['payload']['_fanout_ids'], $l['payload']['_fanout_dedupe']); // recipient list of a bulk summary (kept in the DB for retry)
    $l['actions'] = wa_evt_json($l['actions']);
    $l['messages'] = db_all(
        'SELECT id, contact_id, type, template_name, status, error, body, created_at, sent_at, delivered_at, read_at
           FROM wa_messages WHERE event_log_id = ? ORDER BY id',
        [$id]
    );
    // not sent again by the repeat guard: show the earlier message that already reached the contact
    $l['duplicate_message'] = null;
    if ($dupId = wa_evt_duplicate_of($l['actions'])) {
        $l['duplicate_message'] = db_row(
            'SELECT id, contact_id, conversation_id, type, template_name, sender_type, status, error, body, created_at, sent_at, delivered_at, read_at
               FROM wa_messages WHERE id = ?',
            [$dupId]
        );
    }
    return $l;
}

/**
 * Earlier message id when the Event Manager's send was suppressed by the same-template repeat guard of wa_send()
 * (send_status "duplicate_recent"), else null. $actions = the decoded wa_event_logs.actions (one summary or a list).
 */
function wa_evt_duplicate_of($actions): ?int
{
    $actions = is_array($actions) ? $actions : wa_evt_json($actions);
    $list = isset($actions['handler']) || isset($actions['status']) ? [$actions] : array_values(array_filter($actions, 'is_array'));
    foreach ($list as $a) {
        if ((($a['handler'] ?? '') === 'event_manager' || isset($a['event_id'])) && ($a['send_status'] ?? '') === 'duplicate_recent') {
            return (int) ($a['duplicate_of'] ?? $a['message_id'] ?? 0) ?: null;
        }
    }
    return null;
}

/**
 * Re-queue the recipients of a bulk-event summary row (logged once while nothing acted on the event).
 * Returns the fan-out result, or null when the event still has no consumer.
 */
function wa_evt_retry_fanout(array $log): ?array
{
    $payload = wa_evt_json($log['payload']);
    $code = (string) $log['event_code'];
    $ids = array_values(array_filter(array_map('intval', explode(',', (string) ($payload['_fanout_ids'] ?? '')))));
    if (!$ids || !wa_evt_has_consumers($code)) {
        return null;
    }
    $idKey = (string) ($payload['fanout_id_key'] ?? 'student_id');
    $dedupe = (string) ($payload['_fanout_dedupe'] ?? '');
    $common = array_diff_key($payload, array_flip(['fanout', 'recipients_count', 'fanout_id_key', '_fanout_ids', '_fanout_dedupe', 'student_ids']));
    $res = wa_evt_fanout($code, $common, $ids, $dedupe, ['source' => $log['source'] ?: 'admin', 'id_key' => $idKey]);
    if (!empty($res['job_id']) || !empty($res['fired'])) {
        db_exec("UPDATE wa_event_logs SET error = TRIM(CONCAT(COALESCE(error, ''), ' [re-queued for ', ?, ' recipient(s)]')) WHERE id = ?", [count($ids), (int) $log['id']]);
        return $res;
    }
    return null;
}

/** Re-dispatch a logged event (new log row; the original keeps its status). Returns the new log id or null. */
function wa_evt_retry(array $log): ?int
{
    if (!function_exists('wa_event')) {
        return null;
    }
    $payload = wa_evt_json($log['payload']);
    $base = (string) ($log['dedupe_key'] ?? '');
    if (str_starts_with($base, $log['event_code'] . ':')) {
        $base = substr($base, strlen($log['event_code']) + 1);
    }
    $key = $base !== '' ? mb_substr($base, 0, 120) . ':retry:' . date('YmdHis') : 'retry:' . $log['id'] . ':' . date('YmdHis');
    $newId = wa_event((string) $log['event_code'], $payload, ['dedupe_key' => $key, 'source' => $log['source'] ?: 'admin']);
    if ($newId) {
        db_exec("UPDATE wa_event_logs SET error = TRIM(CONCAT(COALESCE(error, ''), ' [retried as #', ?, ']')) WHERE id = ?", [(int) $newId, (int) $log['id']]);
    }
    return $newId ? (int) $newId : null;
}

/**
 * Why a test of this event may not go to this number (null = allowed). Same rule as the template and campaign tests:
 * a test skips the opt-in requirement (staff testing on their own phone) but never messages a blocked number, nor a
 * number that replied STOP when the event's template is MARKETING (WhatsApp business messaging policy).
 * Returns ['message' => toast text, 'field' => phone field error] or null.
 */
function wa_evt_test_refusal(array $evt, string $digits): ?array
{
    $contact = function_exists('wa_contact_by_phone') ? wa_contact_by_phone($digits)
        : db_row('SELECT * FROM wa_contacts WHERE phone = ?', [$digits]);
    if (!$contact) {
        return null;
    }
    if (($contact['status'] ?? '') === 'blocked') {
        return ['message' => 'This number is blocked in WhatsApp contacts.', 'field' => 'This number is blocked in WhatsApp contacts.'];
    }
    $optedOut = function_exists('wa_contact_opted_out') ? wa_contact_opted_out($contact)
        : (int) ($contact['opt_in'] ?? 0) !== 1 && !empty($contact['opted_out_at']);
    if ($optedOut) {
        $tpl = wa_evt_template_for($evt);
        if ($tpl && strtoupper((string) ($tpl['category'] ?? '')) === 'MARKETING') {
            return ['message' => 'This number opted out of WhatsApp marketing messages.', 'field' => 'This number opted out (replied STOP). Use a number that has not opted out.'];
        }
    }
    return null;
}

/** Fire an event for testing: payload from the sample (or the editor), always delivered to $phone. */
function wa_evt_test(array $evt, array $payload, string $phone): array
{
    if (!function_exists('wa_event')) {
        throw new CrudException('The WhatsApp core service is not available.');
    }
    $digits = wa_evt_phone_digits($phone);
    if (!$digits) {
        throw new CrudException('Enter a valid WhatsApp number for the test.');
    }
    if ($refusal = wa_evt_test_refusal($evt, $digits)) {
        throw new CrudException($refusal['message']);
    }
    // record ids and internal markers are removed so no CRM record is changed and the message can only reach the test phone
    foreach (array_keys($payload) as $k) {
        if (!is_string($k) || $k === '' || $k[0] === '_' || in_array($k, ['student_id', 'admission_id', 'enquiry_id', 'contact_id', 'user_id', 'counsellor_id', 'fanout'], true)) {
            unset($payload[$k]);
        }
    }
    $payload['phone'] = $digits;
    $payload['_test'] = true;
    $payload += ['name' => 'Test recipient'];
    $contact = function_exists('wa_contact_by_phone') ? wa_contact_by_phone($digits) : null;
    if (!$contact) {
        $me = function_exists('current_user') ? (current_user()['name'] ?? '') : '';
        $contact = wa_evt_contact_for_phone($digits, ['name' => 'WhatsApp test' . ($me !== '' ? ' (' . $me . ')' : ''), 'contact_type' => 'staff', 'source' => 'manual']);
    }
    if (!$contact) {
        throw new CrudException('Could not create a WhatsApp contact for the test number.');
    }
    wa_evt_by_code((string) $evt['code'], true);
    $logId = wa_event((string) $evt['code'], $payload, ['source' => 'test', 'contact_id' => (int) $contact['id'], 'dedupe_key' => 'test:' . $evt['id'] . ':' . bin2hex(random_bytes(6))]);
    $log = $logId ? wa_evt_log_detail((int) $logId) : null;
    return ['log_id' => $logId ? (int) $logId : null, 'log' => $log];
}

/** Active, unrevoked API token with the given scope (sha256 match). Updates last use. */
function wa_evt_token(string $token, string $scope = 'events'): ?array
{
    $token = trim($token);
    if ($token === '' || strlen($token) > 200) {
        return null;
    }
    $row = db_row('SELECT * FROM wa_api_tokens WHERE token_hash = ? AND revoked_at IS NULL', [hash('sha256', $token)]);
    if (!$row) {
        return null;
    }
    $scopes = array_map('trim', explode(',', (string) $row['scopes']));
    if (!in_array($scope, $scopes, true) && !in_array('*', $scopes, true)) {
        return null;
    }
    db_exec('UPDATE wa_api_tokens SET last_used_at = NOW(), last_used_ip = ? WHERE id = ?', [substr(client_ip(), 0, 45), (int) $row['id']]);
    return $row;
}

/** Variable keys available for an event: sample payload keys + derived (enrichment) keys + contact fields. */
function wa_evt_payload_keys(array $evt): array
{
    $sample = wa_evt_json($evt['sample_payload'] ?? null);
    return array_keys(wa_evt_flatten($sample));
}
