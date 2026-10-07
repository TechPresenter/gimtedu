-- =====================================================================
-- 09 Communication: notices, events, contact messages, feedback,
-- newsletter subscribers, campaigns
-- =====================================================================

CREATE TABLE notices (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'general' COMMENT 'general|academic|examination|admission|event|holiday|placement|circular|urgent|fee',
  audience VARCHAR(20) NOT NULL DEFAULT 'all' COMMENT 'all|students|faculty|staff|parents|alumni|department|program|semester',
  department_id INT UNSIGNED NULL,
  program_id INT UNSIGNED NULL,
  semester_no TINYINT UNSIGNED NULL,
  description MEDIUMTEXT NULL,
  attachment VARCHAR(255) NULL,
  publish_date DATETIME NULL,
  expiry_date DATETIME NULL,
  is_pinned TINYINT(1) NOT NULL DEFAULT 0,
  show_on_website TINYINT(1) NOT NULL DEFAULT 1,
  send_email TINYINT(1) NOT NULL DEFAULT 0,
  send_sms TINYINT(1) NOT NULL DEFAULT 0,
  send_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|published|archived',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notices_slug (slug),
  KEY idx_notices_status_date (status, publish_date),
  CONSTRAINT fk_notices_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_notices_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(255) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'academic' COMMENT 'academic|cultural|sports|seminar|workshop|webinar|conference|placement|admission|fdp|alumni|other',
  excerpt VARCHAR(500) NULL,
  description MEDIUMTEXT NULL,
  venue VARCHAR(190) NULL,
  start_datetime DATETIME NOT NULL,
  end_datetime DATETIME NULL,
  image VARCHAR(255) NULL,
  organizer VARCHAR(150) NULL,
  contact_email VARCHAR(190) NULL,
  registration_link VARCHAR(255) NULL,
  max_participants INT UNSIGNED NULL,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  show_on_website TINYINT(1) NOT NULL DEFAULT 1,
  status VARCHAR(20) NOT NULL DEFAULT 'published' COMMENT 'draft|published|cancelled|completed',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_events_slug (slug),
  KEY idx_events_start (start_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(20) NULL,
  organization VARCHAR(190) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'registered' COMMENT 'registered|attended|cancelled',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_event_reg (event_id, email),
  CONSTRAINT fk_evreg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(20) NULL,
  enquiry_type VARCHAR(40) NULL COMMENT 'admission|academic|placement|general|other',
  subject VARCHAR(255) NULL,
  message TEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'new' COMMENT 'new|read|replied|closed',
  reply TEXT NULL,
  replied_by INT UNSIGNED NULL,
  replied_at DATETIME NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_contact_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feedback (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_no VARCHAR(30) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'feedback' COMMENT 'feedback|complaint|suggestion|grievance',
  category VARCHAR(40) NULL COMMENT 'academic|infrastructure|hostel|transport|fees|faculty|administration|ragging|other',
  submitted_by_type VARCHAR(20) NOT NULL DEFAULT 'student' COMMENT 'student|parent|faculty|staff|visitor|alumni',
  student_id INT UNSIGNED NULL,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(20) NULL,
  subject VARCHAR(255) NOT NULL,
  message TEXT NOT NULL,
  rating TINYINT UNSIGNED NULL,
  priority VARCHAR(10) NOT NULL DEFAULT 'medium' COMMENT 'low|medium|high|urgent',
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|in_progress|resolved|closed',
  assigned_to INT UNSIGNED NULL,
  resolution TEXT NULL,
  resolved_at DATETIME NULL,
  is_anonymous TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_feedback_ticket (ticket_no),
  KEY idx_feedback_status (status),
  CONSTRAINT fk_feedback_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(150) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'subscribed' COMMENT 'subscribed|unsubscribed|bounced',
  source VARCHAR(40) NULL DEFAULT 'website',
  token CHAR(32) NOT NULL COMMENT 'unsubscribe token',
  ip_address VARCHAR(45) NULL,
  subscribed_at DATETIME NOT NULL,
  unsubscribed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_newsletter_email (email),
  UNIQUE KEY uq_newsletter_token (token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_campaigns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  channel VARCHAR(20) NOT NULL DEFAULT 'email' COMMENT 'email|sms|whatsapp',
  subject VARCHAR(255) NULL,
  body_html MEDIUMTEXT NOT NULL,
  audience VARCHAR(30) NOT NULL DEFAULT 'subscribers' COMMENT 'subscribers|students|parents|faculty|staff|alumni|program|custom',
  audience_filter JSON NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|scheduled|sending|sent|failed|cancelled',
  scheduled_at DATETIME NULL,
  sent_at DATETIME NULL,
  recipients_count INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_campaign_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
