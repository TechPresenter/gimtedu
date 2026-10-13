-- =====================================================================
-- GIMT SmartCampus - 43 AI chatbot (website "GIMT Admission Assistant").
-- Conversations + messages (provider / model / tokens / latency / feedback),
-- and a provider log (failed calls, fallbacks, admin connection tests).
-- Settings live in `settings` (group 'chatbot', seeded by database/seed/09_chatbot.php).
-- Runs after 01-12 (+ add-on files); plain CREATE TABLEs, portable to MySQL 8 / MariaDB 10.4+.
-- =====================================================================

CREATE TABLE IF NOT EXISTS chatbot_conversations (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  public_id CHAR(32) NOT NULL COMMENT 'random token the browser keeps in sessionStorage (never the numeric id)',
  visitor_hash CHAR(64) NULL COMMENT 'HMAC-SHA256 of the visitor IP (raw IPs are not stored)',
  user_agent VARCHAR(255) NULL,
  page_url VARCHAR(500) NULL COMMENT 'page the chat started on',
  referrer VARCHAR(500) NULL,
  utm_source VARCHAR(100) NULL,
  utm_medium VARCHAR(100) NULL,
  utm_campaign VARCHAR(100) NULL,
  language VARCHAR(12) NULL COMMENT 'en | hi | hinglish (detected from the first questions)',
  title VARCHAR(255) NULL COMMENT 'first visitor question (list label)',
  message_count INT UNSIGNED NOT NULL DEFAULT 0,
  user_message_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_provider VARCHAR(20) NULL COMMENT 'anthropic | openai | gemini | offline',
  lead_status VARCHAR(20) NOT NULL DEFAULT 'none' COMMENT 'none | offered | captured',
  enquiry_id INT UNSIGNED NULL,
  lead_name VARCHAR(150) NULL,
  feedback_up INT UNSIGNED NOT NULL DEFAULT 0,
  feedback_down INT UNSIGNED NOT NULL DEFAULT 0,
  source VARCHAR(20) NOT NULL DEFAULT 'web' COMMENT 'web | demo',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active | closed',
  started_at DATETIME NOT NULL,
  last_message_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_chatbot_conv_public (public_id),
  KEY idx_chatbot_conv_started (started_at),
  KEY idx_chatbot_conv_last (last_message_at),
  KEY idx_chatbot_conv_lead (lead_status),
  KEY idx_chatbot_conv_visitor (visitor_hash),
  KEY idx_chatbot_conv_enquiry (enquiry_id),
  CONSTRAINT fk_chatbot_conv_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chatbot_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id BIGINT UNSIGNED NOT NULL,
  role VARCHAR(12) NOT NULL COMMENT 'user | assistant | event',
  content TEXT NOT NULL,
  intent VARCHAR(40) NULL COMMENT 'detected topic of the visitor question (fees, programs, hostel …)',
  provider VARCHAR(20) NULL COMMENT 'anthropic | openai | gemini | offline (assistant rows)',
  model VARCHAR(80) NULL,
  sources TEXT NULL COMMENT 'JSON [{title, url}] of the knowledge snippets used',
  input_tokens INT UNSIGNED NULL,
  output_tokens INT UNSIGNED NULL,
  cached_tokens INT UNSIGNED NULL,
  latency_ms INT UNSIGNED NULL,
  error VARCHAR(500) NULL COMMENT 'provider errors that led to a fallback for this answer',
  feedback TINYINT NULL COMMENT '1 = helpful, -1 = not helpful',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chatbot_msg_conv (conversation_id, id),
  KEY idx_chatbot_msg_created (created_at),
  KEY idx_chatbot_msg_role (role, created_at),
  CONSTRAINT fk_chatbot_msg_conv FOREIGN KEY (conversation_id) REFERENCES chatbot_conversations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chatbot_provider_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(20) NOT NULL,
  model VARCHAR(80) NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'chat' COMMENT 'chat | test',
  status VARCHAR(20) NOT NULL COMMENT 'ok | error',
  http_status SMALLINT UNSIGNED NULL,
  error_type VARCHAR(60) NULL COMMENT 'auth | rate_limit | overloaded | timeout | network | bad_request | not_found | server | refusal | config',
  message VARCHAR(500) NULL,
  latency_ms INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chatbot_plog_created (created_at),
  KEY idx_chatbot_plog_provider (provider, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
