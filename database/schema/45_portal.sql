-- =====================================================================
-- GIMT SmartCampus - 45 Student & Faculty portals (/portal/)
--   * student_accounts         one login per student (username = Student ID), separate from admin `users`
--   * student_account_tokens   "remember me" tokens for the student portal (validator stored as SHA-256)
--   * student_login_logs       sign-in audit trail for student accounts (admin users keep using login_logs)
-- Faculty sign in with their linked `users` row (users.faculty_id) - no schema change needed for them.
-- =====================================================================

CREATE TABLE student_accounts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  username VARCHAR(40) NOT NULL COMMENT 'Student ID (students.student_uid) at the time the account was created',
  password_hash VARCHAR(255) NULL COMMENT 'NULL until a password is set (admin generated or self activation)',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|disabled',
  must_change_password TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = temporary password issued by the office',
  failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_failed_login_at DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  login_count INT UNSIGNED NOT NULL DEFAULT 0,
  activated_at DATETIME NULL COMMENT 'first time the student set their own password',
  activation_method VARCHAR(20) NULL COMMENT 'self|admin|bulk|reset|seed',
  password_changed_at DATETIME NULL,
  reset_selector VARCHAR(40) NULL,
  reset_token_hash CHAR(64) NULL,
  reset_expires_at DATETIME NULL,
  theme VARCHAR(10) NULL COMMENT 'light|dark|system',
  created_by INT UNSIGNED NULL COMMENT 'users.id of the admin who generated the account',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_accounts_student (student_id),
  UNIQUE KEY uq_student_accounts_username (username),
  UNIQUE KEY uq_student_accounts_reset (reset_selector),
  KEY idx_student_accounts_status (status),
  KEY idx_student_accounts_last_login (last_login_at),
  CONSTRAINT fk_student_accounts_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_student_accounts_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_account_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  account_id INT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'remember' COMMENT 'remember',
  selector VARCHAR(40) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_tokens_selector (selector),
  KEY idx_student_tokens_account (account_id, type),
  CONSTRAINT fk_student_tokens_account FOREIGN KEY (account_id) REFERENCES student_accounts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_login_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NULL,
  identifier VARCHAR(190) NULL,
  status VARCHAR(20) NOT NULL COMMENT 'success|failed|locked|blocked|logout|activated|reset',
  reason VARCHAR(190) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_student_login_student (student_id, created_at),
  KEY idx_student_login_ip_time (ip_address, created_at),
  KEY idx_student_login_status_time (status, created_at),
  KEY idx_student_login_created (created_at),
  CONSTRAINT fk_student_login_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
