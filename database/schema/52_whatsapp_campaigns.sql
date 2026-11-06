-- ============================================================================================================
-- 52_whatsapp_campaigns.sql — WhatsApp bulk campaigns extras (campaigns part of the WhatsApp module, docs/WHATSAPP.md)
-- Campaign throttle / business-hours options and lifecycle stamps, per-recipient skip reason, retry counter and reply
-- tracking, and the uploaded CSV / XLSX audience of a campaign (raw rows + column mapping; normalised at use).
-- Plain portable SQL (MariaDB 10.4+ / MySQL 8), runs after 48_whatsapp.sql.
-- ============================================================================================================

ALTER TABLE wa_campaigns
  ADD COLUMN description VARCHAR(500) NULL COMMENT 'purpose / internal note' AFTER type,
  ADD COLUMN rate_per_minute INT UNSIGNED NULL COMMENT 'max recipients queued per minute (NULL = account rate)' AFTER scheduled_at,
  ADD COLUMN respect_business_hours TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'queue messages only inside WhatsApp business hours' AFTER rate_per_minute,
  ADD COLUMN queued_count INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'recipients still waiting in the send queue' AFTER total_recipients,
  ADD COLUMN paused_at DATETIME NULL AFTER started_at,
  ADD COLUMN cancelled_at DATETIME NULL AFTER completed_at,
  ADD COLUMN last_error VARCHAR(255) NULL AFTER cancelled_at,
  ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by,
  ADD KEY idx_wa_campaigns_type (type),
  ADD KEY idx_wa_campaigns_template (template_id),
  ADD KEY idx_wa_campaigns_created (created_at);

ALTER TABLE wa_campaign_recipients
  ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'filter' COMMENT 'filter|csv' AFTER name,
  ADD COLUMN contact_type VARCHAR(20) NULL COMMENT 'snapshot of wa_contacts.contact_type at build time' AFTER source,
  ADD COLUMN skip_reason VARCHAR(30) NULL COMMENT 'no_opt_in|no_consent|opted_out|blocked|invalid_number|duplicate|missing_variables|template|cancelled' AFTER error,
  ADD COLUMN attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'send attempts (retry failed increments)' AFTER skip_reason,
  ADD COLUMN queued_at DATETIME NULL AFTER attempts,
  ADD COLUMN replied_at DATETIME NULL COMMENT 'first inbound message within 72 h of the send' AFTER queued_at,
  ADD KEY idx_wa_campaign_recipients_contact (contact_id, replied_at),
  ADD KEY idx_wa_campaign_recipients_queued (campaign_id, queued_at);

CREATE TABLE wa_campaign_uploads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  campaign_id INT UNSIGNED NOT NULL,
  file_name VARCHAR(190) NOT NULL,
  headers LONGTEXT NULL COMMENT 'JSON list of column headers',
  rows_data LONGTEXT NULL COMMENT 'JSON list of raw rows (arrays aligned with headers)',
  phone_column INT NULL COMMENT 'index of the phone column',
  name_column INT NULL COMMENT 'index of the name column',
  consent_column INT NULL COMMENT 'index of a WhatsApp consent column (yes/no)',
  total_rows INT UNSIGNED NOT NULL DEFAULT 0,
  valid_rows INT UNSIGNED NOT NULL DEFAULT 0,
  invalid_rows INT UNSIGNED NOT NULL DEFAULT 0,
  duplicate_rows INT UNSIGNED NOT NULL DEFAULT 0,
  uploaded_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_campaign_uploads_campaign (campaign_id),
  CONSTRAINT fk_wa_campaign_uploads_campaign FOREIGN KEY (campaign_id) REFERENCES wa_campaigns (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_campaign_uploads_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
