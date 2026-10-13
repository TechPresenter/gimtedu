-- =====================================================================
-- 22 Students unit additions (runs after 01-12)
--   * document verification workflow (pending | verified | rejected + remarks)
--   * lookup indexes used by duplicate detection (mobile / aadhaar)
--   * promotion run log (bulk semester promotions / pass-outs)
-- =====================================================================

ALTER TABLE student_documents
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|verified|rejected' AFTER size_bytes,
  ADD COLUMN remarks VARCHAR(255) NULL COMMENT 'rejection reason / verification note' AFTER status,
  ADD KEY idx_sdocs_status (status);

ALTER TABLE students
  ADD KEY idx_students_mobile (mobile),
  ADD KEY idx_students_aadhaar (aadhaar_no);

CREATE TABLE student_promotions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference_no VARCHAR(40) NOT NULL,
  program_id INT UNSIGNED NOT NULL,
  from_semester TINYINT UNSIGNED NOT NULL,
  to_semester TINYINT UNSIGNED NULL COMMENT 'NULL when the run only passed students out',
  from_section_id INT UNSIGNED NULL,
  from_session_id INT UNSIGNED NULL,
  to_session_id INT UNSIGNED NULL,
  promoted_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  detained_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  passed_out_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  skipped_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  details JSON NULL COMMENT '[{student_id, action, to_section_id}]',
  remarks VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_promotions_ref (reference_no),
  KEY idx_student_promotions_program (program_id, from_semester),
  KEY idx_student_promotions_created (created_at),
  CONSTRAINT fk_spromo_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
  CONSTRAINT fk_spromo_section FOREIGN KEY (from_section_id) REFERENCES sections(id) ON DELETE SET NULL,
  CONSTRAINT fk_spromo_from_session FOREIGN KEY (from_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_spromo_to_session FOREIGN KEY (to_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL,
  CONSTRAINT fk_spromo_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
