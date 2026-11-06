<?php
/**
 * One-line WhatsApp event hooks for existing ERP code (docs/WHATSAPP.md §5). Every call site is wrapped, so even an
 * error while building the payload (or loading this file) can never break the ERP action:
 *
 *   try { require_once APP_ROOT . '/app/whatsapp_hook.php'; gimt_wa_event('GIMT_PAYMENT_SUCCESS', ['student_id' => $sid, 'receipt_no' => $no, 'amount' => $total], ['dedupe_key' => 'pay:' . $paymentId]); } catch (\Throwable $waE) { error_log('[GIMT] WhatsApp hook: ' . $waE->getMessage()); }
 *
 * - gimt_wa_event(): one event (cheap: a log row + a queued message). gimt_wa_fanout(): one event per student / enquiry /
 *   ticket — the request only stores ONE background job (wa_event_fanouts) that the WhatsApp worker expands in batches.
 * - Loads the WhatsApp services lazily (app/services/whatsapp.php is otherwise only loaded by /api/whatsapp/*).
 * - Never throws and never breaks the business flow: every failure is caught and written to the PHP error log.
 * - Skips spreadsheet imports (adm_is_importing()).
 * - Payloads are enriched from the ids they carry (student_id / admission_id / enquiry_id / counsellor_id → name, phone,
 *   program, guardian, counsellor …) by wa_evt_enrich() before wa_event() logs them.
 * - dedupe_key is namespaced with the event code by the core ("GIMT_PAYMENT_SUCCESS:pay:15"), so the same key can be
 *   reused for several codes fired from one place.
 * Owned by the WhatsApp "events" part; the engine is app/services/whatsapp_events.php.
 */

if (!function_exists('gimt_wa_event')) {
    /** Load the WhatsApp services once. False when the module is not installed. */
    function gimt_wa_boot(): bool
    {
        static $ok = null;
        if ($ok !== null) {
            return $ok;
        }
        try {
            $file = APP_ROOT . '/app/services/whatsapp.php';
            if (!is_file($file)) {
                return $ok = false;
            }
            require_once $file;
            $ok = function_exists('wa_event');
        } catch (Throwable $e) {
            error_log('[GIMT] WhatsApp services could not be loaded: ' . $e->getMessage());
            $ok = false;
        }
        return $ok;
    }

    /** True while a CRUD spreadsheet import runs (no per-row WhatsApp events). */
    function gimt_wa_importing(): bool
    {
        if (function_exists('adm_is_importing')) {
            return adm_is_importing();
        }
        return in_array('crud_import', array_column(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 14), 'function'), true);
    }

    /**
     * Fire one business event (or several codes with the same payload).
     * @param string|string[] $codes GIMT_* event code(s)
     * @param array $opts dedupe_key (namespaced per code), source (website|admin|payment|portal|cron|api …)
     */
    function gimt_wa_event($codes, array $payload = [], array $opts = []): void
    {
        try {
            if (gimt_wa_importing() || !gimt_wa_boot()) {
                return;
            }
            foreach ((array) $codes as $code) {
                $code = (string) $code;
                if ($code === '') {
                    continue;
                }
                $p = function_exists('wa_evt_enrich') ? wa_evt_enrich($code, $payload) : $payload;
                wa_event($code, $p, $opts); // the core namespaces dedupe_key with the code

            }
        } catch (Throwable $e) {
            error_log('[GIMT] WhatsApp event ' . implode(',', (array) $codes) . ' failed: ' . $e->getMessage());
        }
    }

    /**
     * Bulk event: one event per id (results, timetable, notice, admit cards, bulk assignments …). The request only
     * stores one background job; the WhatsApp worker expands it in batches. When nothing acts on the event (inactive,
     * no listener, no active workflow) only one summary log row is written.
     * @param string|string[] $codes
     * @param array $opts source, id_key (payload key of each id, default student_id)
     */
    function gimt_wa_fanout($codes, array $common, array $ids, string $dedupe = '', array $opts = []): void
    {
        try {
            if (!$ids || gimt_wa_importing() || !gimt_wa_boot() || !function_exists('wa_evt_fanout')) {
                return;
            }
            wa_evt_fanout($codes, $common, $ids, $dedupe, $opts);
        } catch (Throwable $e) {
            error_log('[GIMT] WhatsApp fan-out event ' . implode(',', (array) $codes) . ' failed: ' . $e->getMessage());
        }
    }

    /** Event codes of a student status change (+ course completed when the student passes out). */
    function gimt_wa_student_status_codes(string $from, string $to): array
    {
        $codes = ['GIMT_STUDENT_STATUS_CHANGED'];
        if (in_array($to, ['graduated', 'alumni'], true) && !in_array($from, ['graduated', 'alumni'], true)) {
            $codes[] = 'GIMT_COURSE_COMPLETED';
        }
        return $codes;
    }

    /** Event code of a website enquiry form (hidden "form" field) — callback, counselling, landing page, chatbot, program page. */
    function gimt_wa_enquiry_code(?string $form, bool $hasProgram = false): string
    {
        $form = strtolower(trim((string) $form));
        if ($form === 'callback') {
            return 'GIMT_CALLBACK_REQUEST';
        }
        if ($form === 'counselling') {
            return 'GIMT_COUNSELLING_REQUEST';
        }
        if (str_starts_with($form, 'landing')) {
            return 'GIMT_LANDING_LEAD';
        }
        if ($form === 'ai-chat' || $form === 'chatbot') {
            return 'GIMT_CHATBOT_LEAD';
        }
        if (in_array($form, ['demo', 'demo-class', 'demo_class', 'trial-class'], true)) {
            return 'GIMT_DEMO_CLASS_REQUEST';
        }
        if ($form === '' && $hasProgram) {
            return 'GIMT_COURSE_ENQUIRY';
        }
        return 'GIMT_ENQUIRY_RECEIVED';
    }

    /** Fire the follow-up events of a logged counsellor follow-up (+ counselling scheduled for a meeting / campus visit). */
    function gimt_wa_followup(int $followupId, array $ids, array $in): void
    {
        $type = (string) ($in['type'] ?? '');
        $next = (string) ($in['next_followup_date'] ?? '');
        $codes = ['GIMT_FOLLOWUP_LOGGED'];
        if ($next !== '' && strtotime($next) && in_array($type, ['meeting', 'campus_visit'], true)) {
            $codes[] = 'GIMT_COUNSELLING_SCHEDULED';
        }
        $payload = $ids + [
            'followup_id' => $followupId, 'type' => $type, 'followup_type' => $type, 'outcome' => (string) ($in['outcome'] ?? ''),
            'next_followup_date' => $next, 'counselling_date' => $next !== '' && strtotime($next) ? date('d M Y', strtotime($next)) : '',
            'counselling_time' => (string) setting('wa_counselling_time', '10:00 AM – 5:00 PM'),
            'counselling_mode' => $type === 'campus_visit' ? 'Campus visit' : 'Counselling meeting',
        ];
        gimt_wa_event($codes, $payload, ['dedupe_key' => 'fup:' . $followupId, 'source' => 'admin']);
    }

    /** Event codes of an admission stage move: always GIMT_ADMISSION_STAGE_CHANGED plus the specific business event. */
    function gimt_wa_stage_codes(string $from, string $to): array
    {
        $codes = ['GIMT_ADMISSION_STAGE_CHANGED'];
        if ($to === 'document_verification') {
            $codes[] = 'GIMT_DOCUMENTS_PENDING';
        } elseif ($to === 'approval') {
            $codes[] = 'GIMT_APPLICATION_UNDER_REVIEW';
        } elseif ($to === 'fee_payment' && in_array($from, ['approval', 'waitlisted'], true)) {
            array_push($codes, 'GIMT_ADMISSION_APPROVED', 'GIMT_OFFER_LETTER_GENERATED');
        } elseif ($to === 'waitlisted') {
            $codes[] = 'GIMT_ADMISSION_WAITLISTED';
        } elseif ($to === 'rejected') {
            $codes[] = 'GIMT_ADMISSION_REJECTED';
        } elseif ($to === 'confirmed') {
            $codes[] = 'GIMT_ADMISSION_CONFIRMED';
        }
        return $codes;
    }

    /** Generic CRUD tap (app/crud.php, after a committed save): enquiries, applications, tickets, holidays, website events, students, exams, programmes. */
    function gimt_wa_crud(string $module, int $id, ?array $old, ?array $row): void
    {
        static $modules = ['holidays' => 1, 'events' => 1, 'students' => 1, 'enquiries' => 1, 'admissions' => 1, 'feedback' => 1, 'exams' => 1, 'programs' => 1];
        if (!isset($modules[$module])) {
            return;
        }
        try {
            if (gimt_wa_importing() || !gimt_wa_boot() || !function_exists('wa_evt_crud_saved')) {
                return;
            }
            wa_evt_crud_saved($module, $id, $old, $row);
        } catch (Throwable $e) {
            error_log('[GIMT] WhatsApp CRUD event (' . $module . ' #' . $id . ') failed: ' . $e->getMessage());
        }
    }
}
