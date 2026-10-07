-- =====================================================================
-- 28 Examination unit additions (unit: exams). Runs after 01-12.
--   * exams.verified_at / verified_by     - marks verification (lock) audit
--   * exam_students eligibility snapshot   - attendance %, overdue fee balance,
--     manual override (with reason, who, when) used for admit cards
--   * results withhold audit + computed_at - WITHHELD keeps its reason across
--     re-processing; computed_at tells when SGPA/CGPA were last calculated
--   * marksheets revocation + last print    - revoke reason/date, last printed
--   * indexes for conflict checks (room/date) and result filters
-- Portable MySQL 8 / MariaDB 10.4+ SQL.
-- =====================================================================

ALTER TABLE exams
  ADD COLUMN verified_at DATETIME NULL AFTER min_attendance_percent,
  ADD COLUMN verified_by INT UNSIGNED NULL AFTER verified_at,
  ADD KEY idx_exams_dates (start_date, end_date);

ALTER TABLE exam_students
  ADD COLUMN attendance_percent DECIMAL(5,2) NULL COMMENT 'session attendance when eligibility was checked' AFTER ineligibility_reason,
  ADD COLUMN fee_due DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'overdue fee balance when eligibility was checked' AFTER attendance_percent,
  ADD COLUMN is_override TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'eligibility set manually' AFTER fee_due,
  ADD COLUMN override_reason VARCHAR(255) NULL AFTER is_override,
  ADD COLUMN overridden_by INT UNSIGNED NULL AFTER override_reason,
  ADD COLUMN overridden_at DATETIME NULL AFTER overridden_by,
  ADD KEY idx_exs_exam_eligible (exam_id, is_eligible);

ALTER TABLE exam_schedules
  ADD KEY idx_esch_room_date (classroom_id, exam_date);

ALTER TABLE exam_attendance
  ADD KEY idx_exatt_student (student_id);

ALTER TABLE results
  ADD COLUMN withheld_reason VARCHAR(255) NULL AFTER remarks,
  ADD COLUMN withheld_by INT UNSIGNED NULL AFTER withheld_reason,
  ADD COLUMN withheld_at DATETIME NULL AFTER withheld_by,
  ADD COLUMN computed_at DATETIME NULL AFTER withheld_at,
  ADD KEY idx_results_program (program_id, semester_no),
  ADD KEY idx_results_exam_status (exam_id, result_status);

ALTER TABLE marksheets
  ADD COLUMN revoked_reason VARCHAR(255) NULL AFTER status,
  ADD COLUMN revoked_at DATETIME NULL AFTER revoked_reason,
  ADD COLUMN last_printed_at DATETIME NULL AFTER printed_count,
  ADD KEY idx_marksheets_status (status);
