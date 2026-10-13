-- =====================================================================
-- 08 Examination, Results, Marksheets, Certificates
-- Workflow: Create Exam -> Assign Subjects -> Schedule -> Allocate Students
--           -> Enter Marks -> Verify -> Publish Result
-- =====================================================================

CREATE TABLE exam_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL COMMENT 'Mid Term, End Semester, Internal Assessment, Practical, Supplementary',
  code VARCHAR(20) NOT NULL,
  weightage DECIMAL(5,2) NULL COMMENT 'percentage contribution',
  is_final TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'final exams produce SGPA results',
  description VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exams (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  exam_type_id INT UNSIGNED NOT NULL,
  academic_session_id INT UNSIGNED NOT NULL,
  program_id INT UNSIGNED NULL,
  semester_no TINYINT UNSIGNED NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  result_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|scheduled|ongoing|marks_entry|verified|published|cancelled',
  min_attendance_percent DECIMAL(5,2) NULL COMMENT 'eligibility threshold',
  instructions TEXT NULL,
  published_at DATETIME NULL,
  published_by INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_exams_session (academic_session_id),
  KEY idx_exams_program (program_id, semester_no),
  KEY idx_exams_status (status),
  CONSTRAINT fk_exams_type FOREIGN KEY (exam_type_id) REFERENCES exam_types(id),
  CONSTRAINT fk_exams_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  CONSTRAINT fk_exams_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  max_internal SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  max_external SMALLINT UNSIGNED NOT NULL DEFAULT 70,
  max_practical SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pass_marks SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_subject (exam_id, subject_id),
  CONSTRAINT fk_es_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_es_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_schedules (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  exam_date DATE NOT NULL,
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  classroom_id INT UNSIGNED NULL,
  invigilator_id INT UNSIGNED NULL COMMENT 'faculty.id (chief invigilator)',
  notes VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_schedule (exam_id, subject_id),
  KEY idx_es_date (exam_date),
  CONSTRAINT fk_esch_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_esch_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_esch_room FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE SET NULL,
  CONSTRAINT fk_esch_invigilator FOREIGN KEY (invigilator_id) REFERENCES faculty(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_invigilators (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_schedule_id INT UNSIGNED NOT NULL,
  faculty_id INT UNSIGNED NOT NULL,
  classroom_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_invigilator (exam_schedule_id, faculty_id),
  CONSTRAINT fk_ei_schedule FOREIGN KEY (exam_schedule_id) REFERENCES exam_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_ei_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Student allocation & eligibility (hall ticket / seat)
CREATE TABLE exam_students (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  is_eligible TINYINT(1) NOT NULL DEFAULT 1,
  ineligibility_reason VARCHAR(255) NULL,
  hall_ticket_no VARCHAR(40) NULL,
  seat_no VARCHAR(20) NULL,
  classroom_id INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_student (exam_id, student_id),
  CONSTRAINT fk_exs_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_exs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_schedule_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'present' COMMENT 'present|absent|malpractice',
  remarks VARCHAR(255) NULL,
  marked_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_attendance (exam_schedule_id, student_id),
  CONSTRAINT fk_exatt_schedule FOREIGN KEY (exam_schedule_id) REFERENCES exam_schedules(id) ON DELETE CASCADE,
  CONSTRAINT fk_exatt_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grade_scales (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  grade VARCHAR(5) NOT NULL,
  min_percent DECIMAL(5,2) NOT NULL,
  max_percent DECIMAL(5,2) NOT NULL,
  grade_point DECIMAL(4,2) NOT NULL,
  description VARCHAR(60) NULL COMMENT 'Outstanding, Excellent ...',
  is_pass TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_grade (grade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exam_marks (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  internal_marks DECIMAL(6,2) NULL,
  external_marks DECIMAL(6,2) NULL,
  practical_marks DECIMAL(6,2) NULL,
  total_marks DECIMAL(6,2) NULL,
  max_marks DECIMAL(6,2) NULL,
  grade VARCHAR(5) NULL,
  grade_point DECIMAL(4,2) NULL,
  is_absent TINYINT(1) NOT NULL DEFAULT 0,
  is_pass TINYINT(1) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|submitted|verified|published',
  remarks VARCHAR(255) NULL,
  entered_by INT UNSIGNED NULL,
  verified_by INT UNSIGNED NULL,
  verified_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_exam_marks (exam_id, subject_id, student_id),
  KEY idx_marks_student (student_id),
  CONSTRAINT fk_marks_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE results (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  exam_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  academic_session_id INT UNSIGNED NULL,
  program_id INT UNSIGNED NULL,
  semester_no TINYINT UNSIGNED NULL,
  total_marks DECIMAL(8,2) NULL,
  max_marks DECIMAL(8,2) NULL,
  percentage DECIMAL(5,2) NULL,
  credits_registered DECIMAL(5,1) NULL,
  credits_earned DECIMAL(5,1) NULL,
  sgpa DECIMAL(4,2) NULL,
  cgpa DECIMAL(4,2) NULL,
  result_status VARCHAR(20) NOT NULL DEFAULT 'PASS' COMMENT 'PASS|FAIL|BACKLOG|ABSENT|WITHHELD',
  backlog_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
  class_rank SMALLINT UNSIGNED NULL,
  division VARCHAR(40) NULL COMMENT 'First Division with Distinction ...',
  remarks VARCHAR(255) NULL,
  is_published TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_results (exam_id, student_id),
  KEY idx_results_student (student_id),
  KEY idx_results_status (result_status),
  CONSTRAINT fk_results_exam FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
  CONSTRAINT fk_results_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_results_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE marksheets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  marksheet_no VARCHAR(40) NOT NULL,
  result_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  verification_code VARCHAR(64) NOT NULL,
  issued_at DATETIME NOT NULL,
  issued_by INT UNSIGNED NULL,
  printed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'issued' COMMENT 'issued|revoked',
  PRIMARY KEY (id),
  UNIQUE KEY uq_marksheet_no (marksheet_no),
  UNIQUE KEY uq_marksheet_result (result_id),
  CONSTRAINT fk_ms_result FOREIGN KEY (result_id) REFERENCES results(id) ON DELETE CASCADE,
  CONSTRAINT fk_ms_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificate_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(30) NOT NULL COMMENT 'bonafide|transfer|character|course_completion|internship|migration|provisional|custom',
  name VARCHAR(150) NOT NULL,
  title VARCHAR(190) NOT NULL COMMENT 'heading printed on the certificate',
  body_html MEDIUMTEXT NOT NULL COMMENT 'supports {{placeholders}}',
  orientation VARCHAR(10) NOT NULL DEFAULT 'portrait',
  signatory_name VARCHAR(150) NULL,
  signatory_designation VARCHAR(150) NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ct_type (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE certificates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  certificate_no VARCHAR(40) NOT NULL,
  type VARCHAR(30) NOT NULL,
  template_id INT UNSIGNED NULL,
  student_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  purpose VARCHAR(255) NULL,
  content_html MEDIUMTEXT NULL COMMENT 'rendered snapshot at issue time',
  data JSON NULL COMMENT 'extra fields (conduct, internship company, dates ...)',
  issue_date DATE NOT NULL,
  valid_until DATE NULL,
  verification_hash CHAR(64) NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'issued' COMMENT 'draft|issued|revoked',
  revoked_reason VARCHAR(255) NULL,
  issued_by INT UNSIGNED NULL,
  verified_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_certificate_no (certificate_no),
  KEY idx_cert_student (student_id),
  KEY idx_cert_type (type),
  CONSTRAINT fk_cert_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_cert_template FOREIGN KEY (template_id) REFERENCES certificate_templates(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
