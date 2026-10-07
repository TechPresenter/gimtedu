-- =====================================================================
-- 25 Admissions unit: extra columns for the application form, fee recording,
-- follow-up completion, contact-message inbox flags and feedback workflow.
-- Runs after 01-12 (portable ALTER TABLE ... ADD COLUMN statements).
-- =====================================================================

-- Application form: second preference, quota, parents, class 10 details, declaration, facilities
ALTER TABLE admissions
  ADD COLUMN second_program_id INT UNSIGNED NULL AFTER course_id,
  ADD COLUMN quota VARCHAR(30) NULL COMMENT 'general|management|sports|nri|scholarship|lateral|defence' AFTER category,
  ADD COLUMN aadhaar_no VARCHAR(20) NULL AFTER nationality,
  ADD COLUMN father_phone VARCHAR(20) NULL AFTER father_name,
  ADD COLUMN father_occupation VARCHAR(100) NULL AFTER father_phone,
  ADD COLUMN mother_phone VARCHAR(20) NULL AFTER mother_name,
  ADD COLUMN mother_occupation VARCHAR(100) NULL AFTER mother_phone,
  ADD COLUMN guardian_name VARCHAR(150) NULL AFTER mother_occupation,
  ADD COLUMN guardian_relation VARCHAR(40) NULL AFTER guardian_name,
  ADD COLUMN family_income DECIMAL(12,2) NULL AFTER guardian_phone,
  ADD COLUMN tenth_board VARCHAR(100) NULL AFTER family_income,
  ADD COLUMN tenth_percentage DECIMAL(5,2) NULL AFTER tenth_board,
  ADD COLUMN tenth_year SMALLINT UNSIGNED NULL AFTER tenth_percentage,
  ADD COLUMN hostel_required TINYINT(1) NOT NULL DEFAULT 0 AFTER interview_remarks,
  ADD COLUMN transport_required TINYINT(1) NOT NULL DEFAULT 0 AFTER hostel_required,
  ADD COLUMN declaration_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER transport_required,
  ADD COLUMN declaration_at DATETIME NULL AFTER declaration_accepted,
  ADD COLUMN stage_changed_at DATETIME NULL AFTER stage,
  ADD COLUMN fee_mode VARCHAR(20) NULL COMMENT 'cash|bank_transfer|cheque|dd|upi|card|online' AFTER fee_paid,
  ADD COLUMN fee_reference VARCHAR(100) NULL AFTER fee_mode,
  ADD COLUMN fee_paid_on DATE NULL AFTER fee_reference,
  ADD COLUMN fee_receipt_no VARCHAR(40) NULL AFTER fee_paid_on,
  ADD COLUMN fee_collected_by INT UNSIGNED NULL AFTER fee_receipt_no;

ALTER TABLE admissions
  ADD KEY idx_admissions_phone (phone),
  ADD KEY idx_admissions_email (email),
  ADD KEY idx_admissions_assigned (assigned_to),
  ADD CONSTRAINT fk_admissions_second_program FOREIGN KEY (second_program_id) REFERENCES programs(id) ON DELETE SET NULL;

-- Follow-ups: a scheduled follow-up (next_followup_date) is open until it is completed
ALTER TABLE admission_followups
  ADD COLUMN completed_at DATETIME NULL AFTER next_followup_date,
  ADD COLUMN completed_by INT UNSIGNED NULL AFTER completed_at;

ALTER TABLE admission_followups
  ADD KEY idx_followups_open (completed_at, next_followup_date);

ALTER TABLE enquiries
  ADD KEY idx_enquiries_phone (phone),
  ADD KEY idx_enquiries_followup (follow_up_date);

-- Contact messages inbox: read/star/archive flags + link to the enquiry it was converted to
ALTER TABLE contact_messages
  ADD COLUMN is_starred TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN is_archived TINYINT(1) NOT NULL DEFAULT 0 AFTER is_starred,
  ADD COLUMN read_at DATETIME NULL AFTER is_archived,
  ADD COLUMN resolved_at DATETIME NULL AFTER read_at,
  ADD COLUMN enquiry_id INT UNSIGNED NULL AFTER resolved_at;

ALTER TABLE contact_messages
  ADD KEY idx_contact_flags (is_archived, is_starred),
  ADD CONSTRAINT fk_contact_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE SET NULL;

-- Feedback & complaints workflow: open -> in_progress -> resolved -> closed
ALTER TABLE feedback
  ADD COLUMN resolved_by INT UNSIGNED NULL AFTER resolved_at,
  ADD COLUMN closed_at DATETIME NULL AFTER resolved_by,
  ADD COLUMN started_at DATETIME NULL AFTER closed_at;

ALTER TABLE feedback
  ADD KEY idx_feedback_assigned (assigned_to),
  ADD KEY idx_feedback_created (created_at);
