-- =====================================================================
-- GIMT SmartCampus - 01 Core: users, RBAC, security, settings, logs
-- =====================================================================

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  username VARCHAR(60) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(30) NULL,
  password_hash VARCHAR(255) NOT NULL,
  avatar VARCHAR(255) NULL,
  designation VARCHAR(120) NULL,
  department_id INT UNSIGNED NULL,
  faculty_id INT UNSIGNED NULL COMMENT 'linked faculty profile (role Faculty)',
  staff_id INT UNSIGNED NULL COMMENT 'linked staff profile',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  theme ENUM('light','dark','system') NOT NULL DEFAULT 'light',
  preferences JSON NULL,
  two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
  two_factor_secret VARCHAR(255) NULL,
  failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until DATETIME NULL,
  last_failed_login_at DATETIME NULL,
  last_login_at DATETIME NULL,
  last_login_ip VARCHAR(45) NULL,
  password_changed_at DATETIME NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  description VARCHAR(255) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'system roles cannot be deleted',
  is_super TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'bypasses all permission checks',
  color VARCHAR(20) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  module VARCHAR(60) NOT NULL,
  action VARCHAR(20) NOT NULL COMMENT 'view|create|edit|delete|export|import|approve|publish|manage',
  label VARCHAR(150) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_permissions (module, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id INT UNSIGNED NOT NULL,
  permission_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_rp_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  user_id INT UNSIGNED NOT NULL,
  role_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, role_id),
  KEY idx_ur_role (role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Remember-me and password reset tokens (validator stored as SHA-256 hash)
CREATE TABLE user_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(20) NOT NULL COMMENT 'remember|reset',
  selector VARCHAR(40) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_selector (selector),
  KEY idx_tokens_user (user_id, type),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  identifier VARCHAR(190) NULL,
  status VARCHAR(20) NOT NULL COMMENT 'success|failed|locked|blocked|logout',
  reason VARCHAR(190) NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_login_user (user_id),
  KEY idx_login_ip_time (ip_address, created_at),
  KEY idx_login_status_time (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL COMMENT 'create|update|delete|login|logout|export|import|approve|publish|payment|...',
  module VARCHAR(60) NOT NULL,
  record_id VARCHAR(64) NULL,
  description VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'success',
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  meta JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_activity_user (user_id),
  KEY idx_activity_module (module, record_id),
  KEY idx_activity_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group` VARCHAR(40) NOT NULL DEFAULT 'general',
  `key` VARCHAR(100) NOT NULL,
  `value` MEDIUMTEXT NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_key (`key`),
  KEY idx_settings_group (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Atomic number sequences (receipts, applications, certificates ...)
CREATE TABLE sequences (
  name VARCHAR(60) NOT NULL,
  current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL DEFAULT 'system' COMMENT 'admission|fee|attendance|exam|enquiry|contact|application|certificate|security|system',
  title VARCHAR(190) NOT NULL,
  message VARCHAR(500) NULL,
  url VARCHAR(255) NULL,
  icon VARCHAR(40) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user_read (user_id, is_read, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Internal staff messages (topbar envelope)
CREATE TABLE messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  sender_id INT UNSIGNED NULL,
  recipient_id INT UNSIGNED NOT NULL,
  subject VARCHAR(190) NOT NULL,
  body TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  read_at DATETIME NULL,
  deleted_by_sender TINYINT(1) NOT NULL DEFAULT 0,
  deleted_by_recipient TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msg_recipient (recipient_id, is_read),
  KEY idx_msg_sender (sender_id),
  CONSTRAINT fk_msg_recipient FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE backups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename VARCHAR(190) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'database' COMMENT 'database|files|full',
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'completed' COMMENT 'running|completed|failed|restored',
  notes VARCHAR(255) NULL,
  error TEXT NULL,
  created_by INT UNSIGNED NULL,
  restored_at DATETIME NULL,
  restored_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_backups_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE system_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  level VARCHAR(20) NOT NULL DEFAULT 'info' COMMENT 'debug|info|warning|error|critical',
  message VARCHAR(1000) NOT NULL,
  context JSON NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_syslog_level_time (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blocked_ips (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip_address VARCHAR(45) NOT NULL,
  reason VARCHAR(255) NULL,
  expires_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_blocked_ip (ip_address)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fixed-window rate limiting for public forms and sensitive endpoints
CREATE TABLE rate_limits (
  rl_key VARCHAR(190) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  reset_at DATETIME NOT NULL,
  PRIMARY KEY (rl_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(60) NOT NULL,
  name VARCHAR(150) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  variables VARCHAR(500) NULL COMMENT 'comma separated placeholder names',
  channel VARCHAR(20) NOT NULL DEFAULT 'email' COMMENT 'email|sms|whatsapp',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_email_templates_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every outbound email / SMS / WhatsApp message
CREATE TABLE message_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  channel VARCHAR(20) NOT NULL COMMENT 'email|sms|whatsapp',
  recipient VARCHAR(190) NOT NULL,
  subject VARCHAR(255) NULL,
  body MEDIUMTEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'queued' COMMENT 'queued|sent|failed',
  error TEXT NULL,
  related_type VARCHAR(40) NULL,
  related_id INT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  sent_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msglog_channel_status (channel, status),
  KEY idx_msglog_campaign (campaign_id),
  KEY idx_msglog_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
