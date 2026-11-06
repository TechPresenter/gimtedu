-- ============================================================================================================
-- 54_whatsapp_templates.sql — WhatsApp Template Designer / Library extras (runs after 48_whatsapp.sql).
-- description: what the template is for (shown in the library); header_media_name: file name of the sample /
-- default header document (sent as fileName); parent_id: template this one was duplicated or versioned from;
-- archived_from / archived_at: archiving is local only (Interakt has no delete API), restore puts the old status back.
-- ============================================================================================================

ALTER TABLE wa_templates
  ADD COLUMN description VARCHAR(255) NULL COMMENT 'purpose of the template (library card)' AFTER display_name,
  ADD COLUMN header_media_name VARCHAR(190) NULL COMMENT 'file name of the header document / media sample' AFTER header_media_url,
  ADD COLUMN parent_id INT UNSIGNED NULL COMMENT 'duplicated / new version of this template' AFTER is_system,
  ADD COLUMN archived_from VARCHAR(20) NULL COMMENT 'status before archiving' AFTER parent_id,
  ADD COLUMN archived_at DATETIME NULL AFTER archived_from;

ALTER TABLE wa_templates
  ADD KEY idx_wa_templates_parent (parent_id),
  ADD KEY idx_wa_templates_interakt (interakt_id),
  ADD KEY idx_wa_templates_meta (wa_template_id);
