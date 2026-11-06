-- ============================================================================================================
-- 53_whatsapp_contacts.sql — WhatsApp contact CRM extras (contacts part of the WhatsApp module, see docs/WHATSAPP.md)
-- Saved segments (named filter sets used by campaigns), lead-stage history, and the sync bookkeeping columns on
-- wa_contacts (stage last derived from the admission portal, ERP lead source, last sync time).
-- Plain portable SQL (MariaDB 10.4+ / MySQL 8), runs after 48_whatsapp.sql.
-- ============================================================================================================

ALTER TABLE wa_contacts
  ADD COLUMN erp_stage VARCHAR(30) NULL COMMENT 'stage last derived from enquiry / application / student data (sync)' AFTER stage_changed_at,
  ADD COLUMN lead_source VARCHAR(30) NULL COMMENT 'enquiry / application source (website, walk_in, referral, whatsapp...)' AFTER source,
  ADD COLUMN synced_at DATETIME NULL COMMENT 'last refresh from the admission portal / ERP' AFTER interakt_synced_at,
  ADD KEY idx_wa_contacts_type (contact_type, status),
  ADD KEY idx_wa_contacts_city (city),
  ADD KEY idx_wa_contacts_state (state);

ALTER TABLE wa_followup_stages
  ADD COLUMN variable_defaults LONGTEXT NULL COMMENT 'JSON {template variable key: default value} used when the contact data has no value (last_date, support_number...)' AFTER stop_conditions;

CREATE TABLE wa_segments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  filters LONGTEXT NULL COMMENT 'JSON contact filters (same keys as GET /api/whatsapp/contacts)',
  contacts_count INT UNSIGNED NOT NULL DEFAULT 0,
  opted_in_count INT UNSIGNED NOT NULL DEFAULT 0,
  counted_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wa_segments_name (name),
  CONSTRAINT fk_wa_segments_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wa_contact_stage_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  contact_id INT UNSIGNED NOT NULL,
  from_stage VARCHAR(30) NULL,
  to_stage VARCHAR(30) NOT NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual|board|bulk|sync|event|import|automation|bot',
  note VARCHAR(255) NULL,
  changed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_wa_stage_history_contact (contact_id, created_at),
  KEY idx_wa_stage_history_to (to_stage, created_at),
  CONSTRAINT fk_wa_stage_history_contact FOREIGN KEY (contact_id) REFERENCES wa_contacts (id) ON DELETE CASCADE,
  CONSTRAINT fk_wa_stage_history_user FOREIGN KEY (changed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
