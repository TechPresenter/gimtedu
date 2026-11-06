-- ============================================================================================================
-- 48_whatsapp.sql — WhatsApp Automation & Student Communication System (Interakt WhatsApp Business API)
-- Contacts CRM, shared inbox, templates, event engine, workflow automation, admission follow-ups, campaigns,
-- AI-bot sessions, webhook / API logs and the send queue. All tables are prefixed wa_. JSON is stored in LONGTEXT
-- (portable across MariaDB 10.4+ and MySQL 8). Phone numbers are stored as digits with the country code
-- (E.164 without "+", e.g. 919876543210); Interakt wants countryCode "+91" + phoneNumber "9876543210".
-- Settings live in `settings` (group "whatsapp", keys wa_* / interakt_*), permissions in app/registry.php.
-- ============================================================================================================

-- ---------------------------------------------------------------------------------------------- contacts / CRM
CREATE TABLE wa_contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  phone VARCHAR(20) NOT NULL COMMENT 'digits with country code, no + (919876543210)',
  country_code VARCHAR(6) NOT NULL DEFAULT '+91',
  national_number VARCHAR(15) NOT NULL COMMENT 'number without the country code (9876543210)',
  name VARCHAR(150) NULL,
  email VARCHAR(190) NULL,
  alt_phone VARCHAR(20) NULL,
  contact_type VARCHAR(20) NOT NULL DEFAULT 'lead' COMMENT 'lead|applicant|student|guardian|alumni|staff|other',
  student_id INT UNSIGNED NULL,
  enquiry_id INT UNSIGNED NULL,
  admission_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL COMMENT 'staff user when the contact is a team member',
  application_no VARCHAR(40) NULL,
  program_id INT UNSIGNED NULL,
  program_name VARCHAR(190) NULL COMMENT 'denormalised for lists / templates',
  batch VARCHAR(80) NULL,
  city VARCHAR(80) NULL,
  state VARCHAR(80) NULL,
  stage VARCHAR(30) NOT NULL DEFAULT 'new_enquiry' COMMENT 'admission follow-up stage key (wa_followup_stages.stage_key)',
  stage_changed_at DATETIME NULL,
  admission_status VARCHAR(40) NULL,
  payment_status VARCHAR(40) NULL,
  counsellor_id INT UNSIGNED NULL,
  language VARCHAR(5) NOT NULL DEFAULT 'en' COMMENT 'en|hi',
  opt_in TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'WhatsApp opt-in (consent); marketing sends require 1',
  opt_in_at DATETIME NULL,
  opt_in_source VARCHAR(80) NULL COMMENT 'enquiry form consent / import / inbound message / manual',
  opted_out_at DATETIME NULL,
  source VARCHAR(60) NULL COMMENT 'website|landing|apply|import|inbound|manual|sync',
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|blocked|archived',
  interakt_user_id VARCHAR(64) NULL,
  interakt_synced_at DATETIME NULL,
  last_inbound_at DATETIME NULL COMMENT 'opens the 24-hour customer service window',
  last_outbound_at DATETIME NULL,
  last_message_at DATETIME NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_contacts_phone (phone),
  KEY idx_wa_contacts_student (student_id),
  KEY idx_wa_contacts_enquiry (enquiry_id),
  KEY idx_wa_contacts_admission (admission_id),
  KEY idx_wa_contacts_stage (stage),
  KEY idx_wa_contacts_counsellor (counsellor_id),
  KEY idx_wa_contacts_program (program_id),
  KEY idx_wa_contacts_last_msg (last_message_at),
  CONSTRAINT fk_wa_contacts_student FOREIGN KEY (student_id) REFERENCES students (id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_contacts_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_contacts_admission FOREIGN KEY (admission_id) REFERENCES admissions (id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_contacts_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_contacts_counsellor FOREIGN KEY (counsellor_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_wa_contacts_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_tags (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(60) NOT NULL,
  color VARCHAR(20) NULL COMMENT 'badge colour key (blue|green|amber|red|violet|slate…)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_tags_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_contact_tags (
  contact_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (contact_id, tag_id),
  KEY idx_wa_contact_tags_tag (tag_id),
  CONSTRAINT fk_wa_contact_tags_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_contact_tags_tag FOREIGN KEY (tag_id) REFERENCES wa_tags (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- shared inbox
CREATE TABLE wa_conversations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id INT UNSIGNED NOT NULL COMMENT 'one thread per contact',
  status VARCHAR(20) NOT NULL DEFAULT 'open' COMMENT 'open|pending|resolved',
  assigned_to INT UNSIGNED NULL,
  unread_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_message_at DATETIME NULL,
  last_message_preview VARCHAR(255) NULL,
  last_message_direction CHAR(3) NULL COMMENT 'in|out',
  last_inbound_at DATETIME NULL,
  bot_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'AI bot answers until a human takes over',
  bot_handover_at DATETIME NULL,
  resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_conversations_contact (contact_id),
  KEY idx_wa_conversations_status (status, last_message_at),
  KEY idx_wa_conversations_assigned (assigned_to),
  CONSTRAINT fk_wa_conversations_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_conversations_assigned FOREIGN KEY (assigned_to) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_messages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NOT NULL,
  direction VARCHAR(3) NOT NULL COMMENT 'in|out',
  type VARCHAR(30) NOT NULL DEFAULT 'text' COMMENT 'text|image|video|document|audio|sticker|location|contacts|interactive_button|interactive_list|button_reply|list_reply|template|system',
  body TEXT NULL COMMENT 'text / caption / rendered template text',
  media_url VARCHAR(500) NULL COMMENT 'public URL sent to / received from Interakt',
  media_local VARCHAR(255) NULL COMMENT 'copy stored under assets/uploads/whatsapp/…',
  media_mime VARCHAR(100) NULL,
  file_name VARCHAR(190) NULL,
  interactive LONGTEXT NULL COMMENT 'JSON: buttons / list sections / location / contacts payload',
  template_id INT UNSIGNED NULL,
  template_name VARCHAR(190) NULL,
  template_vars LONGTEXT NULL COMMENT 'JSON: {header:[], body:[], buttons:{}}',
  interakt_id VARCHAR(64) NULL COMMENT 'Interakt message id (send response id / webhook data.message.id)',
  callback_data VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'queued' COMMENT 'queued|sent|delivered|read|failed|received|simulated|cancelled',
  error_code VARCHAR(40) NULL,
  error TEXT NULL,
  cost DECIMAL(10,4) NULL COMMENT 'meta_data.message_cost.actual_message_cost from webhooks',
  sender_type VARCHAR(20) NOT NULL DEFAULT 'agent' COMMENT 'contact|agent|bot|automation|event|campaign|followup|system',
  sent_by INT UNSIGNED NULL COMMENT 'staff user for agent messages',
  campaign_id INT UNSIGNED NULL,
  workflow_run_id BIGINT UNSIGNED NULL,
  event_log_id BIGINT UNSIGNED NULL,
  followup_id INT UNSIGNED NULL,
  reply_to_id BIGINT UNSIGNED NULL,
  raw LONGTEXT NULL COMMENT 'JSON: last request (redacted) / inbound webhook payload',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  sent_at DATETIME NULL,
  delivered_at DATETIME NULL,
  read_at DATETIME NULL,
  failed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_messages_interakt (interakt_id),
  KEY idx_wa_messages_conversation (conversation_id, id),
  KEY idx_wa_messages_contact (contact_id, created_at),
  KEY idx_wa_messages_status (status, created_at),
  KEY idx_wa_messages_campaign (campaign_id),
  KEY idx_wa_messages_template (template_id),
  KEY idx_wa_messages_run (workflow_run_id),
  KEY idx_wa_messages_created (created_at),
  CONSTRAINT fk_wa_messages_conversation FOREIGN KEY (conversation_id) REFERENCES wa_conversations (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_messages_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_messages_sent_by FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id BIGINT UNSIGNED NOT NULL,
  priority TINYINT UNSIGNED NOT NULL DEFAULT 5 COMMENT '1 = highest (agent replies, OTP), 9 = bulk campaigns',
  scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|done|failed|cancelled',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  locked_at DATETIME NULL,
  last_error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_outbox_message (message_id),
  KEY idx_wa_outbox_due (status, scheduled_at, priority),
  CONSTRAINT fk_wa_outbox_message FOREIGN KEY (message_id) REFERENCES wa_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_notes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  conversation_id INT UNSIGNED NULL,
  contact_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_notes_contact (contact_id, created_at),
  CONSTRAINT fk_wa_notes_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_notes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_quick_replies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  shortcut VARCHAR(40) NOT NULL COMMENT 'typed as /shortcut in the composer',
  title VARCHAR(120) NOT NULL,
  body TEXT NOT NULL,
  category VARCHAR(60) NULL,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_quick_replies_shortcut (shortcut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_agent_activity (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  conversation_id INT UNSIGNED NULL,
  contact_id INT UNSIGNED NULL,
  action VARCHAR(40) NOT NULL COMMENT 'reply|assign|unassign|resolve|reopen|note|tag|untag|handover|template|view',
  detail VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_agent_activity_user (user_id, created_at),
  KEY idx_wa_agent_activity_conversation (conversation_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- templates
CREATE TABLE wa_templates (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(190) NOT NULL COMMENT 'Interakt code name used on send (lowercase_underscore)',
  display_name VARCHAR(190) NULL,
  category VARCHAR(20) NOT NULL DEFAULT 'UTILITY' COMMENT 'MARKETING|UTILITY|AUTHENTICATION',
  language VARCHAR(10) NOT NULL DEFAULT 'en' COMMENT 'send code (en, hi, en_US…)',
  header_type VARCHAR(10) NOT NULL DEFAULT 'none' COMMENT 'none|text|image|video|document',
  header_text VARCHAR(60) NULL,
  header_media_url VARCHAR(500) NULL COMMENT 'sample / default media URL',
  header_handle TEXT NULL COMMENT 'Interakt upload_to_fb file handle',
  body TEXT NOT NULL COMMENT 'with positional {{1}}..{{n}}',
  footer VARCHAR(60) NULL,
  buttons LONGTEXT NULL COMMENT 'JSON [{type: QUICK_REPLY|URL|PHONE_NUMBER|COPY_CODE|OTP, text, url, phone_number, example}]',
  variables LONGTEXT NULL COMMENT 'JSON {header:[{pos,key,sample}], body:[{pos,key,sample}], buttons:{index:{key,sample}}} — key = GIMT variable e.g. student_name',
  otp_settings LONGTEXT NULL COMMENT 'JSON {code_expiration_minutes, add_security_recommendation, otp_type}',
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|pending|approved|rejected|paused|disabled|archived',
  interakt_id VARCHAR(64) NULL,
  wa_template_id VARCHAR(64) NULL COMMENT 'Meta template id',
  rejection_reason TEXT NULL,
  submitted_at DATETIME NULL,
  approved_at DATETIME NULL,
  synced_at DATETIME NULL,
  usage_count INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at DATETIME NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_templates_name_lang (name, language),
  KEY idx_wa_templates_status (status, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- event engine
CREATE TABLE wa_events (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(80) NOT NULL COMMENT 'e.g. GIMT_ADMISSION_CONFIRMED',
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'custom' COMMENT 'enquiry|registration|admission|course|payment|exam|certificate|support|staff|custom',
  source VARCHAR(30) NOT NULL DEFAULT 'admin' COMMENT 'website|admission_portal|payment|admin|api|webhook|js|cron',
  conditions LONGTEXT NULL COMMENT 'JSON [{field, op, value}] — all must match',
  template_id INT UNSIGNED NULL,
  recipient VARCHAR(30) NOT NULL DEFAULT 'contact' COMMENT 'contact|student|guardian|counsellor|custom_field',
  recipient_field VARCHAR(80) NULL COMMENT 'payload key holding the phone when recipient = custom_field',
  variable_map LONGTEXT NULL COMMENT 'JSON {template var key: payload key or literal}',
  delay_minutes INT UNSIGNED NOT NULL DEFAULT 0,
  respect_business_hours TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'built-in event fired by GIMT code (cannot be deleted)',
  sample_payload LONGTEXT NULL COMMENT 'JSON example payload (for testing + integration docs)',
  last_executed_at DATETIME NULL,
  execution_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_events_code (code),
  KEY idx_wa_events_category (category, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_event_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_code VARCHAR(80) NOT NULL,
  source VARCHAR(30) NULL,
  payload LONGTEXT NULL COMMENT 'JSON',
  dedupe_key VARCHAR(190) NULL COMMENT 'duplicate-event prevention (unique)',
  contact_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'received' COMMENT 'received|processed|skipped|duplicate|failed',
  actions LONGTEXT NULL COMMENT 'JSON list of what was triggered (message ids, workflow runs, follow-ups)',
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_event_logs_dedupe (dedupe_key),
  KEY idx_wa_event_logs_code (event_code, created_at),
  KEY idx_wa_event_logs_status (status, id),
  KEY idx_wa_event_logs_contact (contact_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- workflows
CREATE TABLE wa_workflows (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  trigger_type VARCHAR(20) NOT NULL DEFAULT 'event' COMMENT 'event|manual|stage|schedule',
  trigger_event VARCHAR(80) NULL COMMENT 'wa_events.code when trigger_type = event',
  trigger_config LONGTEXT NULL COMMENT 'JSON (stage key, schedule, filters)',
  definition LONGTEXT NULL COMMENT 'JSON {nodes:[{id,type,x,y,data}], edges:[{from,to,label}]}',
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|active|inactive',
  business_hours_only TINYINT(1) NOT NULL DEFAULT 0,
  allow_reentry TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = one active run per contact',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  runs_total INT UNSIGNED NOT NULL DEFAULT 0,
  runs_completed INT UNSIGNED NOT NULL DEFAULT 0,
  runs_failed INT UNSIGNED NOT NULL DEFAULT 0,
  last_run_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_workflows_trigger (status, trigger_event)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_workflow_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  workflow_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NULL,
  event_log_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'running' COMMENT 'running|waiting|completed|failed|cancelled',
  current_node VARCHAR(64) NULL,
  context LONGTEXT NULL COMMENT 'JSON: payload + variables + node outputs',
  next_run_at DATETIME NULL COMMENT 'when the runner should resume (delay / wait-for-reply timeout)',
  waiting_for VARCHAR(20) NULL COMMENT 'delay|reply|business_hours',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error TEXT NULL,
  dedupe_key VARCHAR(190) NULL,
  is_test TINYINT(1) NOT NULL DEFAULT 0,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME NULL,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_workflow_runs_dedupe (dedupe_key),
  KEY idx_wa_workflow_runs_due (status, next_run_at),
  KEY idx_wa_workflow_runs_workflow (workflow_id, status),
  KEY idx_wa_workflow_runs_contact (contact_id, status),
  CONSTRAINT fk_wa_workflow_runs_workflow FOREIGN KEY (workflow_id) REFERENCES wa_workflows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_workflow_steps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_id BIGINT UNSIGNED NOT NULL,
  node_id VARCHAR(64) NOT NULL,
  node_type VARCHAR(30) NOT NULL,
  status VARCHAR(20) NOT NULL COMMENT 'ok|waiting|skipped|failed',
  output LONGTEXT NULL COMMENT 'JSON',
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_workflow_steps_run (run_id, id),
  CONSTRAINT fk_wa_workflow_steps_run FOREIGN KEY (run_id) REFERENCES wa_workflow_runs (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- admission follow-ups
CREATE TABLE wa_followup_stages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  stage_key VARCHAR(30) NOT NULL COMMENT 'new_enquiry|contacted|interested|counselling_scheduled|application_started|application_submitted|payment_pending|admission_confirmed|not_interested',
  name VARCHAR(80) NOT NULL,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  color VARCHAR(20) NULL,
  template_id INT UNSIGNED NULL,
  delay_hours INT UNSIGNED NOT NULL DEFAULT 24 COMMENT 'wait after entering the stage / after the previous follow-up',
  max_followups TINYINT UNSIGNED NOT NULL DEFAULT 3,
  counsellor_id INT UNSIGNED NULL COMMENT 'auto-assign on entering the stage',
  next_action VARCHAR(190) NULL COMMENT 'task text for the counsellor',
  stop_conditions LONGTEXT NULL COMMENT 'JSON e.g. {"on_reply":true,"on_stage_change":true,"on_opt_out":true}',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_terminal TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'admission_confirmed / not_interested: no follow-ups',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_followup_stages_key (stage_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_followups (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id INT UNSIGNED NOT NULL,
  stage_key VARCHAR(30) NOT NULL,
  attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
  template_id INT UNSIGNED NULL,
  due_at DATETIME NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'scheduled' COMMENT 'scheduled|sent|skipped|cancelled|failed',
  message_id BIGINT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_followups_due (status, due_at),
  KEY idx_wa_followups_contact (contact_id, stage_key),
  CONSTRAINT fk_wa_followups_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- campaigns
CREATE TABLE wa_campaigns (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  type VARCHAR(40) NOT NULL DEFAULT 'announcement' COMMENT 'admission|course_promotion|scholarship|admission_closing|exam|fee_reminder|event|alumni|festival|announcement',
  template_id INT UNSIGNED NULL,
  audience LONGTEXT NULL COMMENT 'JSON filters {programs:[], stages:[], admission_status:[], cities:[], batches:[], tags:[], contact_types:[], opt_in_only:true}',
  audience_source VARCHAR(20) NOT NULL DEFAULT 'filters' COMMENT 'filters|csv|mixed',
  variable_map LONGTEXT NULL COMMENT 'JSON {template var key: contact field | csv column | literal}',
  scheduled_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|scheduled|sending|completed|paused|cancelled|failed',
  total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
  sent_count INT UNSIGNED NOT NULL DEFAULT 0,
  delivered_count INT UNSIGNED NOT NULL DEFAULT 0,
  read_count INT UNSIGNED NOT NULL DEFAULT 0,
  failed_count INT UNSIGNED NOT NULL DEFAULT 0,
  replied_count INT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count INT UNSIGNED NOT NULL DEFAULT 0,
  interakt_campaign_id VARCHAR(64) NULL,
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_campaigns_status (status, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_campaign_recipients (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id INT UNSIGNED NOT NULL,
  contact_id INT UNSIGNED NULL,
  phone VARCHAR(20) NOT NULL,
  name VARCHAR(150) NULL,
  variables LONGTEXT NULL COMMENT 'JSON resolved values / csv row',
  message_id BIGINT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|queued|sent|delivered|read|failed|skipped',
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_campaign_recipients (campaign_id, phone),
  KEY idx_wa_campaign_recipients_status (campaign_id, status),
  KEY idx_wa_campaign_recipients_message (message_id),
  CONSTRAINT fk_wa_campaign_recipients_campaign FOREIGN KEY (campaign_id) REFERENCES wa_campaigns (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- AI bot
CREATE TABLE wa_bot_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id INT UNSIGNED NOT NULL,
  language VARCHAR(5) NOT NULL DEFAULT 'en',
  state VARCHAR(30) NOT NULL DEFAULT 'active' COMMENT 'active|qualifying|handed_over|closed',
  qualification LONGTEXT NULL COMMENT 'JSON collected lead details (name, program, city, qualification, intake)',
  summary TEXT NULL COMMENT 'conversation summary for the counsellor',
  messages_count INT UNSIGNED NOT NULL DEFAULT 0,
  handed_over_at DATETIME NULL,
  handover_reason VARCHAR(190) NULL,
  last_activity_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_bot_sessions_contact (contact_id),
  CONSTRAINT fk_wa_bot_sessions_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------------------------- integration logs
CREATE TABLE wa_webhook_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(60) NULL,
  signature_valid TINYINT(1) NULL,
  payload LONGTEXT NULL COMMENT 'raw request body',
  headers TEXT NULL,
  interakt_message_id VARCHAR(64) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'received' COMMENT 'received|processed|ignored|failed',
  error TEXT NULL,
  is_simulated TINYINT(1) NOT NULL DEFAULT 0,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_wa_webhook_logs_status (status, id),
  KEY idx_wa_webhook_logs_type (event_type, received_at),
  KEY idx_wa_webhook_logs_message (interakt_message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_api_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  endpoint VARCHAR(190) NOT NULL,
  method VARCHAR(8) NOT NULL DEFAULT 'POST',
  request LONGTEXT NULL COMMENT 'JSON (no credentials)',
  response LONGTEXT NULL,
  http_status SMALLINT UNSIGNED NULL,
  ok TINYINT(1) NOT NULL DEFAULT 0,
  duration_ms INT UNSIGNED NULL,
  message_id BIGINT UNSIGNED NULL,
  mode VARCHAR(10) NOT NULL DEFAULT 'live' COMMENT 'live|test (test = not sent to Interakt)',
  error TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_api_logs_created (created_at),
  KEY idx_wa_api_logs_ok (ok, created_at),
  KEY idx_wa_api_logs_message (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_api_tokens (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  token_hash CHAR(64) NOT NULL COMMENT 'sha256 of the token; the token is shown once',
  token_last4 CHAR(4) NOT NULL,
  scopes VARCHAR(190) NOT NULL DEFAULT 'events' COMMENT 'comma list: events,contacts,messages',
  last_used_at DATETIME NULL,
  last_used_ip VARCHAR(45) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_api_tokens_hash (token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
