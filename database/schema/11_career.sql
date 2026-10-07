-- =====================================================================
-- 11 Career: Placement & Alumni
-- =====================================================================

CREATE TABLE companies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  logo VARCHAR(255) NULL,
  industry VARCHAR(100) NULL COMMENT 'IT Services|Consulting|BFSI|FMCG|E-commerce|Manufacturing|...',
  website VARCHAR(255) NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(30) NULL,
  contact_person VARCHAR(150) NULL,
  contact_designation VARCHAR(100) NULL,
  address VARCHAR(255) NULL,
  city VARCHAR(80) NULL,
  description TEXT NULL,
  is_recruiter TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'show in website recruiters strip',
  is_mou TINYINT(1) NOT NULL DEFAULT 0,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_companies_slug (slug),
  KEY idx_companies_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placement_drives (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  job_role VARCHAR(150) NOT NULL,
  job_type VARCHAR(20) NOT NULL DEFAULT 'full_time' COMMENT 'full_time|internship|internship_ppo|contract',
  description TEXT NULL,
  package_min DECIMAL(6,2) NULL COMMENT 'LPA',
  package_max DECIMAL(6,2) NULL COMMENT 'LPA',
  stipend DECIMAL(10,2) NULL COMMENT 'monthly stipend for internships',
  location VARCHAR(150) NULL,
  eligible_programs JSON NULL COMMENT 'array of program ids',
  batch_year SMALLINT UNSIGNED NULL COMMENT 'passing-out year',
  min_cgpa DECIMAL(4,2) NULL,
  min_percentage DECIMAL(5,2) NULL,
  max_backlogs TINYINT UNSIGNED NULL,
  eligibility_notes TEXT NULL,
  rounds VARCHAR(255) NULL COMMENT 'comma separated: Aptitude,Technical,HR',
  drive_date DATE NULL,
  registration_deadline DATE NULL,
  venue VARCHAR(190) NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'on_campus' COMMENT 'on_campus|off_campus|virtual|pool',
  status VARCHAR(20) NOT NULL DEFAULT 'upcoming' COMMENT 'upcoming|open|ongoing|completed|cancelled',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_drives_status_date (status, drive_date),
  CONSTRAINT fk_drives_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placement_applications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  drive_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'applied' COMMENT 'applied|shortlisted|aptitude|technical|hr|selected|rejected|on_hold|withdrawn',
  current_round VARCHAR(60) NULL,
  resume_path VARCHAR(255) NULL,
  remarks VARCHAR(255) NULL,
  applied_at DATETIME NOT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_placement_app (drive_id, student_id),
  KEY idx_papp_status (status),
  CONSTRAINT fk_papp_drive FOREIGN KEY (drive_id) REFERENCES placement_drives(id) ON DELETE CASCADE,
  CONSTRAINT fk_papp_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placement_interviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NOT NULL,
  round_name VARCHAR(60) NOT NULL,
  scheduled_at DATETIME NULL,
  mode VARCHAR(20) NULL COMMENT 'in_person|online|telephonic',
  panel VARCHAR(255) NULL,
  result VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|cleared|failed|absent',
  score DECIMAL(5,2) NULL,
  feedback VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pint_app (application_id),
  CONSTRAINT fk_pint_app FOREIGN KEY (application_id) REFERENCES placement_applications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placement_offers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id INT UNSIGNED NULL,
  drive_id INT UNSIGNED NULL,
  student_id INT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  job_role VARCHAR(150) NOT NULL,
  package_lpa DECIMAL(6,2) NOT NULL,
  offer_date DATE NOT NULL,
  joining_date DATE NULL,
  location VARCHAR(150) NULL,
  offer_letter VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'offered' COMMENT 'offered|accepted|declined|joined|revoked',
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_offers_student (student_id),
  KEY idx_offers_company (company_id),
  CONSTRAINT fk_offers_app FOREIGN KEY (application_id) REFERENCES placement_applications(id) ON DELETE SET NULL,
  CONSTRAINT fk_offers_drive FOREIGN KEY (drive_id) REFERENCES placement_drives(id) ON DELETE SET NULL,
  CONSTRAINT fk_offers_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_offers_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placement_trainings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'training' COMMENT 'training|mock_test|mock_interview|workshop|seminar|certification',
  trainer VARCHAR(150) NULL,
  program_id INT UNSIGNED NULL,
  semester_no TINYINT UNSIGNED NULL,
  start_date DATE NOT NULL,
  end_date DATE NULL,
  duration_hours DECIMAL(5,1) NULL,
  venue VARCHAR(150) NULL,
  max_marks DECIMAL(6,2) NULL COMMENT 'for mock tests',
  participants_count INT UNSIGNED NOT NULL DEFAULT 0,
  description TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled|ongoing|completed|cancelled',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ptrain_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE placement_training_participants (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  training_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  attended TINYINT(1) NOT NULL DEFAULT 0,
  score DECIMAL(6,2) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ptp (training_id, student_id),
  CONSTRAINT fk_ptp_training FOREIGN KEY (training_id) REFERENCES placement_trainings(id) ON DELETE CASCADE,
  CONSTRAINT fk_ptp_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alumni (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(20) NULL,
  photo VARCHAR(255) NULL,
  gender VARCHAR(10) NULL,
  batch_year SMALLINT UNSIGNED NOT NULL COMMENT 'graduation year',
  program_id INT UNSIGNED NULL,
  department_id INT UNSIGNED NULL,
  company VARCHAR(190) NULL,
  designation VARCHAR(150) NULL,
  industry VARCHAR(100) NULL,
  city VARCHAR(80) NULL,
  country VARCHAR(80) NULL DEFAULT 'India',
  linkedin_url VARCHAR(255) NULL,
  employment_status VARCHAR(20) NOT NULL DEFAULT 'employed' COMMENT 'employed|self_employed|higher_studies|entrepreneur|seeking|other',
  is_mentor TINYINT(1) NOT NULL DEFAULT 0,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  show_on_website TINYINT(1) NOT NULL DEFAULT 0,
  bio TEXT NULL,
  achievements TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alumni_email (email),
  KEY idx_alumni_batch (batch_year),
  CONSTRAINT fk_alumni_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
  CONSTRAINT fk_alumni_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL,
  CONSTRAINT fk_alumni_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alumni_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  event_date DATETIME NOT NULL,
  venue VARCHAR(190) NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'in_person' COMMENT 'in_person|online|hybrid',
  image VARCHAR(255) NULL,
  registration_link VARCHAR(255) NULL,
  attendees_count INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'upcoming' COMMENT 'upcoming|completed|cancelled',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alumni_jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  posted_by_alumni_id INT UNSIGNED NULL,
  company VARCHAR(190) NOT NULL,
  title VARCHAR(190) NOT NULL,
  location VARCHAR(150) NULL,
  job_type VARCHAR(20) NOT NULL DEFAULT 'full_time' COMMENT 'full_time|part_time|internship|contract|remote',
  experience VARCHAR(60) NULL,
  salary_range VARCHAR(60) NULL,
  description TEXT NULL,
  apply_link VARCHAR(255) NULL,
  last_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|closed',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_ajobs_alumni FOREIGN KEY (posted_by_alumni_id) REFERENCES alumni(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alumni_success_stories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alumni_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  story TEXT NOT NULL,
  image VARCHAR(255) NULL,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  show_on_website TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'published' COMMENT 'draft|published',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_astories_alumni FOREIGN KEY (alumni_id) REFERENCES alumni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alumni_donations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_no VARCHAR(40) NOT NULL,
  alumni_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  purpose VARCHAR(190) NULL COMMENT 'scholarship fund, infrastructure, library ...',
  donation_date DATE NOT NULL,
  payment_mode VARCHAR(20) NULL,
  reference_no VARCHAR(100) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'received' COMMENT 'pledged|received|cancelled',
  remarks VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_donation_receipt (receipt_no),
  CONSTRAINT fk_donations_alumni FOREIGN KEY (alumni_id) REFERENCES alumni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alumni_mentorships (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  alumni_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  area VARCHAR(150) NULL,
  start_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|completed|cancelled',
  notes VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_amentor_alumni FOREIGN KEY (alumni_id) REFERENCES alumni(id) ON DELETE CASCADE,
  CONSTRAINT fk_amentor_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
