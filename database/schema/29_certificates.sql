-- =====================================================================
-- 29 Certificates unit additions (runs after 01-12)
--   * template design options: subtitle, border style, accent colour, font, second signatory,
--     photo / seal / QR toggles, default validity
--   * issue workflow: approval (pending -> issued | rejected), revocation audit, reissue chain,
--     print + verification counters, academic session of issue
--   * public verification log (every lookup on /verify-certificate and the JSON endpoint)
-- Portable MySQL 8 / MariaDB 10.4+ SQL.
-- =====================================================================

ALTER TABLE certificate_templates
  ADD COLUMN description VARCHAR(255) NULL COMMENT 'internal note shown in the template gallery' AFTER name,
  ADD COLUMN subtitle VARCHAR(190) NULL COMMENT 'line under the heading, e.g. To Whomsoever It May Concern' AFTER title,
  ADD COLUMN border_style VARCHAR(20) NOT NULL DEFAULT 'classic' COMMENT 'classic|double|ornate|modern|minimal' AFTER orientation,
  ADD COLUMN accent_color VARCHAR(9) NOT NULL DEFAULT '#0B2A5B' AFTER border_style,
  ADD COLUMN font_style VARCHAR(20) NOT NULL DEFAULT 'serif' COMMENT 'serif|sans|display' AFTER accent_color,
  ADD COLUMN signatory2_name VARCHAR(150) NULL AFTER signatory_designation,
  ADD COLUMN signatory2_designation VARCHAR(150) NULL AFTER signatory2_name,
  ADD COLUMN show_photo TINYINT(1) NOT NULL DEFAULT 0 AFTER signatory2_designation,
  ADD COLUMN show_seal TINYINT(1) NOT NULL DEFAULT 1 AFTER show_photo,
  ADD COLUMN show_qr TINYINT(1) NOT NULL DEFAULT 1 AFTER show_seal,
  ADD COLUMN validity_days SMALLINT UNSIGNED NULL COMMENT 'default validity in days; NULL = no expiry' AFTER show_qr,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER status,
  ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by,
  ADD KEY idx_ct_status (status);

ALTER TABLE certificates
  ADD COLUMN academic_session_id INT UNSIGNED NULL COMMENT 'session in which the certificate was issued' AFTER student_id,
  ADD COLUMN remarks VARCHAR(255) NULL COMMENT 'internal note / rejection reason' AFTER revoked_reason,
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER issued_by,
  ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
  ADD COLUMN revoked_by INT UNSIGNED NULL AFTER approved_at,
  ADD COLUMN revoked_at DATETIME NULL AFTER revoked_by,
  ADD COLUMN reissued_from_id INT UNSIGNED NULL COMMENT 'certificate this one replaces' AFTER revoked_at,
  ADD COLUMN printed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER verified_count,
  ADD COLUMN last_printed_at DATETIME NULL AFTER printed_count,
  ADD COLUMN last_verified_at DATETIME NULL AFTER last_printed_at,
  ADD KEY idx_cert_status (status),
  ADD KEY idx_cert_issue_date (issue_date),
  ADD KEY idx_cert_session (academic_session_id),
  ADD CONSTRAINT fk_cert_reissued FOREIGN KEY (reissued_from_id) REFERENCES certificates(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_cert_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL;

CREATE TABLE certificate_verifications (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  certificate_id INT UNSIGNED NULL,
  certificate_no VARCHAR(60) NOT NULL COMMENT 'number as entered (normalised)',
  result VARCHAR(20) NOT NULL COMMENT 'valid|expired|revoked|not_found',
  source VARCHAR(10) NOT NULL DEFAULT 'web' COMMENT 'web|qr|api',
  ip_address VARCHAR(45) NULL COMMENT 'masked (last octet removed)',
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cv_certificate (certificate_id),
  KEY idx_cv_created (created_at),
  KEY idx_cv_result (result),
  CONSTRAINT fk_cv_certificate FOREIGN KEY (certificate_id) REFERENCES certificates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
