<?php
/**
 * WhatsApp template library — production-ready DRAFT templates for every built-in GIMT event (+ marketing campaigns
 * and the OTP template). Template name = event code lowercased without "GIMT_" (GIMT_PAYMENT_SUCCESS → payment_success)
 * so the event engine finds them by name. Written in "named" form and compiled by wa_tpl_compile() into the stored
 * Meta-compliant positional form ({{1}}..{{n}} + variables mapping).
 *
 * Every brief event that GIMT code fires has a starter UTILITY template here, including the staff alerts sent to a
 * counsellor's / staff member's own mobile (followup_pending, lead_transferred, support_followup, ticket_assigned_staff
 * — the recipient phone comes from Users › phone). They are created as drafts: an admin reviews and submits them to
 * Meta from the Template Library; events using them stay inactive until an admin switches them on (the demo seed
 * 98_wa_0_templates approves them in test mode and switches a few on).
 *
 * Re-runnable and id-stable: upserts by (name, language). Rows that are no longer drafts (submitted / approved /
 * imported) are left untouched apart from the description, so a re-run never undoes an Interakt approval.
 */
return function (array $opts): void {
    // core first: it loads every feature service and registers their hooks (loading this one alone would skip them)
    require_once APP_ROOT . '/app/services/whatsapp.php';
    require_once APP_ROOT . '/app/services/whatsapp_templates.php';

    $cfgUrl = (string) (app_config('app')['url'] ?? '');
    $site = preg_match('#^https://#', $cfgUrl) && !preg_match('#localhost|127\.0\.0\.1#', $cfgUrl) ? rtrim($cfgUrl, '/') : 'https://gimtedu.com';
    $phone = wa_tpl_phone((string) setting('phone', '+91 90600 31540'));
    if (!preg_match('/^\+[1-9]\d{7,14}$/', $phone)) {
        $phone = '+919060031540';
    }

    // Shared samples (Meta reviews every template with sample values)
    $S = [
        'student_name' => 'Rahul Sharma',
        'application_number' => 'GIMT-APP-2026-00148',
        'course_name' => 'BBA (Business Analytics)',
        'course_duration' => '3 years',
        'admission_status' => 'Under review',
        'fee_amount' => 'Rs. 45,000',
        'payment_status' => 'Paid',
        'payment_date' => '03 Nov 2026',
        'receipt_link' => $site . '/verify/RCPT-2026-00412',
        'receipt_number' => 'RCPT-2026-00412',
        'exam_date' => '15 Dec 2026',
        'class_date' => '10 Nov 2026',
        'class_time' => '10:00 AM',
        'certificate_link' => $site . '/verify-certificate/GIMT-CERT-2026-0091',
        'support_number' => '+91 90600 31540',
        'institute_name' => 'Global Institute of Management & Technology',
        'counselling_date' => '06 Nov 2026',
        'counselling_time' => '11:30 AM',
        'document_list' => 'Class 12 mark sheet, Aadhaar card',
        'remarks' => 'The Class 12 mark sheet image is blurred',
        'last_date' => '20 Nov 2026',
        'due_date' => '15 Nov 2026',
        'payment_link' => $site . '/pay/GIMT8Q2K7',
        'refund_amount' => 'Rs. 12,500',
        'transaction_id' => 'RFND-2026-0032',
        'subject_name' => 'Financial Accounting',
        'exam_name' => 'Semester 1 End-Term Examination',
        'admit_card_link' => $site . '/student-portal/admit-card',
        'result_link' => $site . '/student-portal/results',
        'marksheet_link' => $site . '/student-portal/marksheets',
        'ticket_number' => 'TKT-2026-0187',
        'issue_summary' => 'Unable to download fee receipt',
        'notice_title' => 'Revised timetable for Semester 1',
        'notice_link' => $site . '/notices',
        'holiday_name' => 'Guru Nanak Jayanti',
        'holiday_date' => '24 Nov 2026',
        'reopen_date' => '25 Nov 2026',
        'brochure_link' => $site . '/programs',
        'academic_session' => '2026-27',
        'festival_name' => 'Diwali',
        'event_name' => 'Industry Connect Summit 2026',
        'event_date' => '28 Nov 2026',
        'event_venue' => 'GIMT Auditorium, Noida',
        'otp_code' => '482913',
        // starter templates of the remaining brief events (keys = what the event payload / contact provides)
        'name' => 'Sneha Patel',
        'subject' => 'Hostel availability for first-year students',
        'counsellor_name' => 'Pooja Malhotra',
        'pending_count' => '8',
        'due_today' => '6',
        'overdue' => '2',
        'student_phone' => '+91 91234 56780',
        'offer_letter_no' => 'GIMT/OL/2026-27/0087',
        'student_id_no' => 'GIMT26BBA041',
        'batch_name' => 'BBA 2026-29',
        'changed_fields' => 'fee, eligibility',
        'course_link' => $site . '/programs/bba',
        'certificate_type' => 'Bonafide Certificate',
        'certificate_no' => 'GIMT/BON/2026/0088',
        'assignee_name' => 'Ravi Agarwal',
        'ticket_numbers' => 'TKT-2026-0131, TKT-2026-0138',
        'days_waiting' => '2',
        'new_status' => 'Graduated',
    ];

    $portal = ['type' => 'URL', 'text' => 'Open student portal', 'url' => $site . '/student-portal'];
    $call = fn (string $text = 'Call us') => ['type' => 'PHONE_NUMBER', 'text' => $text, 'phone_number' => $phone];

    $T = [
        // ------------------------------------------------------------------------------------------ enquiry
        ['enquiry_received', 'Enquiry received', 'Acknowledges a website / landing-page enquiry and sets the callback expectation.', 'UTILITY',
            ['text', 'We received your enquiry'],
            "Dear {{student_name}},\n\nThank you for your enquiry about *{{course_name}}* at {{institute_name}}. Our admissions counsellor will call you within one working day to guide you on eligibility, fees and the admission process.\n\nFor anything urgent, call or WhatsApp us on {{support_number}}.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'View programmes', 'url' => $site . '/programs'], $call('Call admissions')]],
        ['callback_requested', 'Callback requested', 'Confirms a callback request and lets the lead pick a time slot.', 'UTILITY',
            ['none', ''],
            "Hello {{student_name}}, we have received your callback request for *{{course_name}}*.\n\nA counsellor from {{institute_name}} will call you on this number shortly. If you prefer a particular time, tap an option below or reply to this message.",
            'GIMT Admissions Cell', 'quick_reply', [['type' => 'QUICK_REPLY', 'text' => 'Call me today'], ['type' => 'QUICK_REPLY', 'text' => 'Call me tomorrow'], ['type' => 'QUICK_REPLY', 'text' => 'Call in the evening']]],
        ['brochure_requested', 'Brochure requested', 'Sends the programme brochure link after a brochure download request.', 'UTILITY',
            ['text', 'Your programme brochure'],
            "Dear {{student_name}}, as requested, here is the brochure for *{{course_name}}* at {{institute_name}}. It covers the curriculum, eligibility, fee structure and placement support.\n\nView it here: {{brochure_link}}\n\nReply to this message if you have any questions.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Start application', 'url' => $site . '/apply'], $call('Talk to a counsellor')]],
        ['counselling_scheduled', 'Counselling scheduled', 'Confirms the date and time of an admission counselling session.', 'UTILITY',
            ['text', 'Counselling session confirmed'],
            "Dear {{student_name}}, your admission counselling session for *{{course_name}}* is confirmed for *{{counselling_date}}* at *{{counselling_time}}*.\n\nPlease keep your latest mark sheets handy. To change the slot, tap Reschedule or reply to this message.",
            'GIMT Admissions Cell', 'quick_reply', [['type' => 'QUICK_REPLY', 'text' => 'I will attend'], ['type' => 'QUICK_REPLY', 'text' => 'Reschedule']]],

        // ------------------------------------------------------------------------------------------ application
        ['application_submitted', 'Application submitted', 'Confirms an online application with its application number.', 'UTILITY',
            ['text', 'Application received'],
            "Dear {{student_name}}, your application for *{{course_name}}* has been submitted successfully.\n\nApplication number: *{{application_number}}*\nCurrent status: {{admission_status}}\n\nPlease quote your application number in all communication. We will keep you updated here at every step.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Track application', 'url' => $site . '/apply']]],
        ['application_incomplete', 'Application incomplete', 'Reminds an applicant to finish a partially filled application.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your application {{application_number}} for *{{course_name}}* is not complete yet.\n\nPlease log in and finish the remaining steps before {{last_date}} so that our team can review it. Need help? Call us on {{support_number}}.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Complete application', 'url' => $site . '/apply']]],
        ['documents_pending', 'Documents pending', 'Lists the documents still missing from an application.', 'UTILITY',
            ['text', 'Documents pending'],
            "Dear {{student_name}}, we are still waiting for these documents for application {{application_number}}:\n{{document_list}}\n\nPlease upload them by {{last_date}} to avoid any delay in your admission.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Upload documents', 'url' => $site . '/apply']]],
        ['documents_approved', 'Documents verified', 'Tells the applicant that all uploaded documents were verified.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, the documents submitted with application {{application_number}} have been verified by our admissions team.\n\nYour application for *{{course_name}}* now moves to the next stage. We will message you here with the next steps.",
            'GIMT Admissions Cell', 'none', []],
        ['documents_rejected', 'Documents need re-upload', 'Explains why a document was rejected and asks for a fresh copy.', 'UTILITY',
            ['text', 'Action needed on your documents'],
            "Dear {{student_name}}, some documents submitted with application {{application_number}} could not be verified.\n\nReason: {{remarks}}\n\nPlease upload a clear, valid copy by {{last_date}}. Call {{support_number}} if you need any help.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Re-upload documents', 'url' => $site . '/apply']]],

        // ------------------------------------------------------------------------------------------ admission
        ['admission_approved', 'Admission approved', 'Offer of admission with the fee to pay and the deadline.', 'UTILITY',
            ['text', 'Admission approved'],
            "Dear {{student_name}}, your admission to *{{course_name}}* ({{course_duration}}) at {{institute_name}} has been approved.\n\nTo confirm your seat, please pay the admission fee of *{{fee_amount}}* by {{last_date}}. Your application number is {{application_number}}.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Pay admission fee', 'url' => $site . '/apply'], $call('Call admissions')]],
        ['admission_confirmed', 'Admission confirmed', 'Welcome message once the admission is confirmed.', 'UTILITY',
            ['text', 'Admission confirmed'],
            "Dear {{student_name}}, your admission to *{{course_name}}* at {{institute_name}} is confirmed.\n\nApplication number: {{application_number}}\nFee status: {{payment_status}}\n\nYour orientation schedule and student portal login will be shared with you soon. We look forward to welcoming you on campus.",
            'GIMT Admissions Cell', 'cta', [$portal]],
        ['admission_rejected', 'Admission not offered', 'Polite decline with an offer to suggest other programmes.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, thank you for applying to *{{course_name}}* at {{institute_name}}. After careful review, we are unable to proceed with your admission for this intake (application {{application_number}}).\n\nOur counsellors can suggest other programmes that match your profile. Call us on {{support_number}}.",
            'GIMT Admissions Cell', 'none', []],
        ['admission_deadline_reminder', 'Admission deadline reminder', 'Reminds an applicant of the last date to complete admission formalities.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, this is a reminder that the last date to complete your admission for *{{course_name}}* is *{{last_date}}*.\n\nApplication number: {{application_number}}. Please complete the pending steps before the deadline.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Complete admission', 'url' => $site . '/apply'], $call('Call admissions')]],

        // ------------------------------------------------------------------------------------------ fees / payments
        ['payment_link_generated', 'Payment link generated', 'Sends a secure fee payment link with its validity.', 'UTILITY',
            ['text', 'Fee payment link'],
            "Dear {{student_name}}, a payment link for *{{fee_amount}}* has been generated for {{course_name}}.\n\nPay securely here: {{payment_link}}\n\nThe link is valid until {{due_date}}. You will receive the receipt on WhatsApp as soon as the payment is successful.",
            'GIMT Accounts Office', 'none', []],
        ['payment_success', 'Payment successful', 'Confirms a fee payment with the receipt number and link.', 'UTILITY',
            ['text', 'Payment received'],
            "Dear {{student_name}}, we have received your payment of *{{fee_amount}}* on {{payment_date}}.\n\nReceipt number: {{receipt_number}}\nDownload receipt: {{receipt_link}}\n\nThank you. Please keep this message for your records.",
            'GIMT Accounts Office', 'none', []],
        ['payment_failed', 'Payment failed', 'Tells the payer that an online payment failed and how to retry.', 'UTILITY',
            ['text', 'Payment not completed'],
            "Dear {{student_name}}, your payment of {{fee_amount}} could not be completed. If money was debited from your account, it is usually reversed by the bank within 5 to 7 working days.\n\nPlease try again using {{payment_link}} or call our accounts office on {{support_number}}.",
            'GIMT Accounts Office', 'none', []],
        ['fee_receipt_generated', 'Fee receipt generated', 'Shares the fee receipt download link.', 'UTILITY',
            ['text', 'Fee receipt'],
            "Dear {{student_name}}, your fee receipt *{{receipt_number}}* for {{fee_amount}} dated {{payment_date}} is ready.\n\nDownload: {{receipt_link}}\n\nPlease keep it safe for future reference.",
            'GIMT Accounts Office', 'none', []],
        ['fee_due_reminder', 'Fee due reminder', 'Reminds a student about an upcoming fee due date.', 'UTILITY',
            ['text', 'Fee due reminder'],
            "Dear {{student_name}}, this is a reminder that your fee of *{{fee_amount}}* for {{course_name}} is due on *{{due_date}}*.\n\nPay online: {{payment_link}}\n\nPlease ignore this message if you have already paid.",
            'GIMT Accounts Office', 'cta', [$call('Call accounts office')]],
        ['installment_overdue', 'Instalment overdue', 'Escalation when a fee instalment is past its due date.', 'UTILITY',
            ['text', 'Fee instalment overdue'],
            "Dear {{student_name}}, your fee instalment of *{{fee_amount}}* was due on {{due_date}} and is now overdue. Please pay at the earliest to avoid a late fee and a hold on exam registration.\n\nPay online: {{payment_link}}\n\nFor any query, call our accounts office on {{support_number}}.",
            'GIMT Accounts Office', 'none', []],
        ['refund_initiated', 'Refund initiated', 'Confirms that a refund has been initiated.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your refund of *{{refund_amount}}* was initiated on {{payment_date}} (reference {{transaction_id}}).\n\nThe amount should reach your bank account within 7 to 10 working days.",
            'GIMT Accounts Office', 'none', []],
        ['refund_completed', 'Refund completed', 'Confirms that a refund has been processed.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your refund of *{{refund_amount}}* has been processed (reference {{transaction_id}}). Please check your bank account.\n\nIf you have not received it within 3 working days, call us on {{support_number}}.",
            'GIMT Accounts Office', 'none', []],

        // ------------------------------------------------------------------------------------------ academics
        ['class_schedule_published', 'Class schedule published', 'Announces that the timetable is available on the portal.', 'UTILITY',
            ['text', 'Class schedule published'],
            "Dear {{student_name}}, the class schedule for *{{course_name}}* is now available on the student portal. Classes begin on {{class_date}}.\n\nPlease check your timetable and be on time for the first lecture.",
            'GIMT Academic Office', 'cta', [$portal]],
        ['class_reminder', 'Class reminder', 'Reminder before a scheduled class.', 'UTILITY',
            ['none', ''],
            "Hello {{student_name}}, a reminder that your *{{subject_name}}* class is scheduled for {{class_date}} at {{class_time}}. Please be on time.",
            'GIMT Academic Office', 'none', []],
        ['class_cancelled', 'Class cancelled', 'Tells students that a class is cancelled.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, the *{{subject_name}}* class scheduled for {{class_date}} has been cancelled. The new date will be shared on the student portal.\n\nSorry for the inconvenience.",
            'GIMT Academic Office', 'none', []],

        // ------------------------------------------------------------------------------------------ exams / results
        ['exam_schedule_released', 'Exam schedule released', 'Announces the examination date sheet.', 'UTILITY',
            ['text', 'Exam schedule released'],
            "Dear {{student_name}}, the schedule for *{{exam_name}}* has been released. Exams begin on {{exam_date}}.\n\nCheck the full date sheet on the student portal and clear any pending dues to receive your admit card.",
            'GIMT Examination Cell', 'cta', [['type' => 'URL', 'text' => 'View date sheet', 'url' => $site . '/student-portal']]],
        ['admit_card_generated', 'Admit card generated', 'Shares the admit card download link.', 'UTILITY',
            ['text', 'Admit card ready'],
            "Dear {{student_name}}, your admit card for *{{exam_name}}* is ready. Download it here: {{admit_card_link}}\n\nCarry a printed copy and your college ID card to the exam hall. Your first exam is on {{exam_date}}.",
            'GIMT Examination Cell', 'none', []],
        ['exam_reminder', 'Exam reminder', 'Reminder before an examination.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, a reminder that your *{{exam_name}}* exam is on {{exam_date}}. Please reach the exam hall 30 minutes early with your admit card and college ID.\n\nAll the best!",
            'GIMT Examination Cell', 'none', []],
        ['result_published', 'Result published', 'Announces a result with the link to view it.', 'UTILITY',
            ['text', 'Result declared'],
            "Dear {{student_name}}, the result of *{{exam_name}}* has been published. View your result here: {{result_link}}\n\nTo apply for re-evaluation, use the student portal within 7 days.",
            'GIMT Examination Cell', 'none', []],
        ['certificate_ready', 'Certificate ready', 'Certificate issued — download / verification link.', 'UTILITY',
            ['text', 'Your certificate is ready'],
            "Dear {{student_name}}, your certificate for *{{course_name}}* is ready. Download or verify it here: {{certificate_link}}\n\nYou can also collect the original from the examination cell with your college ID.",
            'GIMT Examination Cell', 'none', []],
        ['marksheet_generated', 'Mark sheet generated', 'Mark sheet available for download.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your mark sheet for *{{exam_name}}* has been generated. Download it here: {{marksheet_link}}\n\nPlease report any discrepancy to the examination cell within 7 days.",
            'GIMT Examination Cell', 'none', []],

        // ------------------------------------------------------------------------------------------ support
        ['ticket_created', 'Support ticket created', 'Acknowledges a helpdesk ticket with its number.', 'UTILITY',
            ['none', ''],
            "Hello {{student_name}}, your support request has been registered.\n\nTicket number: *{{ticket_number}}*\nSubject: {{issue_summary}}\n\nOur team will get back to you within one working day. Reply to this message to add more details.",
            'GIMT Student Support', 'none', []],
        ['complaint_resolved', 'Complaint resolved', 'Tells the student a ticket is resolved and allows reopening.', 'UTILITY',
            ['none', ''],
            "Hello {{student_name}}, your ticket *{{ticket_number}}* has been resolved.\n\nIf the issue is not fixed, tap the button below or reply within 3 days and we will reopen it.",
            'GIMT Student Support', 'quick_reply', [['type' => 'QUICK_REPLY', 'text' => 'Issue resolved'], ['type' => 'QUICK_REPLY', 'text' => 'Still facing issue']]],
        ['feedback_request', 'Feedback request', 'Asks for a quick rating after a ticket is closed.', 'UTILITY',
            ['none', ''],
            "Hello {{student_name}}, thank you for contacting {{institute_name}}. How was your experience with ticket {{ticket_number}}?\n\nPlease tap an option below — it takes just a second.",
            'GIMT Student Support', 'quick_reply', [['type' => 'QUICK_REPLY', 'text' => 'Excellent'], ['type' => 'QUICK_REPLY', 'text' => 'Good'], ['type' => 'QUICK_REPLY', 'text' => 'Needs improvement']]],

        // ------------------------------------------------------------------------------------------ notices
        ['notice_published', 'Notice published', 'Alerts students about a new notice.', 'UTILITY',
            ['text', 'New notice from GIMT'],
            "Dear {{student_name}}, a new notice has been published: *{{notice_title}}*.\n\nRead the full notice here: {{notice_link}}\n\nPlease read it carefully and follow the instructions.",
            'GIMT Administration', 'none', []],
        ['holiday_notification', 'Holiday notification', 'Informs students about an upcoming holiday.', 'UTILITY',
            ['text', 'Holiday notice'],
            "Dear {{student_name}}, the institute will remain closed on {{holiday_date}} on account of {{holiday_name}}.\n\nClasses will resume on {{reopen_date}} as per the regular timetable.",
            'GIMT Administration', 'none', []],

        // ------------------------------------------------------------------------------------------ authentication
        ['otp_verification', 'OTP verification', 'One-time password for portal login and phone verification.', 'AUTHENTICATION',
            ['none', ''], '', null, 'none', [],
            ['code_expiration_minutes' => 10, 'add_security_recommendation' => true, 'otp_type' => 'COPY_CODE', 'button_text' => 'Copy code']],

        // ------------------------------------------------------------------------------------------ marketing
        ['admission_open_campaign', 'Admissions open', 'Admissions-open announcement for leads and enquirers (opted-in contacts only).', 'MARKETING',
            ['text', 'Admissions open for {{academic_session}}'],
            "Hello {{student_name}},\n\nAdmissions are now open at {{institute_name}} for *{{course_name}}*. Learn from experienced faculty, work on live industry projects and get dedicated placement support.\n\nTalk to a counsellor today to check your eligibility.",
            'Reply STOP to unsubscribe', 'cta', [['type' => 'URL', 'text' => 'Apply now', 'url' => $site . '/apply'], $call('Talk to a counsellor')]],
        ['scholarship_announcement', 'Scholarship announcement', 'Announces merit / need-based scholarships for the session.', 'MARKETING',
            ['text', 'Scholarships for {{academic_session}}'],
            "Hello {{student_name}}, merit and need-based scholarships are available for *{{course_name}}* students at {{institute_name}} this session.\n\nCheck your eligibility and apply before {{last_date}}. Our counsellors will help you with the documents.",
            'Reply STOP to unsubscribe', 'cta', [['type' => 'URL', 'text' => 'Check eligibility', 'url' => $site . '/admissions'], $call('Call admissions')]],
        ['admission_closing_reminder', 'Admissions closing', 'Last-date reminder for leads who have not applied yet.', 'MARKETING',
            ['none', ''],
            "Hello {{student_name}}, admissions for *{{course_name}}* at {{institute_name}} close on {{last_date}}.\n\nComplete your application before the deadline to be considered for the {{academic_session}} batch, or talk to a counsellor if you need help.",
            'Reply STOP to unsubscribe', 'cta', [['type' => 'URL', 'text' => 'Apply now', 'url' => $site . '/apply'], $call('Talk to a counsellor')]],
        ['festival_greeting', 'Festival greeting', 'Festival wishes to students, parents and alumni.', 'MARKETING',
            ['none', ''],
            "Dear {{student_name}}, warm wishes to you and your family on {{festival_name}} from everyone at {{institute_name}}.\n\nMay this festival bring you joy, good health and success.",
            'Reply STOP to unsubscribe', 'none', []],
        ['event_invitation', 'Event invitation', 'Invitation to a campus event, seminar or workshop.', 'MARKETING',
            ['text', 'You are invited'],
            "Hello {{student_name}}, you are invited to *{{event_name}}* at {{institute_name}} on {{event_date}}.\n\nVenue: {{event_venue}}\n\nRegister now to reserve your place.",
            'Reply STOP to unsubscribe', 'cta', [['type' => 'URL', 'text' => 'Register', 'url' => $site . '/events']]],
        ['alumni_meetup', 'Alumni meetup', 'Invitation to the alumni meet.', 'MARKETING',
            ['text', 'GIMT Alumni Meet {{academic_session}}'],
            "Dear {{student_name}}, it is time to reconnect! The {{institute_name}} Alumni Meet is on {{event_date}} at {{event_venue}}.\n\nMeet your batchmates and faculty, and hear from alumni leading teams across industries. We would love to see you there.",
            'Reply STOP to unsubscribe', 'quick_reply', [['type' => 'QUICK_REPLY', 'text' => 'I will attend'], ['type' => 'QUICK_REPLY', 'text' => 'Cannot make it']]],

        // ================================================================== starter templates for the remaining brief events
        // (appended so the ids above stay stable). Each uses only keys its event payload provides after wa_evt_enrich()
        // or the recipient contact provides (wa_variables_for_contact). Events that fire together with another active
        // event (offer letter + admission approved, enrolled + admission confirmed …) are not switched on by default.

        // ------------------------------------------------------------------------------------------ enquiries
        ['contact_form', 'Contact form received', 'Acknowledges a message sent from the website Contact Us page or the recruiter form.', 'UTILITY',
            ['none', ''],
            "Hello {{name}}, thank you for writing to {{institute_name}}. We have received your message about *{{subject}}* and the right team will reply to you soon.\n\nFor anything urgent, please call us on {{support_number}}.",
            'GIMT Administration', 'none', []],
        ['enquiry_assigned', 'Counsellor assigned (lead)', 'Introduces the admission counsellor assigned to an enquiry.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, {{counsellor_name}} from the {{institute_name}} admissions team is now your admission counsellor and will help you with eligibility, fees and the admission process.\n\nYou can reply to this message with any question, or call us on {{support_number}}.",
            'GIMT Admissions Cell', 'none', []],
        // staff alerts: sent to the counsellor's own mobile (Users › phone)
        ['followup_pending', 'Follow-ups pending (staff alert)', 'Daily digest to a counsellor: admission follow-ups due today and overdue.', 'UTILITY',
            ['text', 'Your admission follow-ups'],
            "Hello {{counsellor_name}}, you have *{{pending_count}}* admission follow-ups waiting: {{due_today}} due today and {{overdue}} overdue.\n\nPlease call these leads and record the outcome in GIMT ERP under Admissions.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Open follow-ups', 'url' => $site . '/admin/admissions']]],
        ['lead_transferred', 'Lead assigned (staff alert)', 'Alerts a counsellor that an enquiry or application was assigned to them.', 'UTILITY',
            ['text', 'New lead assigned to you'],
            "Hello {{counsellor_name}}, a lead has been assigned to you in GIMT ERP.\n\nName: *{{student_name}}*\nMobile: {{student_phone}}\n\nPlease contact them and record the follow-up in the ERP.",
            'GIMT Admissions Cell', 'cta', [['type' => 'URL', 'text' => 'Open GIMT ERP', 'url' => $site . '/admin/']]],

        // ------------------------------------------------------------------------------------------ application
        ['application_number_generated', 'Application number allotted', 'Shares the application number allotted to a new application.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your application for *{{course_name}}* at {{institute_name}} has been registered.\n\nYour application number is *{{application_number}}*. Please quote it whenever you contact the admissions office.",
            'GIMT Admissions Cell', 'none', []],
        ['application_assigned', 'Counsellor assigned (applicant)', 'Introduces the admission counsellor assigned to an application.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, {{counsellor_name}} from the {{institute_name}} admissions team will now guide you through your application {{application_number}} for *{{course_name}}*.\n\nReply to this message if you have any question about documents, fees or the next steps.",
            'GIMT Admissions Cell', 'none', []],

        // ------------------------------------------------------------------------------------------ admission
        ['application_under_review', 'Application under review', 'Tells the applicant that the admission committee is reviewing the application.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your application {{application_number}} for *{{course_name}}* is now with the admission committee for review.\n\nYou will be informed as soon as a decision is made.",
            'GIMT Admissions Cell', 'none', []],
        ['offer_letter_generated', 'Offer letter issued', 'Shares the offer letter number issued for an approved application.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your offer letter for *{{course_name}}* at {{institute_name}} has been issued.\n\nOffer letter number: *{{offer_letter_no}}*\nApplication number: {{application_number}}\n\nPlease keep these numbers for your records and quote them when you contact the admissions office.",
            'GIMT Admissions Cell', 'none', []],
        ['admission_stage_changed', 'Application status update', 'Generic update when an application moves to another admission stage.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, there is an update on your application {{application_number}} for *{{course_name}}*.\n\nCurrent status: *{{admission_status}}*\n\nFor any question, please call the admissions office on {{support_number}}.",
            'GIMT Admissions Cell', 'none', []],

        // ------------------------------------------------------------------------------------------ fees / payments
        ['payment_pending', 'Payment pending', 'Reminds a payer whose online payment link is still unpaid.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your online payment of *{{fee_amount}}* has not been completed yet.\n\nIf you still wish to pay, please use this secure link: {{payment_link}}\n\nPlease ignore this message if you have already paid.",
            'GIMT Accounts Office', 'none', []],

        // ------------------------------------------------------------------------------------------ courses / academics
        ['student_enrolled', 'Student enrolled (welcome)', 'Welcome message with the student ID when a student record is created.', 'UTILITY',
            ['text', 'Welcome to GIMT'],
            "Dear {{student_name}}, welcome to {{institute_name}}. You are now enrolled in *{{course_name}}*.\n\nYour student ID is *{{student_id_no}}*. Please quote it in all communication with the institute.",
            'GIMT Academic Office', 'cta', [$portal]],
        ['batch_allocated', 'Batch allocated', 'Tells a student which batch they were allocated to.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, you have been allocated to batch *{{batch_name}}* of {{course_name}} at {{institute_name}}.\n\nYou can see your batch details on the student portal.",
            'GIMT Academic Office', 'cta', [$portal]],
        ['course_updated', 'Programme details updated', 'Tells opted-in leads and applicants that details of their programme of interest changed.', 'UTILITY',
            ['none', ''],
            "Hello, this is {{institute_name}}. The details of *{{course_name}}*, the programme you are interested in, have been updated: {{changed_fields}}.\n\nSee the current details here: {{course_link}}\n\nReply to this message if you have any questions.",
            'GIMT Admissions Cell', 'none', []],
        ['course_completed', 'Course completed', 'Congratulates a student whose status changed to passed out / alumni.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, congratulations on completing *{{course_name}}* at {{institute_name}}. We wish you every success in the years ahead.\n\nFor certificates, transcripts or other documents, reply to this message or call us on {{support_number}}.",
            'GIMT Administration', 'none', []],

        // ------------------------------------------------------------------------------------------ exams / results
        ['exam_announced', 'Examination announced', 'Tells the candidates that an examination was announced, with its start date.', 'UTILITY',
            ['text', 'Examination announced'],
            "Dear {{student_name}}, *{{exam_name}}* has been announced. The examination begins on {{exam_date}}.\n\nPlease check the student portal for details as they are published.",
            'GIMT Examination Cell', 'cta', [$portal]],
        ['exam_postponed', 'Examination dates changed', 'Tells the candidates that the dates of an announced examination were revised.', 'UTILITY',
            ['text', 'Examination dates changed'],
            "Dear {{student_name}}, the dates of *{{exam_name}}* have been revised. As per the revised schedule, the examination begins on *{{exam_date}}*.\n\nPlease check the full revised date sheet on the student portal.",
            'GIMT Examination Cell', 'cta', [$portal]],
        ['exam_completed', 'Examination completed', 'Thanks the candidates the day after the last paper of an examination.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, *{{exam_name}}* is now complete. Thank you for your cooperation during the examinations.\n\nResults will be published on the student portal.",
            'GIMT Examination Cell', 'none', []],
        ['marks_updated', 'Marks recorded', 'Tells a student that the marks of an examination were verified (before the result is published).', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your marks for *{{exam_name}}* have been verified and recorded by the examination cell.\n\nYour result will be available on the student portal once it is published.",
            'GIMT Examination Cell', 'none', []],

        // ------------------------------------------------------------------------------------------ certificates
        ['certificate_generated', 'Certificate prepared', 'Tells a student that a certificate was prepared and is waiting for approval.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your *{{certificate_type}}* (number {{certificate_no}}) has been prepared and is awaiting approval.\n\nYou will be informed as soon as it is approved and ready.",
            'GIMT Administration', 'none', []],
        ['certificate_approved', 'Certificate approved', 'Tells a student that a certificate was approved, with the verification link.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your *{{certificate_type}}* has been approved.\n\nCertificate number: {{certificate_no}}\nVerify it online: {{certificate_link}}\n\nPlease keep this message for your records.",
            'GIMT Administration', 'none', []],

        // ------------------------------------------------------------------------------------------ support
        ['complaint_registered', 'Hostel complaint registered', 'Acknowledges a hostel complaint raised from the student portal.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your hostel complaint has been registered.\n\nComplaint number: *{{ticket_number}}*\nIssue: {{issue_summary}}\n\nThe hostel office will look into it. Please quote the complaint number when you follow up.",
            'GIMT Hostel Office', 'none', []],
        ['ticket_assigned', 'Ticket assigned (student)', 'Tells the student that a staff member is now handling the ticket.', 'UTILITY',
            ['none', ''],
            "Hello {{student_name}}, your ticket *{{ticket_number}}* about {{issue_summary}} has been assigned to {{assignee_name}} from our team.\n\nYou will receive an update when it is resolved.",
            'GIMT Student Support', 'none', []],
        ['feedback_received', 'Feedback received', 'Thanks a student or visitor for feedback submitted in the portal or the ERP.', 'UTILITY',
            ['none', ''],
            "Hello {{student_name}}, thank you for your feedback about *{{issue_summary}}* (reference {{ticket_number}}).\n\nWe have shared it with the team concerned at {{institute_name}}.",
            'GIMT Student Support', 'none', []],
        ['support_followup', 'Tickets waiting (staff alert)', 'Daily digest to a staff member whose assigned tickets have had no update for some days.', 'UTILITY',
            ['text', 'Tickets waiting for an update'],
            "Hello {{counsellor_name}}, {{pending_count}} support tickets assigned to you have had no update for {{days_waiting}} days or more: {{ticket_numbers}}.\n\nPlease update or resolve them in GIMT ERP under Feedback & Complaints.",
            'GIMT Student Support', 'cta', [['type' => 'URL', 'text' => 'Open tickets', 'url' => $site . '/admin/feedback']]],
        ['ticket_assigned_staff', 'Ticket assigned (staff alert)', 'Alerts a staff member that a support ticket was assigned to them.', 'UTILITY',
            ['text', 'Ticket assigned to you'],
            "Hello {{counsellor_name}}, support ticket *{{ticket_number}}* has been assigned to you.\n\nSubject: {{issue_summary}}\n\nPlease review it in GIMT ERP under Feedback & Complaints.",
            'GIMT Student Support', 'cta', [['type' => 'URL', 'text' => 'Open tickets', 'url' => $site . '/admin/feedback']]],

        // ------------------------------------------------------------------------------------------ staff & admin
        ['student_status_changed', 'Student status changed', 'Tells a student that their status in the student record changed.', 'UTILITY',
            ['none', ''],
            "Dear {{student_name}}, your student record at {{institute_name}} has been updated. Your status is now *{{new_status}}*.\n\nFor any question about this change, please contact the administration office on {{support_number}}.",
            'GIMT Administration', 'none', []],
        // GIMT_EVENT_REMINDER: the daily scan (wa_evt_scan_event_reminder) messages every registration of a published
        // campus event starting the next day (name, title, start date + time, venue). The date is written out, never
        // "tomorrow", so a message delivered after midnight is still correct.
        ['event_reminder', 'Event reminder', 'Reminds a registered participant of a campus event the day before it starts.', 'UTILITY',
            ['text', 'Event reminder'],
            "Dear {{student_name}}, this is a reminder that *{{event_name}}* at {{institute_name}} is scheduled for {{event_date}}.\n\nVenue: {{event_venue}}\n\nWe look forward to seeing you. If you can no longer attend, please reply to this message.",
            'GIMT Administration', 'none', []],
    ];

    $created = 0;
    $updated = 0;
    foreach ($T as $def) {
        [$name, $display, $desc, $cat, $header, $body, $footer, $btnType, $buttons] = $def;
        $otp = $def[9] ?? null;
        $existing = db_row('SELECT id, status FROM wa_templates WHERE name = ? AND language = ?', [$name, 'en']);
        if ($existing && $existing['status'] !== 'draft') {
            db_update('wa_templates', ['description' => $desc, 'is_system' => 1], 'id = ?', [$existing['id']]);
            continue;
        }
        $samples = [];
        $text = $header[1] . ' ' . $body . ' ' . json_encode($buttons);
        preg_match_all('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', $text, $m);
        foreach (array_unique($m[1]) as $k) {
            $samples[$k] = $S[$k] ?? '';
        }
        if ($cat === 'AUTHENTICATION') {
            $samples['otp_code'] = $S['otp_code'];
        }
        $c = wa_tpl_compile([
            'name' => $name, 'display_name' => $display, 'description' => $desc, 'category' => $cat, 'language' => 'en',
            'header_type' => $header[0], 'header_text' => $header[1], 'body' => $body, 'footer' => $footer,
            'button_type' => $btnType, 'buttons' => $buttons, 'samples' => $samples, 'otp' => $otp ?? [],
        ], $existing ? (int) $existing['id'] : null);
        $blocking = wa_tpl_blocking($c['issues'], true);
        if ($blocking) {
            throw new RuntimeException("Seed template $name is not Meta-compliant: " . json_encode($blocking));
        }
        $row = $c['row'] + ['is_system' => 1];
        if ($existing) {
            db_update('wa_templates', $row, 'id = ?', [$existing['id']]);
            $updated++;
        } else {
            db_insert('wa_templates', $row + ['status' => 'draft']);
            $created++;
        }
    }
    if (PHP_SAPI === 'cli' && !defined('GIMT_INSTALLER_QUIET')) {
        fwrite(STDOUT, "    WhatsApp templates: $created created, $updated drafts refreshed\n");
    }
};
