<?php
/**
 * WhatsApp event catalogue (core seeder, always runs): every built-in GIMT_* business event of the brief (sections A–I:
 * website enquiries, registration, admissions, courses, payments, exams, certificates, support, staff/admin) with its
 * source, recipient, a realistic sample payload (the keys the ERP hooks send after enrichment — see
 * app/services/whatsapp_events.php wa_evt_enrich()) and a variable map onto the template library's variable keys.
 *
 * Re-runnable and safe on a live system: rows are upserted by code (ids stay stable for workflows and templates);
 * catalogue metadata (name, description, category, source, sample payload) is refreshed, while the admin's settings
 * (template, conditions, mapping, delay, active flag) are kept. Built-in events no longer in the catalogue are removed.
 * Templates are linked by name (wa_evt_template_name(): code without GIMT_, lowercase, or an alias) and the core
 * transactional events are activated when their template gets linked. On a fresh install this seeder runs after
 * 09_wa_1_templates, so the links are made here. When it ran without templates (older installs), the worker links +
 * activates them on its first run once the templates exist (wa_evt_tick), or use Event Manager › Link templates, or
 * run this seeder again.
 */
return function (array $opts): void {
    if (!db_table_exists('wa_events')) {
        return;
    }
    require_once APP_ROOT . '/app/services/whatsapp_events.php';
    $hasSchedule = in_array('schedule_config', db_table_columns('wa_events'), true);

    $student = ['student_id' => 214, 'name' => 'Rahul Sharma', 'student_name' => 'Rahul Sharma', 'first_name' => 'Rahul', 'phone' => '+919876543210',
        'email' => 'rahul.sharma@example.com', 'student_uid' => 'GIMT26BCA041', 'program_name' => 'Bachelor of Computer Applications',
        'course_name' => 'Bachelor of Computer Applications', 'batch_name' => 'BCA 2026-29', 'section_name' => 'A', 'semester' => 1,
        'guardian_name' => 'Suresh Sharma', 'guardian_phone' => '+919812345670', 'institute_name' => 'Global Institute of Management & Technology',
        'support_number' => '+91 90600 31540'];
    $applicant = ['admission_id' => 412, 'application_no' => 'APP/2026-27/00412', 'application_number' => 'APP/2026-27/00412', 'name' => 'Priya Verma',
        'student_name' => 'Priya Verma', 'first_name' => 'Priya', 'phone' => '+919988776655', 'email' => 'priya.verma@example.com',
        'program_id' => 2, 'program_name' => 'Master of Business Administration', 'course_name' => 'Master of Business Administration',
        'course_duration' => '2 Years', 'counsellor_id' => 3, 'counsellor_name' => 'Pooja Malhotra', 'counsellor_phone' => '+918662927009',
        'last_date' => '30 Nov 2026', 'institute_name' => 'Global Institute of Management & Technology', 'support_number' => '+91 90600 31540'];
    $lead = ['enquiry_id' => 158, 'name' => 'Aman Gupta', 'student_name' => 'Aman Gupta', 'first_name' => 'Aman', 'phone' => '+919123456780',
        'email' => 'aman.gupta@example.com', 'city' => 'Bhubaneswar', 'program_id' => 3, 'program_name' => 'B.Tech in Computer Science & Engineering',
        'course_name' => 'B.Tech in Computer Science & Engineering', 'source' => 'website', 'consent' => true,
        'institute_name' => 'Global Institute of Management & Technology', 'support_number' => '+91 90600 31540', 'last_date' => '30 Nov 2026'];
    $ticket = ['ticket_id' => 142, 'ticket_no' => 'TKT-2026-00142', 'ticket_number' => 'TKT-2026-00142', 'ticket_type' => 'complaint',
        'subject' => 'Wi-Fi not working in Block B', 'issue_summary' => 'Wi-Fi not working in Block B', 'category' => 'Infrastructure'];
    $exam = ['exam_id' => 41, 'exam_name' => 'End Semester Examination – Nov 2026', 'exam_date' => '25 Nov 2026', 'start_date' => '25 Nov 2026',
        'end_date' => '07 Dec 2026', 'portal_link' => 'https://gimtedu.com/portal/student'];
    $receipt = 'https://gimtedu.com/api/fees/verify?no=RCPT%2F2026-27%2F004512&h=9c1f2e3d4a5b6c7d';
    $verify = 'https://gimtedu.com/verify-certificate/GIMT-BON-2026-0088?h=7a1c90d2';
    $n = ['student_name' => 'payload:name', 'course_name' => 'payload:program_name'];
    // leads (website forms, chatbot, walk-ins, WhatsApp-only contacts) may not have chosen a programme yet: their
    // {{course_name}} falls back to "our programmes" (the wording of the starter workflow's welcome) instead of the send
    // being refused for a missing variable. Applicants and students always have a programme (NOT NULL), so their events
    // keep $n — a generic wording there would hide a real data problem.
    $nLead = ['student_name' => 'payload:name', 'course_name' => 'payload:program_name|default:our programmes'];
    $fee = ['fee_amount' => 'payload:amount_text'];

    // code => [name, description, category, source, recipient, sample payload, variable map, extra{business, delay, schedule}]
    $events = [
        // ---------------------------------------------------------------- A. website enquiries
        'GIMT_ENQUIRY_RECEIVED' => ['Website enquiry received', 'A visitor submitted an admission enquiry on the website (home hero, enquiry pop-up, smart enquiry, FAQ question). Thank them and share the next steps.', 'enquiry', 'website', 'contact',
            $lead + ['form' => 'home-hero', 'message' => 'Please share the fee structure and hostel details.'], $nLead],
        'GIMT_CONTACT_FORM' => ['Contact form submitted', 'Message from the website Contact Us page (also the recruiter form).', 'enquiry', 'website', 'contact',
            ['contact_message_id' => 77, 'name' => 'Sneha Patel', 'phone' => '+919090909090', 'email' => 'sneha.patel@example.com', 'subject' => 'Hostel availability', 'enquiry_type' => 'general'], ['student_name' => 'payload:name', 'subject' => 'payload:subject']],
        'GIMT_CALLBACK_REQUEST' => ['Callback requested', 'Visitor asked for a call back from an admission counsellor (template callback_requested).', 'enquiry', 'website', 'contact',
            $lead + ['form' => 'callback', 'callback_time' => 'Evening (5–7 PM)'], $nLead],
        'GIMT_COUNSELLING_REQUEST' => ['Counselling session requested', 'Visitor requested a counselling session from the admissions page (template callback_requested).', 'enquiry', 'website', 'contact',
            $lead + ['form' => 'counselling'], $nLead],
        'GIMT_COURSE_ENQUIRY' => ['Course enquiry', 'Enquiry sent from a program page with the program pre-selected (template enquiry_received).', 'enquiry', 'website', 'contact',
            $lead + ['form' => '', 'page' => 'program-page'], $nLead],
        'GIMT_BROCHURE_DOWNLOAD' => ['Brochure requested', 'A visitor asks for a program brochure on WhatsApp and agrees to receive it (website JavaScript → POST /api/whatsapp/ingest/track with phone + consent; template brochure_requested). Not wired yet: the website\'s brochure buttons are plain download links, so this event only fires once a page posts it.', 'enquiry', 'js', 'contact',
            ['name' => 'Kavya Nair', 'phone' => '+919445566778', 'program_id' => 1, 'program_name' => 'Bachelor of Business Administration', 'brochure_link' => 'https://gimtedu.com/programs/bba?brochure=1', 'page' => '/programs/bba', 'consent' => true], $n + ['brochure_link' => 'payload:brochure_link'], ['business' => 1]],
        'GIMT_LANDING_LEAD' => ['Landing page lead', 'Lead from the Admissions Open landing page (hero or footer form; template enquiry_received).', 'enquiry', 'website', 'contact',
            $lead + ['form' => 'landing-hero', 'utm_source' => 'google', 'utm_campaign' => 'admissions-2026'], $nLead],
        'GIMT_CHATBOT_LEAD' => ['Chatbot lead captured', 'The website AI assistant collected a lead (template enquiry_received).', 'enquiry', 'website', 'contact',
            $lead + ['form' => 'ai-chat'], $nLead],
        'GIMT_ENQUIRY_REPEAT' => ['Repeat enquiry', 'The same number enquired again within 30 days (the open enquiry was updated and marked high priority).', 'enquiry', 'website', 'contact',
            $lead + ['form' => 'enquiry-popup'], $nLead],
        'GIMT_ENQUIRY_CREATED_ADMIN' => ['Walk-in / phone enquiry added', 'Staff added an enquiry in the ERP (walk-in, phone call, education fair …; template enquiry_received).', 'enquiry', 'admin', 'contact',
            $lead + ['source' => 'walk_in'], $nLead],
        'GIMT_ENQUIRY_CREATED' => ['Contact message converted to enquiry', 'A website contact message was converted into an admission enquiry.', 'enquiry', 'admin', 'contact',
            $lead + ['contact_message_id' => 77], $nLead],
        'GIMT_ENQUIRY_ASSIGNED' => ['Counsellor assigned (to the lead)', 'An enquiry was assigned to an admission counsellor: introduce the counsellor to the student.', 'enquiry', 'admin', 'contact',
            $lead + ['counsellor_id' => 3, 'counsellor_name' => 'Pooja Malhotra', 'counsellor_phone' => '+918662927009', 'assigned_by' => 'Administrator'], $nLead + ['counsellor_name' => 'payload:counsellor_name', 'counsellor_phone' => 'payload:counsellor_phone']],
        'GIMT_ENQUIRY_STATUS_CHANGED' => ['Enquiry status changed', 'The status of an enquiry changed in the ERP (contacted, interested, not interested, closed).', 'enquiry', 'admin', 'contact',
            $lead + ['old_status' => 'new', 'new_status' => 'interested'], $nLead],
        'GIMT_FOLLOWUP_LOGGED' => ['Follow-up logged', 'A counsellor logged a call / meeting / visit with a lead or applicant (outcome and next follow-up date).', 'enquiry', 'admin', 'contact',
            $lead + ['followup_id' => 920, 'type' => 'call', 'outcome' => 'interested', 'next_followup_date' => '2026-11-06'], $nLead],
        'GIMT_FOLLOWUP_PENDING' => ['Counsellor follow-ups pending', 'Daily digest to each counsellor: follow-ups due today and overdue.', 'enquiry', 'cron', 'counsellor',
            ['counsellor_id' => 3, 'counsellor_name' => 'Pooja Malhotra', 'counsellor_phone' => '+918662927009', 'due_today' => 6, 'overdue' => 2, 'pending_count' => 8, 'top_names' => 'Aman Gupta, Riya Das, Mohit Jain', 'date' => '03 Nov 2026', 'followups_link' => 'https://gimtedu.com/admin/admissions'],['counsellor_name' => 'payload:counsellor_name', 'pending_count' => 'payload:pending_count'], ['schedule' => ['time' => '09:00']]],
        'GIMT_EVENT_REGISTERED' => ['Website event registration', 'Someone registered for a campus event on the website.', 'enquiry', 'website', 'contact',
            ['event_registration_id' => 31, 'event_id' => 9, 'name' => 'Arjun Singh', 'phone' => '+919334455667', 'event_title' => 'Admission Open Day 2026', 'event_name' => 'Admission Open Day 2026', 'event_date' => '15 Nov 2026, 10:00 AM', 'venue' => 'GIMT Main Auditorium', 'event_venue' => 'GIMT Main Auditorium', 'event_link' => 'https://gimtedu.com/events/admission-open-day-2026'], ['student_name' => 'payload:name', 'event_name' => 'payload:event_title', 'event_date' => 'payload:event_date', 'event_venue' => 'payload:venue']],

        // ---------------------------------------------------------------- B. registration & application
        'GIMT_APPLICATION_STARTED' => ['Application started', 'The online application wizard was started (website JavaScript after step 1 → POST /api/whatsapp/ingest/track with phone + consent). Not wired yet: the apply wizard does not post it, so it only fires once the website sends it.', 'registration', 'js', 'contact',
            ['name' => 'Riya Das', 'phone' => '+919876501234', 'program_name' => 'Bachelor of Computer Applications', 'apply_link' => 'https://gimtedu.com/apply', 'consent' => true], $n + ['apply_link' => 'payload:apply_link'], ['business' => 1]],
        'GIMT_APPLICATION_SUBMITTED' => ['Application submitted', 'Online application submitted: share the application number and the acknowledgement link.', 'registration', 'admission_portal', 'contact',
            $applicant + ['session' => '2026-27', 'documents_count' => 4, 'admission_status' => 'Under review', 'ack_link' => 'https://gimtedu.com/apply?ack=APP%2F2026-27%2F00412&t=5f1c0e7a9b'], $n + ['application_number' => 'payload:application_no', 'admission_status' => 'payload:admission_status']],
        'GIMT_APPLICATION_CREATED' => ['Application created by staff', 'An admission officer created an application in the ERP.', 'registration', 'admin', 'contact',
            $applicant, $n + ['application_number' => 'payload:application_no']],
        'GIMT_APPLICATION_ASSIGNED' => ['Counsellor assigned (to the applicant)', 'An application was assigned to an admission counsellor.', 'registration', 'admin', 'contact',
            $applicant + ['assigned_by' => 'Administrator'], $n + ['counsellor_name' => 'payload:counsellor_name', 'counsellor_phone' => 'payload:counsellor_phone']],
        'GIMT_APPLICATION_INCOMPLETE' => ['Application incomplete', 'Daily reminder for online applications started but not submitted (1, 3 and 7 days after).', 'registration', 'cron', 'contact',
            $applicant + ['days_since' => 3, 'apply_link' => 'https://gimtedu.com/apply'], $n + ['application_number' => 'payload:application_no', 'last_date' => 'payload:last_date'], ['business' => 1, 'schedule' => ['days' => [1, 3, 7], 'time' => '11:00']]],
        'GIMT_OTP_VERIFICATION' => ['OTP verification', 'One-time code for registration / mobile verification (fired by an external system through the API).', 'registration', 'api', 'contact',
            ['name' => 'Riya Das', 'phone' => '+919876501234', 'otp_code' => '482913'], ['otp_code' => 'payload:otp_code']],

        // ---------------------------------------------------------------- C. admissions
        'GIMT_ADMISSION_STAGE_CHANGED' => ['Admission stage changed', 'Any move of an application on the admission board. It fires together with the stage\'s own event (documents pending, under review, approved, rejected, confirmed …), so switch it on only with a condition on to_stage for stages without their own message.', 'admission', 'admin', 'contact',
            $applicant + ['from_stage' => 'application', 'to_stage' => 'document_verification', 'stage_label' => 'Document Verification'], $n + ['application_number' => 'payload:application_no', 'admission_status' => 'payload:stage_label']],
        'GIMT_DOCUMENTS_PENDING' => ['Documents under verification', 'The application moved to document verification: list the documents still pending.', 'admission', 'admin', 'contact',
            $applicant + ['from_stage' => 'application', 'to_stage' => 'document_verification', 'document_list' => '12th Marksheet, ID Proof'], $n + ['application_number' => 'payload:application_no', 'document_list' => 'payload:document_list', 'last_date' => 'payload:last_date']],
        'GIMT_DOCUMENT_VERIFIED' => ['Document verified', 'One uploaded document was verified.', 'admission', 'admin', 'contact',
            $applicant + ['document' => '12th Marksheet', 'document_status' => 'verified'], $n + ['document' => 'payload:document']],
        'GIMT_DOCUMENT_REJECTED' => ['Document rejected', 'A document was rejected: ask the applicant to upload it again (template documents_rejected).', 'admission', 'admin', 'contact',
            $applicant + ['document' => 'ID Proof', 'document_status' => 'rejected', 'remarks' => 'Image is blurred, please upload a clear scan.'], $n + ['application_number' => 'payload:application_no', 'remarks' => 'payload:remarks', 'last_date' => 'payload:last_date']],
        'GIMT_DOCUMENTS_VERIFIED' => ['All documents verified', 'Every document of the application is verified (template documents_approved).', 'admission', 'admin', 'contact',
            $applicant + ['all_verified' => true], $n + ['application_number' => 'payload:application_no']],
        'GIMT_INTERVIEW_SCHEDULED' => ['Entrance / interview scheduled', 'Interview or entrance test date set for the applicant.', 'admission', 'admin', 'contact',
            $applicant + ['interview_date' => '12 Nov 2026', 'interview_time' => '11:00 AM', 'entrance_exam' => 'GIMT Aptitude Test'], $n + ['interview_date' => 'payload:interview_date', 'interview_time' => 'payload:interview_time']],
        'GIMT_COUNSELLING_SCHEDULED' => ['Counselling scheduled', 'A counselling meeting or campus visit was scheduled by the counsellor (follow-up of type meeting / campus visit with a date).', 'admission', 'admin', 'contact',
            $lead + ['followup_type' => 'meeting', 'counselling_date' => '08 Nov 2026', 'counselling_time' => '10:00 AM – 5:00 PM', 'counsellor_id' => 3, 'counsellor_name' => 'Pooja Malhotra', 'counsellor_phone' => '+918662927009'], $nLead + ['counselling_date' => 'payload:counselling_date', 'counselling_time' => 'payload:counselling_time']],
        'GIMT_ADMISSION_APPROVED' => ['Admission approved (fee payment pending)', 'Application approved and moved to fee payment: share the offer and the admission fee.', 'admission', 'admin', 'contact',
            $applicant + ['offer_letter_no' => 'GIMT/OL/2026-27/0087', 'admission_fee' => 25000, 'admission_fee_text' => '₹25,000', 'fee_amount' => '₹25,000'], $n + ['application_number' => 'payload:application_no', 'fee_amount' => 'payload:fee_amount', 'course_duration' => 'payload:course_duration', 'last_date' => 'payload:last_date']],
        'GIMT_OFFER_LETTER_GENERATED' => ['Offer letter generated', 'An offer letter number was issued for the application. It fires together with Admission approved (which already carries the fee and deadline), so it is off by default.', 'admission', 'admin', 'contact',
            $applicant + ['offer_letter_no' => 'GIMT/OL/2026-27/0087'], $n + ['offer_letter_no' => 'payload:offer_letter_no']],
        'GIMT_ADMISSION_WAITLISTED' => ['Application waitlisted', 'The application was put on the waiting list.', 'admission', 'admin', 'contact',
            $applicant + ['remarks' => 'Seats full in the first round'], $n],
        'GIMT_ADMISSION_REJECTED' => ['Application rejected', 'The application was rejected (reason included).', 'admission', 'admin', 'contact',
            $applicant + ['reason' => 'Minimum eligibility (50% in graduation) not met'], $n + ['application_number' => 'payload:application_no']],
        'GIMT_ADMISSION_CONFIRMED' => ['Admission confirmed', 'Seat confirmed / applicant enrolled as a student: admission number, roll number, batch and section.', 'admission', 'admin', 'contact',
            array_merge($applicant, $student, ['admission_no' => 'GIMT/2026/0412', 'roll_no' => 'BCA26-041', 'application_no' => 'APP/2026-27/00412', 'application_number' => 'APP/2026-27/00412', 'payment_status' => 'Paid']), $n + ['application_number' => 'payload:application_no', 'payment_status' => 'payload:payment_status']],
        'GIMT_ADMISSION_DEADLINE' => ['Admission deadline reminder', 'Countdown to the admission last date for leads who have not applied yet (7, 3 and 1 days before; template admission_deadline_reminder).', 'admission', 'cron', 'contact',
            $lead + ['deadline' => '30 Nov 2026', 'days_left' => 7, 'apply_link' => 'https://gimtedu.com/apply', 'application_number' => 'not started yet'], $nLead + ['last_date' => 'payload:last_date', 'application_number' => 'payload:application_number'], ['business' => 1, 'schedule' => ['days' => [7, 3, 1], 'time' => '10:00']]],

        // ---------------------------------------------------------------- D. courses & academics
        'GIMT_STUDENT_ENROLLED' => ['Student enrolled (welcome)', 'A student record was created (staff or admission conversion): welcome message with the student ID. On a conversion it fires together with Admission confirmed — add the condition admission_id "is empty" to welcome only students added directly.', 'course', 'admin', 'contact',
            $student, $n + ['student_id_no' => 'payload:student_uid']],
        'GIMT_BATCH_ALLOCATED' => ['Batch allocated', 'The student was allocated to a batch.', 'course', 'admin', 'contact',
            $student, $n + ['batch_name' => 'payload:batch_name']],
        'GIMT_SECTION_ALLOCATED' => ['Section allocated', 'The student was allocated to (or moved to) a section.', 'course', 'admin', 'contact',
            $student, $n + ['section_name' => 'payload:section_name']],
        'GIMT_TIMETABLE_PUBLISHED' => ['Timetable published', 'The class timetable of the student\'s section was published (template class_schedule_published).', 'course', 'admin', 'contact',
            $student + ['timetable_label' => 'BCA Sem 1 – Section A', 'classes_count' => 28, 'class_date' => '04 Nov 2026', 'portal_link' => 'https://gimtedu.com/portal/student'], $n + ['class_date' => 'payload:class_date']],
        'GIMT_CLASS_REMINDER' => ['Class reminder (next day)', 'Evening reminder of tomorrow\'s first class and number of classes (skips holidays).', 'course', 'cron', 'contact',
            $student + ['class_date' => 'Wednesday, 04 Nov 2026', 'first_class_time' => '09:30 AM', 'class_time' => '09:30 AM', 'first_subject' => 'Programming in C', 'subject_name' => 'Programming in C', 'classes_count' => 5], $n + ['subject_name' => 'payload:subject_name', 'class_date' => 'payload:class_date', 'class_time' => 'payload:class_time'], ['schedule' => ['time' => '18:00']]],
        'GIMT_CLASS_CANCELLED' => ['Class cancelled', 'A class was cancelled (fired by the timetable team or an external system through the API).', 'course', 'api', 'contact',
            $student + ['subject_name' => 'Financial Accounting', 'class_date' => '10 Nov 2026'], $n + ['subject_name' => 'payload:subject_name', 'class_date' => 'payload:class_date']],
        'GIMT_STUDENT_PROMOTED' => ['Promoted to next semester', 'The student was promoted in a promotion run.', 'course', 'admin', 'contact',
            $student + ['promotion' => 'promote', 'from_semester' => 1, 'to_semester' => 2, 'session' => '2026-27', 'reference_no' => 'PRM/2026-27/0004'], $n + ['to_semester' => 'payload:to_semester']],

        // ---------------------------------------------------------------- E. payments & fees
        'GIMT_FEE_INVOICE_RAISED' => ['Fee invoice raised', 'A fee invoice was raised for the student (amount and due date).', 'payment', 'admin', 'contact',
            $student + ['invoice_id' => 1245, 'invoice_no' => 'INV/2026-27/001245', 'invoice_number' => 'INV/2026-27/001245', 'fee_title' => 'Semester 1 Tuition Fee', 'amount' => 48500, 'amount_text' => '₹48,500', 'fee_amount' => '₹48,500', 'due_date' => '15 Nov 2026', 'payment_link' => 'https://gimtedu.com/portal/student/fees'], $n + $fee + ['due_date' => 'payload:due_date', 'payment_link' => 'payload:payment_link']],
        'GIMT_PAYMENT_LINK_CREATED' => ['Online payment link created', 'The student started an online payment (Cashfree link; template payment_link_generated).', 'payment', 'payment', 'contact',
            $student + ['order_ref' => 'GIMT-PAY-2026-000318', 'amount' => 24250, 'amount_text' => '₹24,250', 'fee_amount' => '₹24,250', 'invoice_no' => 'INV/2026-27/001245', 'due_date' => '15 Nov 2026', 'payment_link' => 'https://payments.cashfree.com/links/abc123'], $n + $fee + ['payment_link' => 'payload:payment_link', 'due_date' => 'payload:due_date']],
        'GIMT_PAYMENT_SUCCESS' => ['Payment successful', 'A fee payment was received (counter or online): receipt number and verification link.', 'payment', 'payment', 'contact',
            $student + ['payment_id' => 5821, 'receipt_no' => 'RCPT/2026-27/004512', 'receipt_number' => 'RCPT/2026-27/004512', 'amount' => 24250, 'amount_text' => '₹24,250', 'fee_amount' => '₹24,250', 'mode' => 'UPI', 'payment_date' => '03 Nov 2026', 'balance' => 24250, 'balance_text' => '₹24,250', 'receipt_link' => $receipt], $n + $fee + ['receipt_number' => 'payload:receipt_no', 'payment_date' => 'payload:payment_date', 'receipt_link' => 'payload:receipt_link']],
        'GIMT_ONLINE_PAYMENT_SUCCESS' => ['Online payment received (Cashfree)', 'An online payment was posted from Cashfree (Payment successful also fires for it — activate only one of the two).', 'payment', 'payment', 'contact',
            $student + ['payment_id' => 5821, 'order_ref' => 'GIMT-PAY-2026-000318', 'receipt_no' => 'RCPT/2026-27/004512', 'amount' => 24250, 'amount_text' => '₹24,250', 'mode' => 'Online (Cashfree)', 'transaction_id' => 'CF-882316', 'payment_date' => '03 Nov 2026', 'receipt_link' => $receipt], $n + $fee + ['receipt_number' => 'payload:receipt_no', 'receipt_link' => 'payload:receipt_link']],
        'GIMT_RECEIPT_GENERATED' => ['Receipt generated', 'A fee receipt was generated (template fee_receipt_generated; Payment successful carries the same link — activate one of the two).', 'payment', 'admin', 'contact',
            $student + ['payment_id' => 5821, 'receipt_no' => 'RCPT/2026-27/004512', 'receipt_number' => 'RCPT/2026-27/004512', 'amount' => 24250, 'amount_text' => '₹24,250', 'payment_date' => '03 Nov 2026', 'receipt_link' => $receipt], $n + $fee + ['receipt_number' => 'payload:receipt_no', 'payment_date' => 'payload:payment_date', 'receipt_link' => 'payload:receipt_link']],
        'GIMT_PAYMENT_FAILED' => ['Payment failed', 'An online payment attempt failed (Cashfree webhook): share the payment link again.', 'payment', 'payment', 'contact',
            $student + ['order_ref' => 'GIMT-PAY-2026-000318', 'amount' => 24250, 'amount_text' => '₹24,250', 'reason' => 'Payment declined by the bank', 'payment_link' => 'https://payments.cashfree.com/links/abc123'], $n + $fee + ['payment_link' => 'payload:payment_link']],
        'GIMT_PAYMENT_PENDING' => ['Payment pending', 'An online payment link is still unpaid one hour after it was created.', 'payment', 'cron', 'contact',
            $student + ['order_ref' => 'GIMT-PAY-2026-000318', 'amount' => 24250, 'amount_text' => '₹24,250', 'payment_link' => 'https://payments.cashfree.com/links/abc123'], $n + $fee + ['payment_link' => 'payload:payment_link'], ['schedule' => ['minutes' => 60]]],
        'GIMT_FEE_INSTALMENT_DUE' => ['Fee instalment due', 'Reminder N days before a fee instalment (or invoice) is due — default 3 days and 1 day before (template fee_due_reminder).', 'payment', 'cron', 'contact',
            $student + ['invoice_no' => 'INV/2026-27/001245', 'fee_title' => 'Semester 1 Tuition Fee', 'installment_no' => 2, 'amount' => 24250, 'amount_text' => '₹24,250', 'due_date' => '06 Nov 2026', 'days_left' => 3, 'payment_link' => 'https://gimtedu.com/portal/student/fees'], $n + $fee + ['due_date' => 'payload:due_date', 'payment_link' => 'payload:payment_link'], ['schedule' => ['days' => [3, 1], 'time' => '09:00']]],
        'GIMT_FEE_OVERDUE' => ['Fee instalment overdue', 'Reminder after the due date has passed — default 1, 7 and 15 days overdue (template installment_overdue).', 'payment', 'cron', 'contact',
            $student + ['invoice_no' => 'INV/2026-27/001245', 'fee_title' => 'Semester 1 Tuition Fee', 'installment_no' => 1, 'amount' => 24250, 'amount_text' => '₹24,250', 'due_date' => '20 Oct 2026', 'days_overdue' => 7, 'payment_link' => 'https://gimtedu.com/portal/student/fees'], $n + $fee + ['due_date' => 'payload:due_date', 'payment_link' => 'payload:payment_link'], ['schedule' => ['days' => [1, 7, 15], 'time' => '10:00']]],
        'GIMT_FEE_REMINDER' => ['Manual fee reminder', 'Accounts sent fee reminders from the Fees module (template fee_due_reminder).', 'payment', 'admin', 'contact',
            $student + ['invoice_no' => 'INV/2026-27/001245', 'amount' => 24250, 'amount_text' => '₹24,250', 'due_date' => '20 Oct 2026', 'payment_link' => 'https://gimtedu.com/portal/student/fees'], $n + $fee + ['due_date' => 'payload:due_date', 'payment_link' => 'payload:payment_link']],
        'GIMT_ADMISSION_FEE_RECEIVED' => ['Admission fee received', 'The admission fee of an application was recorded.', 'payment', 'admin', 'contact',
            $applicant + ['receipt_no' => 'ADMR/2026-27/00087', 'receipt_number' => 'ADMR/2026-27/00087', 'amount' => 25000, 'amount_text' => '₹25,000', 'mode' => 'UPI', 'payment_date' => '03 Nov 2026', 'payment_status' => 'Paid'], $n + $fee + ['receipt_number' => 'payload:receipt_no']],
        'GIMT_RECEIPT_CANCELLED' => ['Receipt cancelled', 'A fee receipt was voided by accounts.', 'payment', 'admin', 'contact',
            $student + ['receipt_no' => 'RCPT/2026-27/004512', 'amount' => 24250, 'amount_text' => '₹24,250', 'reason' => 'Cheque bounced'], $n + ['receipt_number' => 'payload:receipt_no', 'reason' => 'payload:reason']],
        'GIMT_REFUND_REQUESTED' => ['Refund requested', 'A refund request was raised.', 'payment', 'admin', 'contact',
            $student + ['refund_id' => 12, 'refund_no' => 'RFND-2026-0032', 'transaction_id' => 'RFND-2026-0032', 'amount' => 5000, 'refund_amount' => '₹5,000', 'reason' => 'Hostel not availed'], $n + ['refund_amount' => 'payload:refund_amount']],
        'GIMT_REFUND_APPROVED' => ['Refund approved', 'The refund request was approved (template refund_initiated).', 'payment', 'admin', 'contact',
            $student + ['refund_id' => 12, 'refund_no' => 'RFND-2026-0032', 'transaction_id' => 'RFND-2026-0032', 'amount' => 5000, 'refund_amount' => '₹5,000', 'payment_date' => '03 Nov 2026'], $n + ['refund_amount' => 'payload:refund_amount', 'transaction_id' => 'payload:transaction_id', 'payment_date' => 'payload:payment_date']],
        'GIMT_REFUND_REJECTED' => ['Refund rejected', 'The refund request was rejected (reason included).', 'payment', 'admin', 'contact',
            $student + ['refund_id' => 12, 'refund_no' => 'RFND-2026-0032', 'amount' => 5000, 'refund_amount' => '₹5,000', 'reason' => 'Refund window closed'], $n + ['refund_amount' => 'payload:refund_amount', 'reason' => 'payload:reason']],
        'GIMT_REFUND_PROCESSED' => ['Refund paid', 'The refund was paid to the student (template refund_completed).', 'payment', 'admin', 'contact',
            $student + ['refund_id' => 12, 'refund_no' => 'RFND-2026-0032', 'amount' => 5000, 'refund_amount' => '₹5,000', 'mode' => 'Bank transfer', 'reference' => 'NEFT/HDFC/88231', 'transaction_id' => 'NEFT/HDFC/88231'], $n + ['refund_amount' => 'payload:refund_amount', 'transaction_id' => 'payload:transaction_id']],

        // ---------------------------------------------------------------- F. examinations
        'GIMT_EXAM_SCHEDULED' => ['Exam schedule published', 'The date sheet of an examination was completed and published (template exam_schedule_released).', 'exam', 'admin', 'contact',
            $student + $exam, $n + ['exam_name' => 'payload:exam_name', 'exam_date' => 'payload:start_date']],
        'GIMT_ADMIT_CARD_GENERATED' => ['Admit card generated', 'Hall tickets were allocated: the admit card is available in the student portal.', 'exam', 'admin', 'contact',
            $student + $exam + ['hall_ticket_no' => 'HT26000412', 'admit_card_link' => 'https://gimtedu.com/portal/student'], $n + ['exam_name' => 'payload:exam_name', 'admit_card_link' => 'payload:admit_card_link', 'exam_date' => 'payload:exam_date']],
        'GIMT_EXAM_REMINDER' => ['Exam reminder (day before)', 'Reminder the evening before an exam paper (also sent by the manual "Remind students" action).', 'exam', 'cron', 'contact',
            $student + $exam + ['subject' => 'Programming in C', 'subject_name' => 'Programming in C', 'exam_time' => '10:00 AM', 'hall_ticket_no' => 'HT26000412'], $n + ['exam_name' => 'payload:exam_name', 'exam_date' => 'payload:exam_date'], ['schedule' => ['time' => '17:00']]],
        'GIMT_MARKS_UPDATED' => ['Marks updated', 'Marks of an exam were verified and locked; results are computed.', 'exam', 'admin', 'contact',
            $student + $exam + ['result_status' => 'Pass', 'percentage' => '78.6'], $n + ['exam_name' => 'payload:exam_name']],
        'GIMT_RESULT_PUBLISHED' => ['Result published', 'Exam results were published: result status, SGPA and percentage.', 'exam', 'admin', 'contact',
            $student + $exam + ['result_status' => 'Pass', 'sgpa' => '8.42', 'percentage' => '78.6', 'result_link' => 'https://gimtedu.com/portal/student'], $n + ['exam_name' => 'payload:exam_name', 'result_link' => 'payload:result_link']],

        // ---------------------------------------------------------------- G. certificates & documents
        'GIMT_CERTIFICATE_REQUESTED' => ['Certificate requested', 'The student requested a certificate from the portal.', 'certificate', 'admission_portal', 'contact',
            $student + ['certificate_id' => 88, 'certificate_no' => 'GIMT/BON/2026/0088', 'certificate_type' => 'Bonafide Certificate', 'purpose' => 'Bank loan'], $n + ['certificate_type' => 'payload:certificate_type']],
        'GIMT_CERTIFICATE_GENERATED' => ['Certificate generated (pending approval)', 'A certificate was generated and is waiting for approval.', 'certificate', 'admin', 'contact',
            $student + ['certificate_id' => 88, 'certificate_no' => 'GIMT/BON/2026/0088', 'certificate_type' => 'Bonafide Certificate'], $n + ['certificate_type' => 'payload:certificate_type', 'certificate_no' => 'payload:certificate_no']],
        'GIMT_CERTIFICATE_APPROVED' => ['Certificate approved', 'The certificate was approved. It fires together with Certificate ready (same verification link), so switch on only one of the two.', 'certificate', 'admin', 'contact',
            $student + ['certificate_id' => 88, 'certificate_no' => 'GIMT/BON/2026/0088', 'certificate_type' => 'Bonafide Certificate', 'verify_link' => $verify], $n + ['certificate_type' => 'payload:certificate_type', 'certificate_link' => 'payload:verify_link']],
        'GIMT_CERTIFICATE_ISSUED' => ['Certificate ready', 'The certificate is issued and ready: number and public verification link (template certificate_ready).', 'certificate', 'admin', 'contact',
            $student + ['certificate_id' => 88, 'certificate_no' => 'GIMT/BON/2026/0088', 'certificate_type' => 'Bonafide Certificate', 'issue_date' => '03 Nov 2026', 'verify_link' => $verify, 'certificate_link' => $verify], $n + ['certificate_link' => 'payload:certificate_link']],
        'GIMT_CERTIFICATE_REJECTED' => ['Certificate rejected / revoked', 'The certificate request was rejected, or an issued certificate was revoked.', 'certificate', 'admin', 'contact',
            $student + ['certificate_id' => 88, 'certificate_no' => 'GIMT/BON/2026/0088', 'certificate_type' => 'Bonafide Certificate', 'reason' => 'Fee dues pending', 'action' => 'rejected'], $n + ['reason' => 'payload:reason']],
        'GIMT_MARKSHEET_GENERATED' => ['Marksheet generated', 'Marksheets of an exam were generated (available in the student portal).', 'certificate', 'admin', 'contact',
            $student + $exam + ['marksheet_no' => 'MS/2026/004512', 'marksheet_link' => 'https://gimtedu.com/portal/student'], $n + ['exam_name' => 'payload:exam_name', 'marksheet_link' => 'payload:marksheet_link']],

        // ---------------------------------------------------------------- H. support
        'GIMT_TICKET_CREATED' => ['Support ticket created', 'A ticket, complaint, grievance or feedback was registered (ticket number).', 'support', 'admin', 'contact',
            $student + $ticket, $n + ['ticket_number' => 'payload:ticket_no', 'issue_summary' => 'payload:issue_summary']],
        'GIMT_TICKET_ASSIGNED' => ['Ticket assigned (to the student)', 'The ticket was assigned to a staff member: let the student know it is in progress.', 'support', 'admin', 'contact',
            $student + $ticket + ['assignee_name' => 'Ravi Agarwal'], $n + ['ticket_number' => 'payload:ticket_no', 'assignee_name' => 'payload:assignee_name']],
        'GIMT_TICKET_RESOLVED' => ['Ticket / complaint resolved', 'The ticket was resolved or closed (template complaint_resolved).', 'support', 'admin', 'contact',
            $student + $ticket + ['ticket_status' => 'resolved', 'resolution' => 'Access point replaced; Wi-Fi restored.'], $n + ['ticket_number' => 'payload:ticket_no']],
        'GIMT_FEEDBACK_REQUEST' => ['Feedback request', 'Ask for feedback after a ticket is resolved (sent 60 minutes later by default).', 'support', 'admin', 'contact',
            $student + $ticket + ['ticket_status' => 'resolved'], $n + ['ticket_number' => 'payload:ticket_no'], ['delay' => 60, 'business' => 1]],
        'GIMT_COMPLAINT_REGISTERED' => ['Hostel complaint registered', 'A hostel complaint was raised from the student portal.', 'support', 'admission_portal', 'contact',
            $student + ['complaint_id' => 23, 'complaint_no' => 'HC-2026-00023', 'ticket_number' => 'HC-2026-00023', 'complaint_category' => 'Electrical', 'subject' => 'Fan not working in room 214', 'issue_summary' => 'Fan not working in room 214', 'room_no' => '214'], $n + ['ticket_number' => 'payload:complaint_no', 'issue_summary' => 'payload:subject']],

        // ---------------------------------------------------------------- I. staff & admin
        'GIMT_LEAD_TRANSFERRED' => ['Lead assigned to counsellor (staff alert)', 'A lead or application was assigned / transferred: alert the counsellor with the lead details.', 'staff', 'admin', 'counsellor',
            $lead + ['counsellor_id' => 3, 'counsellor_name' => 'Pooja Malhotra', 'counsellor_phone' => '+918662927009', 'assigned_by' => 'Administrator'], ['counsellor_name' => 'payload:counsellor_name', 'student_name' => 'payload:name', 'student_phone' => 'payload:phone']],
        'GIMT_TICKET_ASSIGNED_STAFF' => ['Ticket assigned (staff alert)', 'A support ticket was assigned to a staff member.', 'staff', 'admin', 'counsellor',
            $ticket + ['name' => 'Rahul Sharma', 'student_phone' => '+919876543210', 'counsellor_id' => 5, 'counsellor_name' => 'Ravi Agarwal', 'counsellor_phone' => '+919555285052'], ['ticket_number' => 'payload:ticket_no', 'issue_summary' => 'payload:subject']],
        'GIMT_NOTICE_PUBLISHED' => ['Notice published', 'A notice / announcement was published to students.', 'staff', 'admin', 'contact',
            $student + ['notice_id' => 64, 'notice_title' => 'Revised class timings from 10 November', 'notice_category' => 'academic', 'notice_link' => 'https://gimtedu.com/notices/revised-class-timings'], $n + ['notice_title' => 'payload:notice_title', 'notice_link' => 'payload:notice_link']],
        'GIMT_HOLIDAY_ANNOUNCED' => ['Holiday announced', 'A holiday was added to the academic calendar (template holiday_notification).', 'staff', 'admin', 'contact',
            $student + ['holiday_id' => 17, 'title' => 'Guru Nanak Jayanti', 'holiday_name' => 'Guru Nanak Jayanti', 'holiday_date' => '24 Nov 2026', 'end_date' => '', 'reopen_date' => '25 Nov 2026'], $n + ['holiday_name' => 'payload:holiday_name', 'holiday_date' => 'payload:holiday_date', 'reopen_date' => 'payload:reopen_date']],
        'GIMT_EVENT_ANNOUNCED' => ['Campus event published', 'A campus event was published on the website (template event_invitation).', 'staff', 'admin', 'contact',
            $student + ['event_id' => 9, 'event_title' => 'TechFest 2026', 'event_date' => '20 Nov 2026, 10:00 AM', 'venue' => 'GIMT Main Auditorium', 'event_link' => 'https://gimtedu.com/events/techfest-2026'], $n + ['event_name' => 'payload:event_title', 'event_date' => 'payload:event_date', 'event_venue' => 'payload:venue']],
        'GIMT_EVENT_REMINDER' => ['Event reminder (day before)', 'Reminder to everyone registered for a campus event happening tomorrow (registrations with a mobile number; cancelled ones are skipped; template event_reminder).', 'staff', 'cron', 'contact',
            ['event_registration_id' => 31, 'event_id' => 9, 'name' => 'Arjun Singh', 'phone' => '+919334455667', 'event_title' => 'Admission Open Day 2026', 'event_date' => '15 Nov 2026, 10:00 AM', 'venue' => 'GIMT Main Auditorium', 'event_link' => 'https://gimtedu.com/events/admission-open-day-2026'], ['student_name' => 'payload:name', 'event_name' => 'payload:event_title', 'event_date' => 'payload:event_date', 'event_venue' => 'payload:venue'], ['schedule' => ['time' => '17:00']]],
        'GIMT_STUDENT_STATUS_CHANGED' => ['Student status changed', 'The student\'s status changed (active, suspended, dropped, graduated …).', 'staff', 'admin', 'contact',
            $student + ['old_status' => 'active', 'new_status' => 'suspended', 'status_label' => 'Suspended', 'reason' => 'Attendance below 50%'], $n + ['new_status' => 'payload:status_label']],

        // ---------------------------------------------------------------- brief events without a GIMT screen of their own
        // Fired by GIMT where the ERP has the data; otherwise honestly marked as API / website-JS events that an external
        // portal, LMS, courier or exam system sends to POST /api/whatsapp/ingest/event. No template is linked by default.
        'GIMT_DEMO_CLASS_REQUEST' => ['Demo class requested', 'A visitor asked for a free demo / trial class on a website enquiry form (hidden field form=demo). No GIMT page sends form=demo yet — add the hidden field to a demo-class form to use it. Uses the callback_requested template until a dedicated demo-class template is created.', 'enquiry', 'website', 'contact',
            $lead + ['form' => 'demo', 'preferred_date' => '08 Nov 2026'], $nLead],
        'GIMT_STUDENT_REGISTERED' => ['New student registration', 'A student signed up on an external admission / registration portal. GIMT\'s own online application has no separate sign-up step, so that portal fires this event through the API.', 'registration', 'api', 'contact',
            ['name' => 'Neha Rao', 'phone' => '+919700112233', 'email' => 'neha.rao@example.com', 'program_name' => 'Bachelor of Computer Applications', 'registration_id' => 'REG-2026-01842', 'portal_link' => 'https://gimtedu.com/apply'], ['student_name' => 'payload:name', 'course_name' => 'payload:program_name']],
        'GIMT_MOBILE_VERIFIED' => ['Mobile number verified', 'The registration portal verified the student\'s mobile number with an OTP (fired through the API after the OTP check; the OTP itself is sent with OTP verification).', 'registration', 'api', 'contact',
            ['name' => 'Neha Rao', 'phone' => '+919700112233', 'registration_id' => 'REG-2026-01842', 'verified_at' => '03 Nov 2026, 11:20 AM'], ['student_name' => 'payload:name']],
        'GIMT_REGISTRATION_SUCCESS' => ['Registration successful', 'Registration on the admission portal is complete — share the login and the next step (fired by the portal through the API).', 'registration', 'api', 'contact',
            ['name' => 'Neha Rao', 'phone' => '+919700112233', 'registration_id' => 'REG-2026-01842', 'login_link' => 'https://gimtedu.com/apply', 'next_step' => 'Complete your profile and upload documents'], ['student_name' => 'payload:name']],
        'GIMT_PROFILE_COMPLETED' => ['Profile completed', 'The student completed their profile on the admission portal (fired through the API).', 'registration', 'api', 'contact',
            ['name' => 'Neha Rao', 'phone' => '+919700112233', 'registration_id' => 'REG-2026-01842', 'program_name' => 'Bachelor of Computer Applications', 'next_step' => 'Pay the application fee and submit'], ['student_name' => 'payload:name', 'course_name' => 'payload:program_name']],
        'GIMT_APPLICATION_NUMBER_GENERATED' => ['Application number generated', 'An application number was allotted — fired with every online application (payload source "website") and every application added by staff. Off by default so it does not repeat "Application submitted"; to message only applications added by staff, add the condition source "not equals" website.', 'registration', 'admission_portal', 'contact',
            $applicant, $n + ['application_number' => 'payload:application_no']],
        'GIMT_APPLICATION_UNDER_REVIEW' => ['Application under review', 'The application moved to the Approval stage: the admission committee is reviewing it.', 'admission', 'admin', 'contact',
            $applicant + ['from_stage' => 'entrance_interview', 'to_stage' => 'approval', 'stage_label' => 'Approval', 'admission_status' => 'Under review'], $n + ['admission_status' => 'text:Under review']],
        'GIMT_COURSE_SELECTED' => ['Course selected', 'A visitor chooses a programme in the website apply wizard or course finder and agrees to WhatsApp updates (website JavaScript → POST /api/whatsapp/ingest/track with phone + consent). Not wired yet: no website page posts it, so it only fires once the website sends it.', 'course', 'js', 'contact',
            ['name' => 'Kavya Nair', 'phone' => '+919445566778', 'program_id' => 1, 'program_name' => 'Bachelor of Business Administration', 'course_duration' => '3 Years', 'page' => '/apply', 'consent' => true], $n],
        'GIMT_COURSE_UPDATED' => ['Course details updated', 'Fee, duration, eligibility, semesters or intake of a programme changed in Academics › Programs: opted-in leads and applicants interested in it are told (at most once a day).', 'course', 'admin', 'contact',
            ['program_id' => 3, 'program_name' => 'B.Tech in Computer Science & Engineering', 'course_name' => 'B.Tech in Computer Science & Engineering', 'changed_fields' => 'fee per year, eligibility', 'course_duration' => '4 Years', 'fee_amount' => '₹1,25,000 per year', 'course_link' => 'https://gimtedu.com/programs/btech-cse'], ['course_name' => 'payload:program_name', 'course_duration' => 'payload:course_duration', 'fee_amount' => 'payload:fee_amount']],
        'GIMT_CLASS_POSTPONED' => ['Class postponed', 'A class was moved to another time. GIMT timetables are weekly patterns without per-day class records, so the faculty app / LMS fires this through the API.', 'course', 'api', 'contact',
            $student + ['subject_name' => 'Data Structures', 'class_date' => '05 Nov 2026', 'class_time' => '10:00 AM', 'new_date' => '06 Nov 2026', 'new_time' => '02:00 PM', 'reason' => 'Faculty on official duty'], $n + ['subject_name' => 'payload:subject_name', 'class_date' => 'payload:new_date', 'class_time' => 'payload:new_time']],
        'GIMT_STUDY_MATERIAL_UPLOADED' => ['New study material uploaded', 'New notes or study material for a subject. GIMT has no LMS module, so the LMS fires this through the API.', 'course', 'api', 'contact',
            $student + ['subject_name' => 'Data Structures', 'material_title' => 'Unit 3 — Linked lists (notes)', 'material_link' => 'https://lms.gimtedu.com/materials/1842'], $n + ['subject_name' => 'payload:subject_name']],
        'GIMT_ASSIGNMENT_PUBLISHED' => ['Assignment published', 'A new assignment was published in the LMS (fired through the API — GIMT has no assignment module).', 'course', 'api', 'contact',
            $student + ['subject_name' => 'Database Management Systems', 'assignment_title' => 'ER diagram for a hospital system', 'due_date' => '12 Nov 2026', 'assignment_link' => 'https://lms.gimtedu.com/assignments/311'], $n + ['subject_name' => 'payload:subject_name', 'due_date' => 'payload:due_date']],
        'GIMT_ASSIGNMENT_REMINDER' => ['Assignment submission reminder', 'Reminder before an assignment deadline (fired by the LMS through the API).', 'course', 'api', 'contact',
            $student + ['subject_name' => 'Database Management Systems', 'assignment_title' => 'ER diagram for a hospital system', 'due_date' => '12 Nov 2026', 'hours_left' => 24], $n + ['subject_name' => 'payload:subject_name', 'due_date' => 'payload:due_date']],
        'GIMT_COURSE_COMPLETED' => ['Course completed', 'The student\'s status changed to Graduated or Alumni (programme completed): congratulate them and share the certificate / alumni next steps.', 'course', 'admin', 'contact',
            $student + ['old_status' => 'active', 'new_status' => 'graduated'], $n],
        'GIMT_APPLICATION_FEE_GENERATED' => ['Application fee generated', 'An application-fee demand was raised. GIMT\'s online application does not raise an application-fee invoice, so the admission portal or payment system fires this through the API.', 'payment', 'api', 'contact',
            $applicant + ['amount' => 500, 'amount_text' => '₹500', 'fee_amount' => '₹500', 'payment_link' => 'https://gimtedu.com/apply/pay/APP-2026-27-00412', 'due_date' => '10 Nov 2026'], $n + $fee + ['payment_link' => 'payload:payment_link']],
        'GIMT_EXAM_ANNOUNCED' => ['Examination announced', 'An examination was published in Exams (status moved out of Draft): its candidates get the dates.', 'exam', 'admin', 'contact',
            $student + $exam, $n + ['exam_name' => 'payload:exam_name', 'exam_date' => 'payload:start_date']],
        'GIMT_EXAM_REGISTRATION_OPEN' => ['Exam registration opened', 'Examination form filling / registration opened. GIMT allocates candidates automatically, so a university or exam portal fires this through the API.', 'exam', 'api', 'contact',
            $student + $exam + ['last_date' => '15 Nov 2026', 'registration_link' => 'https://exams.gimtedu.com/register'], $n + ['exam_name' => 'payload:exam_name', 'last_date' => 'payload:last_date']],
        'GIMT_EXAM_FEE_REMINDER' => ['Exam fee reminder', 'Reminder to pay an examination fee collected outside GIMT fees (API). Exam fees raised as GIMT fee invoices use the fee due / overdue reminders.', 'exam', 'api', 'contact',
            $student + $exam + ['amount' => 1800, 'amount_text' => '₹1,800', 'fee_amount' => '₹1,800', 'due_date' => '15 Nov 2026', 'payment_link' => 'https://exams.gimtedu.com/pay'], $n + $fee + ['exam_name' => 'payload:exam_name', 'due_date' => 'payload:due_date']],
        'GIMT_EXAM_POSTPONED' => ['Exam postponed / rescheduled', 'The dates of a published examination changed in Exams: candidates get the new dates.', 'exam', 'admin', 'contact',
            $student + $exam + ['old_start_date' => '20 Nov 2026', 'exam_date' => '25 Nov 2026', 'change' => 'postponed'], $n + ['exam_name' => 'payload:exam_name', 'exam_date' => 'payload:start_date']],
        'GIMT_EXAM_COMPLETED' => ['Examination completed', 'The day after the last paper of an examination: thank the candidates and share when results are expected (daily scan).', 'exam', 'cron', 'contact',
            $student + $exam + ['completed_on' => '07 Dec 2026', 'result_date' => '28 Dec 2026'], $n + ['exam_name' => 'payload:exam_name'], ['schedule' => ['time' => '10:00']]],
        'GIMT_MARKSHEET_APPROVED' => ['Marksheet approved', 'A marksheet was approved by the university / board. GIMT issues its marksheets when results are published (Marksheet generated), so an external approval system fires this through the API.', 'certificate', 'api', 'contact',
            $student + $exam + ['marksheet_no' => 'MS/2026-27/000412', 'marksheet_link' => 'https://gimtedu.com/portal/student'], $n + ['marksheet_link' => 'payload:marksheet_link']],
        'GIMT_CERTIFICATE_DISPATCHED' => ['Original certificate dispatched', 'The original certificate / degree was sent by courier. GIMT records no courier dispatch, so the dispatch desk or courier integration fires this through the API.', 'certificate', 'api', 'contact',
            $student + ['certificate_type' => 'Provisional Certificate', 'courier_name' => 'India Post (Speed Post)', 'tracking_number' => 'EE123456789IN', 'dispatch_date' => '04 Nov 2026', 'tracking_link' => 'https://www.indiapost.gov.in/'], $n + ['tracking_number' => 'payload:tracking_number']],
        'GIMT_DISPATCH_TRACKING' => ['Certificate dispatch tracking', 'Tracking update for a dispatched certificate (in transit, out for delivery, delivered) from the courier integration (API).', 'certificate', 'api', 'contact',
            $student + ['certificate_type' => 'Provisional Certificate', 'courier_name' => 'India Post (Speed Post)', 'tracking_number' => 'EE123456789IN', 'tracking_status' => 'Out for delivery', 'tracking_link' => 'https://www.indiapost.gov.in/'], $n + ['tracking_number' => 'payload:tracking_number']],
        'GIMT_FEEDBACK_RECEIVED' => ['Feedback received', 'A student or visitor submitted feedback (ticket type Feedback) in the student portal or Feedback & Complaints: thank them. It fires together with Support ticket created — switch on one of the two (or add the condition ticket_type "not equals" feedback to the ticket event).', 'support', 'admin', 'contact',
            $student + array_merge($ticket, ['ticket_type' => 'feedback', 'subject' => 'Library timings during exams', 'issue_summary' => 'Library timings during exams', 'rating' => 4]), $n + ['ticket_number' => 'payload:ticket_no']],
        'GIMT_SUPPORT_FOLLOWUP' => ['Support follow-up reminder', 'Daily digest to each staff member with assigned tickets that have had no update for N days.', 'support', 'cron', 'counsellor',
            ['counsellor_id' => 5, 'counsellor_name' => 'Anil Kumar', 'counsellor_phone' => '+919437012345', 'pending_count' => 3, 'ticket_numbers' => 'TKT-2026-00131, TKT-2026-00138, TKT-2026-00142', 'days_waiting' => 2, 'oldest_since' => '30 Oct 2026', 'date' => '03 Nov 2026', 'tickets_link' => 'https://gimtedu.com/admin/feedback'], ['counsellor_name' => 'payload:counsellor_name', 'pending_count' => 'payload:pending_count'], ['schedule' => ['days' => [2], 'time' => '10:00']]],
    ];

    $core = wa_evt_core_codes();
    $json = fn ($v) => $v === null || $v === [] ? null : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    db_transaction(function () use ($events, $core, $json, $hasSchedule) {
        $codes = array_keys($events);
        $place = implode(',', array_fill(0, count($codes), '?'));
        db_exec("DELETE FROM wa_events WHERE is_system = 1 AND code NOT IN ($place)", $codes);

        foreach ($events as $code => $e) {
            [$name, $desc, $cat, $source, $recipient, $sample, $map] = $e;
            $extra = $e[7] ?? [];
            $old = db_row('SELECT * FROM wa_events WHERE code = ?', [$code]);
            $tpl = db_row("SELECT id, status FROM wa_templates WHERE name = ? AND status <> 'archived' ORDER BY (status = 'approved') DESC, id LIMIT 1", [wa_evt_template_name($code)]);
            $activate = $tpl && wa_evt_linkable_status($tpl['status']) && in_array($code, $core, true);
            if (!$old) {
                $row = [
                    'code' => $code, 'name' => $name, 'description' => $desc, 'category' => $cat, 'source' => $source,
                    'recipient' => $recipient, 'variable_map' => $json($map), 'sample_payload' => $json($sample),
                    'delay_minutes' => (int) ($extra['delay'] ?? 0), 'respect_business_hours' => (int) ($extra['business'] ?? 0),
                    'template_id' => $tpl ? (int) $tpl['id'] : null, 'is_active' => $activate ? 1 : 0, 'is_system' => 1,
                ];
                if ($hasSchedule && !empty($extra['schedule'])) {
                    $row['schedule_config'] = $json($extra['schedule']);
                }
                db_insert('wa_events', $row);
                continue;
            }
            $upd = ['name' => $name, 'description' => $desc, 'category' => $cat, 'source' => $source, 'sample_payload' => $json($sample), 'is_system' => 1];
            // keep the admin's mapping; only fill an empty one (or one never edited since the catalogue wrote it)
            if (empty($old['variable_map']) || empty($old['updated_by'])) {
                $upd['variable_map'] = $json($map);
            }
            if ($hasSchedule && !empty($extra['schedule']) && empty($old['schedule_config'])) {
                $upd['schedule_config'] = $json($extra['schedule']);
            }
            if (empty($old['template_id']) && $tpl) {
                $upd['template_id'] = (int) $tpl['id'];
            }
            // catalogue default (core transactional events on) until an admin edits or toggles the event
            if (!(int) $old['is_active'] && $activate && empty($old['updated_by']) && (empty($old['template_id']) || (int) $old['template_id'] === (int) $tpl['id'])) {
                $upd['is_active'] = 1;
            }
            db_update('wa_events', $upd, 'id = ?', [(int) $old['id']]);
        }
    });
    // templates already existed (seed order 09_wa_1_templates → 09_wa_2_events, or a re-run): linking + activation is
    // done here, so the worker's one-time fallback (wa_evt_tick) must not run again later and override an admin
    if ((int) db_value('SELECT COUNT(*) FROM wa_templates') > 0 && function_exists('save_setting')) {
        save_setting('wa_events_autolinked', '1', 'whatsapp');
    }
};
