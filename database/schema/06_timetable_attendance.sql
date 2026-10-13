-- =====================================================================
-- 06 Timetable & Attendance
-- =====================================================================

CREATE TABLE time_slots (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(40) NOT NULL COMMENT 'Period 1, Lunch Break',
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  is_break TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row = one class period. Unique keys enforce no faculty / room / section double booking.
CREATE TABLE timetables (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_session_id INT UNSIGNED NOT NULL,
  program_id INT UNSIGNED NOT NULL,
  semester_no TINYINT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NOT NULL,
  day_of_week TINYINT UNSIGNED NOT NULL COMMENT '1=Monday ... 7=Sunday',
  time_slot_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  faculty_id INT UNSIGNED NULL,
  classroom_id INT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'lecture' COMMENT 'lecture|lab|tutorial|seminar',
  notes VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'published' COMMENT 'draft|published',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tt_section_slot (academic_session_id, section_id, day_of_week, time_slot_id),
  UNIQUE KEY uq_tt_faculty_slot (academic_session_id, faculty_id, day_of_week, time_slot_id),
  UNIQUE KEY uq_tt_room_slot (academic_session_id, classroom_id, day_of_week, time_slot_id),
  KEY idx_tt_program (program_id, semester_no),
  CONSTRAINT fk_tt_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_slot FOREIGN KEY (time_slot_id) REFERENCES time_slots(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_tt_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE SET NULL,
  CONSTRAINT fk_tt_room FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendance sheet: one per (date, class/subject) for students, or per date for employees
CREATE TABLE attendance (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  type VARCHAR(10) NOT NULL DEFAULT 'student' COMMENT 'student|faculty|staff',
  attendance_date DATE NOT NULL,
  academic_session_id INT UNSIGNED NULL,
  program_id INT UNSIGNED NULL,
  semester_no TINYINT UNSIGNED NULL,
  section_id INT UNSIGNED NULL,
  subject_id INT UNSIGNED NULL COMMENT 'NULL = daily attendance',
  timetable_id INT UNSIGNED NULL,
  time_slot_id INT UNSIGNED NULL,
  method VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual|biometric|qr|import',
  taken_by INT UNSIGNED NULL COMMENT 'users.id',
  remarks VARCHAR(255) NULL,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attendance_sheet (type, attendance_date, section_id, subject_id, time_slot_id),
  KEY idx_att_date (attendance_date),
  KEY idx_att_section (section_id, attendance_date),
  CONSTRAINT fk_att_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_att_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attendance_id INT UNSIGNED NOT NULL,
  person_type VARCHAR(10) NOT NULL DEFAULT 'student' COMMENT 'student|faculty|staff',
  person_id INT UNSIGNED NOT NULL COMMENT 'students.id / faculty.id / staff.id',
  status VARCHAR(10) NOT NULL COMMENT 'present|absent|late|leave|half_day',
  in_time TIME NULL,
  out_time TIME NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_att_record (attendance_id, person_type, person_id),
  KEY idx_att_person (person_type, person_id, status),
  CONSTRAINT fk_attrec_sheet FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE holidays (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(150) NOT NULL,
  holiday_date DATE NOT NULL,
  end_date DATE NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'holiday' COMMENT 'holiday|vacation|exam_break|event',
  academic_session_id INT UNSIGNED NULL,
  description VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_holidays_date (holiday_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
