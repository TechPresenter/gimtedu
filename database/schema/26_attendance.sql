-- =====================================================================
-- 26 Attendance additions (unit: attendance)
--   * attendance.sheet_key  - NULL-safe uniqueness for one sheet per
--     type/date/section/subject/period (the original unique key in 06 does
--     not stop duplicates when section/subject/slot are NULL, e.g. employee
--     daily sheets). Filled by app/services/attendance.php and the seeder.
--   * attendance.faculty_id - faculty member who conducted the class.
--   * attendance.*_count    - per-sheet status counts (kept in sync by the service) so dashboards,
--     trends, calendars and department/subject reports never re-aggregate every record.
--   * attendance_summaries  - per student per session totals (refreshed on every save) for the
--     session-to-date percentage, low-attendance alerts and defaulter lists.
--   * attendance_punches    - raw biometric / QR device punches received on
--     POST /api/attendance/punch (audit trail + idempotency key).
-- =====================================================================

ALTER TABLE attendance
  ADD COLUMN faculty_id INT UNSIGNED NULL COMMENT 'faculty who conducted the class' AFTER time_slot_id,
  ADD COLUMN sheet_key VARCHAR(100) NULL COMMENT 'type|date|section|subject|slot (0 = none)' AFTER faculty_id,
  ADD COLUMN total_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER is_locked,
  ADD COLUMN present_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER total_count,
  ADD COLUMN absent_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER present_count,
  ADD COLUMN late_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER absent_count,
  ADD COLUMN leave_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER late_count,
  ADD COLUMN half_day_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER leave_count,
  ADD UNIQUE KEY uq_attendance_sheet_key (sheet_key),
  ADD KEY idx_att_type_date (type, attendance_date),
  ADD KEY idx_att_subject_date (subject_id, attendance_date),
  ADD CONSTRAINT fk_att_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE SET NULL;

ALTER TABLE attendance_records
  ADD KEY idx_attrec_person_sheet (person_type, person_id, attendance_id),
  ADD KEY idx_attrec_sheet_status (attendance_id, status, person_id);

ALTER TABLE holidays
  ADD KEY idx_holidays_session (academic_session_id, holiday_date);

CREATE TABLE attendance_summaries (
  student_id INT UNSIGNED NOT NULL,
  academic_session_id INT UNSIGNED NOT NULL,
  held SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  present SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  absent SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  late SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  on_leave SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_date DATE NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (student_id, academic_session_id),
  KEY idx_attsum_session (academic_session_id),
  CONSTRAINT fk_attsum_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_attsum_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_punches (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  device_id VARCHAR(60) NOT NULL,
  punch_key VARCHAR(100) NOT NULL COMMENT 'punch id sent by the device, or a derived hash - idempotency key',
  method VARCHAR(20) NOT NULL DEFAULT 'biometric' COMMENT 'biometric|qr|rfid',
  identifier VARCHAR(100) NOT NULL COMMENT 'student ID / roll no / employee ID / QR payload as received',
  person_type VARCHAR(10) NULL COMMENT 'student|faculty|staff (resolved)',
  person_id INT UNSIGNED NULL,
  punched_at DATETIME NOT NULL,
  direction VARCHAR(10) NOT NULL DEFAULT 'auto' COMMENT 'in|out|auto',
  result VARCHAR(20) NOT NULL COMMENT 'recorded|no_class|unknown_person|holiday|ignored',
  attendance_id INT UNSIGNED NULL,
  record_status VARCHAR(10) NULL,
  message VARCHAR(255) NULL,
  ip_address VARCHAR(45) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_punch_device_key (device_id, punch_key),
  KEY idx_punch_person (person_type, person_id, punched_at),
  KEY idx_punch_created (created_at),
  CONSTRAINT fk_punch_sheet FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
