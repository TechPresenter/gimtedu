-- =====================================================================
-- 35 Communication unit: event registration settings + check-in, campaign
-- template link + dispatch timestamps, message resend tracking and the
-- outbound delivery queue used for batched notice / campaign / event sends.
-- Runs after 01-12 (events, event_registrations, email_campaigns, message_logs).
-- =====================================================================

ALTER TABLE events
  ADD COLUMN registration_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER registration_link,
  ADD COLUMN registration_deadline DATETIME NULL AFTER registration_enabled;

ALTER TABLE event_registrations
  ADD COLUMN participant_type VARCHAR(20) NULL DEFAULT 'guest' COMMENT 'student|faculty|staff|alumni|parent|guest' AFTER organization,
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'website' COMMENT 'website|admin|import' AFTER participant_type,
  ADD COLUMN checked_in_at DATETIME NULL AFTER status,
  ADD COLUMN checked_in_by INT UNSIGNED NULL AFTER checked_in_at,
  ADD COLUMN notes VARCHAR(255) NULL AFTER checked_in_by,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_evreg_event_status (event_id, status);

ALTER TABLE email_campaigns
  ADD COLUMN template_id INT UNSIGNED NULL AFTER body_html,
  ADD COLUMN started_at DATETIME NULL AFTER scheduled_at,
  ADD COLUMN test_sent_at DATETIME NULL AFTER sent_at;

ALTER TABLE message_logs
  ADD COLUMN retry_of BIGINT UNSIGNED NULL AFTER campaign_id,
  ADD KEY idx_msglog_related (related_type, related_id),
  ADD KEY idx_msglog_retry (retry_of);

-- Outbound delivery queue: one row per (channel, recipient) of a notice / campaign / event broadcast.
-- Rows are claimed in batches (claim_token) and handed to send_mail / send_sms / send_whatsapp, which write
-- the final message_logs entry; the queue row keeps the per-dispatch progress (pending -> sent | failed).
CREATE TABLE message_queue (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id CHAR(16) NOT NULL COMMENT 'one dispatch (publish / send / reminder) = one batch',
  channel VARCHAR(20) NOT NULL DEFAULT 'email' COMMENT 'email|sms|whatsapp',
  recipient VARCHAR(190) NOT NULL,
  recipient_name VARCHAR(150) NULL,
  subject VARCHAR(255) NULL,
  body MEDIUMTEXT NULL,
  related_type VARCHAR(40) NULL,
  related_id INT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|processing|sent|failed|cancelled',
  error VARCHAR(500) NULL,
  claim_token CHAR(16) NULL,
  claimed_at DATETIME NULL,
  processed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_mq_batch (batch_id, status),
  KEY idx_mq_status (status, id),
  KEY idx_mq_related (related_type, related_id, status),
  KEY idx_mq_campaign (campaign_id, status),
  KEY idx_mq_claim (claim_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
