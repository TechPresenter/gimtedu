-- =====================================================================
-- 03 People: faculty, staff, leaves, employee documents, faculty assignments
-- =====================================================================

CREATE TABLE faculty (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id VARCHAR(30) NOT NULL,
  user_id INT UNSIGNED NULL COMMENT 'login account (role Faculty)',
  title VARCHAR(20) NULL COMMENT 'Dr., Prof., Mr., Ms.',
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NULL,
  photo VARCHAR(255) NULL,
  gender VARCHAR(10) NULL COMMENT 'male|female|other',
  dob DATE NULL,
  designation VARCHAR(100) NOT NULL COMMENT 'Professor, Associate Professor, Assistant Professor, Lecturer, HOD, Dean',
  department_id INT UNSIGNED NULL,
  qualification VARCHAR(190) NULL,
  specialization VARCHAR(190) NULL,
  experience_years DECIMAL(4,1) NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) NULL,
  alternate_phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  pincode VARCHAR(12) NULL,
  joining_date DATE NULL,
  employment_type VARCHAR(20) NOT NULL DEFAULT 'permanent' COMMENT 'permanent|contract|visiting|guest|probation',
  salary DECIMAL(12,2) NULL COMMENT 'monthly gross (payroll reference)',
  bank_account VARCHAR(40) NULL,
  bank_ifsc VARCHAR(20) NULL,
  pan_no VARCHAR(20) NULL,
  bio TEXT NULL,
  research_interests TEXT NULL,
  publications_count SMALLINT UNSIGNED NULL,
  linkedin_url VARCHAR(255) NULL,
  show_on_website TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|on_leave|resigned|retired|inactive',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_faculty_employee_id (employee_id),
  UNIQUE KEY uq_faculty_email (email),
  KEY idx_faculty_department (department_id),
  KEY idx_faculty_status (status),
  CONSTRAINT fk_faculty_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_faculty_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE departments ADD CONSTRAINT fk_departments_hod FOREIGN KEY (hod_faculty_id) REFERENCES faculty(id) ON DELETE SET NULL;
ALTER TABLE sections ADD CONSTRAINT fk_sections_class_teacher FOREIGN KEY (class_teacher_id) REFERENCES faculty(id) ON DELETE SET NULL;

CREATE TABLE staff (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_id VARCHAR(30) NOT NULL,
  user_id INT UNSIGNED NULL,
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NULL,
  photo VARCHAR(255) NULL,
  gender VARCHAR(10) NULL,
  dob DATE NULL,
  designation VARCHAR(100) NOT NULL,
  category VARCHAR(40) NOT NULL DEFAULT 'administration' COMMENT 'administration|accounts|library|hostel|transport|maintenance|security|it|laboratory|other',
  department_id INT UNSIGNED NULL,
  qualification VARCHAR(190) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  joining_date DATE NULL,
  employment_type VARCHAR(20) NOT NULL DEFAULT 'permanent',
  salary DECIMAL(12,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|on_leave|resigned|inactive',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_staff_employee_id (employee_id),
  KEY idx_staff_category (category),
  CONSTRAINT fk_staff_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_staff_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
  ADD CONSTRAINT fk_users_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;

CREATE TABLE employee_leaves (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_type VARCHAR(10) NOT NULL COMMENT 'faculty|staff',
  employee_id INT UNSIGNED NOT NULL COMMENT 'faculty.id or staff.id',
  leave_type VARCHAR(30) NOT NULL COMMENT 'casual|sick|earned|maternity|duty|unpaid|other',
  from_date DATE NOT NULL,
  to_date DATE NOT NULL,
  days DECIMAL(4,1) NOT NULL DEFAULT 1,
  reason VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|rejected|cancelled',
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_leaves_employee (employee_type, employee_id),
  KEY idx_leaves_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE employee_documents (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  employee_type VARCHAR(10) NOT NULL COMMENT 'faculty|staff',
  employee_id INT UNSIGNED NOT NULL,
  doc_type VARCHAR(40) NOT NULL COMMENT 'resume|id_proof|degree|experience|appointment_letter|other',
  title VARCHAR(190) NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  original_name VARCHAR(190) NULL,
  mime VARCHAR(100) NULL,
  size_bytes INT UNSIGNED NULL,
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_empdocs_employee (employee_type, employee_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which faculty teaches which subject (per section & session)
CREATE TABLE faculty_subjects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  subject_id INT UNSIGNED NOT NULL,
  section_id INT UNSIGNED NULL,
  academic_session_id INT UNSIGNED NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_faculty_subjects (faculty_id, subject_id, section_id, academic_session_id),
  KEY idx_fs_subject (subject_id),
  CONSTRAINT fk_fs_faculty FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
  CONSTRAINT fk_fs_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE CASCADE,
  CONSTRAINT fk_fs_section FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_fs_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
