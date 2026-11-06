<?php
/**
 * WhatsApp admission follow-up stages (contacts part, docs/WHATSAPP.md): the 9 lead stages with default follow-up
 * rules — template, delay, number of follow-ups, auto-assigned counsellor, next action and stop conditions.
 *
 * Re-runnable and safe on a live install: inserts missing stages and keeps rules an admin has edited; only fills a
 * missing template link (templates are matched by name when they exist — 09_wa_1_templates runs before this file;
 * 98_wa_1_contacts calls this seeder again so links to templates added later are filled too) and a missing counsellor.
 */
return function (array $opts): void {
    if (!db_table_exists('wa_followup_stages')) {
        return;
    }
    $stop = json_encode(['on_reply' => true, 'on_stage_change' => true, 'on_opt_out' => true, 'on_admission_confirmed' => true]);
    // key => [name, colour, sort, template candidates (first existing wins), delay h, max follow-ups, counsellor email, next action, terminal, active]
    $stages = [
        'new_enquiry' => ['New Enquiry', 'blue', 1, ['lead_followup_new', 'enquiry_followup', 'callback_requested', 'enquiry_received'], 4, 3, 'admissions@gimt.ac.in',
            'Call the lead within 24 hours, confirm the programme of interest and log the call.', 0, 1],
        'contacted' => ['Contacted', 'cyan', 2, ['lead_followup_contacted', 'brochure_requested', 'enquiry_received'], 24, 2, null,
            'Share the programme brochure and fee structure; ask about Class 12 / graduation marks.', 0, 1],
        'interested' => ['Interested', 'purple', 3, ['counselling_invite', 'brochure_requested', 'admission_open_campaign'], 24, 3, null,
            'Book a counselling session or campus visit and send the confirmation.', 0, 1],
        'counselling_scheduled' => ['Counselling Scheduled', 'navy', 4, ['counselling_reminder', 'counselling_scheduled'], 20, 1, null,
            'Confirm attendance a day before; keep the brochure, fee sheet and scholarship slabs ready.', 0, 1],
        'application_started' => ['Application Started', 'amber', 5, ['application_incomplete', 'application_reminder'], 24, 3, null,
            'Help the applicant finish the form — photo, Class 10/12 marksheets, ID proof and declaration.', 0, 1],
        'application_submitted' => ['Application Submitted', 'amber', 6, ['documents_pending', 'application_submitted'], 48, 2, null,
            'Verify the documents and schedule the entrance interview.', 0, 1],
        'payment_pending' => ['Payment Pending', 'red', 7, ['admission_deadline_reminder', 'payment_link_generated', 'fee_due_reminder'], 24, 3, null,
            'Send the payment link and call the parent before the seat-confirmation deadline.', 0, 1],
        'admission_confirmed' => ['Admission Confirmed', 'green', 8, [], 24, 0, null,
            'Share the welcome kit, orientation date and student-portal login.', 1, 1],
        'not_interested' => ['Not Interested', 'slate', 9, [], 24, 0, null, null, 1, 0],
    ];
    // fallbacks for template values the admission portal does not hold ({+N days} = date relative to the send day)
    // (course_name: many leads have not named a programme yet — without a fallback their follow-ups are skipped)
    $varDefaults = [
        'new_enquiry' => ['course_name' => 'our programmes'],
        'contacted' => ['course_name' => 'our programmes'],
        'interested' => ['course_name' => 'our programmes'],
        'counselling_scheduled' => ['counselling_time' => 'the time confirmed by your counsellor', 'course_name' => 'our programmes'],
        'application_started' => ['last_date' => '{+10 days}', 'course_name' => 'our programmes'],
        'application_submitted' => ['last_date' => '{+7 days}', 'document_list' => 'Class 10 and 12 mark sheets, photograph and ID proof'],
        'payment_pending' => ['last_date' => '{+5 days}', 'course_name' => 'our programmes'],
    ];
    foreach ($stages as $key => [$name, $color, $sort, $candidates, $delay, $max, $counsellorEmail, $nextAction, $terminal, $active]) {
        $defaults = isset($varDefaults[$key]) ? json_encode($varDefaults[$key]) : null;
        $hasDefaultsCol = in_array('variable_defaults', db_table_columns('wa_followup_stages'), true);
        $templateId = null;
        foreach ($candidates as $tplName) {
            $templateId = db_value("SELECT id FROM wa_templates WHERE name = ? AND status <> 'archived' ORDER BY status = 'approved' DESC, id LIMIT 1", [$tplName]);
            if ($templateId) {
                break;
            }
        }
        $counsellorId = $counsellorEmail ? db_value("SELECT id FROM users WHERE email = ? AND status = 'active'", [$counsellorEmail]) : null;
        $row = db_row('SELECT * FROM wa_followup_stages WHERE stage_key = ?', [$key]);
        if (!$row) {
            db_insert('wa_followup_stages', ($hasDefaultsCol ? ['variable_defaults' => $defaults] : []) + [
                'stage_key' => $key, 'name' => $name, 'sort_order' => $sort, 'color' => $color, 'template_id' => $templateId ?: null,
                'delay_hours' => $delay, 'max_followups' => $max, 'counsellor_id' => $counsellorId ?: null, 'next_action' => $nextAction,
                'stop_conditions' => $stop, 'is_active' => $active, 'is_terminal' => $terminal,
            ]);
            continue;
        }
        $fill = [];
        if (!$row['template_id'] && $templateId && !$terminal) {
            $fill['template_id'] = $templateId;
        }
        if (!$row['counsellor_id'] && $counsellorId) {
            $fill['counsellor_id'] = $counsellorId;
        }
        if ($hasDefaultsCol && $defaults) {
            // add missing fallback keys; values an admin already set are kept
            $current = json_decode((string) ($row['variable_defaults'] ?? ''), true);
            $current = is_array($current) ? $current : [];
            $merged = $current + $varDefaults[$key];
            if ($merged !== $current) {
                $fill['variable_defaults'] = json_encode($merged);
            }
        }
        if (!$row['stop_conditions']) {
            $fill['stop_conditions'] = $stop;
        }
        if ($fill) {
            db_update('wa_followup_stages', $fill, 'id = ?', [(int) $row['id']]);
        }
    }
};
