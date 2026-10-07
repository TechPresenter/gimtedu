-- =====================================================================
-- 02 Academics: sessions, departments, programs, courses, semesters,
-- subjects, sections, batches, classrooms
--
-- Hierarchy:  Department -> Program (BBA, MBA, B.Tech CSE ...)
--             -> Course (specialization / track, optional, e.g. MBA Finance)
--             -> Semester -> Subject -> Faculty (faculty_subjects) -> Students
-- =====================================================================

CREATE TABLE academic_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(20) NOT NULL COMMENT 'e.g. 2026-27',
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  admissions_open TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'upcoming|active|completed',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(20) NOT NULL,
  hod_faculty_id INT UNSIGNED NULL COMMENT 'FK added in 03_people.sql',
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  established_year SMALLINT UNSIGNED NULL,
  description TEXT NULL,
  image VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_departments_code (code),
  UNIQUE KEY uq_departments_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE programs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  department_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL COMMENT 'Bachelor of Business Administration',
  short_name VARCHAR(40) NOT NULL COMMENT 'BBA',
  code VARCHAR(20) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  level VARCHAR(20) NOT NULL DEFAULT 'UG' COMMENT 'UG|PG|Diploma|Certificate|PhD',
  degree VARCHAR(80) NULL COMMENT 'Bachelor''s Degree, Master''s Degree ...',
  category VARCHAR(40) NULL COMMENT 'Management|Technology|Commerce|Science|Computer Applications|Professional',
  duration_years DECIMAL(3,1) NOT NULL DEFAULT 3.0,
  duration_label VARCHAR(40) NULL COMMENT 'e.g. 6 Months',
  total_semesters TINYINT UNSIGNED NOT NULL DEFAULT 6,
  total_credits SMALLINT UNSIGNED NULL,
  intake_capacity SMALLINT UNSIGNED NULL,
  fee_per_year DECIMAL(12,2) NULL,
  fee_label VARCHAR(40) NULL COMMENT 'e.g. / Year, / Course',
  eligibility TEXT NULL,
  overview TEXT NULL,
  highlights TEXT NULL COMMENT 'one highlight per line',
  career_prospects TEXT NULL COMMENT 'one item per line',
  image VARCHAR(255) NULL,
  brochure VARCHAR(255) NULL,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  is_popular TINYINT(1) NOT NULL DEFAULT 0,
  show_on_website TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_programs_code (code),
  UNIQUE KEY uq_programs_slug (slug),
  KEY idx_programs_department (department_id),
  CONSTRAINT fk_programs_department FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Courses = specializations / tracks inside a program (optional)
CREATE TABLE courses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_id INT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(20) NOT NULL,
  description TEXT NULL,
  intake_capacity SMALLINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_courses_code (code),
  KEY idx_courses_program (program_id),
  CONSTRAINT fk_courses_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE semesters (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_id INT UNSIGNED NOT NULL,
  number TINYINT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL COMMENT 'Semester 1',
  academic_session_id INT UNSIGNED NULL,
  start_date DATE NULL,
  end_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_semesters_program_number (program_id, number),
  CONSTRAINT fk_semesters_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_semesters_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_id INT UNSIGNED NOT NULL,
  course_id INT UNSIGNED NULL,
  semester_no TINYINT UNSIGNED NOT NULL,
  name VARCHAR(190) NOT NULL,
  code VARCHAR(30) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'theory' COMMENT 'theory|practical|lab|project|elective',
  credits DECIMAL(4,1) NOT NULL DEFAULT 4.0,
  max_internal SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  max_external SMALLINT UNSIGNED NOT NULL DEFAULT 70,
  max_practical SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  pass_marks SMALLINT UNSIGNED NOT NULL DEFAULT 40,
  hours_per_week TINYINT UNSIGNED NULL,
  description TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subjects_code (code),
  KEY idx_subjects_program_sem (program_id, semester_no),
  CONSTRAINT fk_subjects_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_subjects_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE batches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_id INT UNSIGNED NOT NULL,
  name VARCHAR(60) NOT NULL COMMENT 'e.g. BBA 2026-2029',
  start_year SMALLINT UNSIGNED NOT NULL,
  end_year SMALLINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|completed',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_batches_program_start (program_id, start_year),
  CONSTRAINT fk_batches_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE classrooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  code VARCHAR(20) NOT NULL,
  building VARCHAR(80) NULL,
  floor VARCHAR(20) NULL,
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  type VARCHAR(20) NOT NULL DEFAULT 'classroom' COMMENT 'classroom|lab|seminar_hall|auditorium|exam_hall',
  facilities VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_classrooms_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  program_id INT UNSIGNED NOT NULL,
  batch_id INT UNSIGNED NULL,
  semester_no TINYINT UNSIGNED NOT NULL,
  academic_session_id INT UNSIGNED NULL,
  name VARCHAR(20) NOT NULL COMMENT 'A, B, C',
  capacity SMALLINT UNSIGNED NOT NULL DEFAULT 60,
  classroom_id INT UNSIGNED NULL,
  class_teacher_id INT UNSIGNED NULL COMMENT 'faculty id (FK in 03_people.sql)',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sections (program_id, semester_no, academic_session_id, name),
  CONSTRAINT fk_sections_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_sections_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE SET NULL,
  CONSTRAINT fk_sections_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_sections_room FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
