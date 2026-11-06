-- ============================================================================================================
-- 49_whatsapp_bot.sql — AI WhatsApp chatbot (Module 9). Extends the GIMT chatbot knowledge base for WhatsApp:
--   wa_bot_knowledge       WhatsApp-only verified answers (EN + HI) the bot may quote word for word
--   wa_bot_program_checks  per-program "verified for WhatsApp" flag (fees / eligibility quoted only when verified)
--   wa_bot_turns           one row per bot decision (question, reply, language, intent, confidence, KB facts used,
--                          provider, handover) — analytics, unanswered questions, AI cost guard, test console
-- Sessions (language, qualification, summary, handover state) live in wa_bot_sessions (48_whatsapp.sql).
-- ============================================================================================================

CREATE TABLE wa_bot_knowledge (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category VARCHAR(40) NOT NULL DEFAULT 'general' COMMENT 'admission|documents|fees|payment|scholarship|exam|result|certificate|hostel|transport|contact|general',
  question VARCHAR(255) NOT NULL,
  keywords VARCHAR(500) NULL COMMENT 'other phrasings / keywords in English, Hindi or Hinglish (comma separated)',
  answer_en TEXT NOT NULL,
  answer_hi TEXT NULL COMMENT 'Hindi (Devanagari) answer; English is used when empty',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|draft|archived — only active answers are used',
  verified_by INT UNSIGNED NULL,
  verified_at DATETIME NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_bot_knowledge_status (status, category),
  CONSTRAINT fk_wa_bot_knowledge_verified FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_bot_program_checks (
  program_id INT UNSIGNED NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = the bot never quotes fee / eligibility for this program (counsellor confirms)',
  note VARCHAR(255) NULL,
  checked_by INT UNSIGNED NULL,
  checked_at DATETIME NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (program_id),
  CONSTRAINT fk_wa_bot_program_checks_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_bot_program_checks_user FOREIGN KEY (checked_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_bot_turns (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id INT UNSIGNED NULL,
  session_id INT UNSIGNED NULL,
  inbound_message_id BIGINT UNSIGNED NULL COMMENT 'wa_messages.id of the question (unique: a message is answered once)',
  reply_message_id BIGINT UNSIGNED NULL COMMENT 'wa_messages.id of the bot reply',
  question TEXT NULL,
  reply TEXT NULL,
  language VARCHAR(10) NULL COMMENT 'en|hi|hing',
  intent VARCHAR(40) NULL,
  intents VARCHAR(190) NULL,
  action VARCHAR(20) NOT NULL DEFAULT 'answer' COMMENT 'answer|greeting|qualify|language|handover|fallback|media|limit|ack|skipped',
  confidence DECIMAL(4,3) NULL,
  answered TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = answered from verified data',
  provider VARCHAR(20) NULL COMMENT 'kb|programs|anthropic|openai|gemini|offline|flow',
  model VARCHAR(80) NULL,
  facts LONGTEXT NULL COMMENT 'JSON [{id,type,title,score}] knowledge used for the answer',
  handover TINYINT(1) NOT NULL DEFAULT 0,
  input_tokens INT UNSIGNED NULL,
  output_tokens INT UNSIGNED NULL,
  latency_ms INT UNSIGNED NULL,
  error VARCHAR(500) NULL,
  reviewed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'unanswered question reviewed (added to the KB or dismissed)',
  knowledge_id INT UNSIGNED NULL COMMENT 'KB answer created from this question',
  is_test TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'test console (nothing sent)',
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_bot_turns_inbound (inbound_message_id),
  KEY idx_wa_bot_turns_contact (contact_id, created_at),
  KEY idx_wa_bot_turns_created (created_at, is_test),
  KEY idx_wa_bot_turns_answered (answered, reviewed, created_at),
  KEY idx_wa_bot_turns_intent (intent),
  CONSTRAINT fk_wa_bot_turns_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_bot_turns_session FOREIGN KEY (session_id) REFERENCES wa_bot_sessions (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
