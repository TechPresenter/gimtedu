-- =====================================================================
-- 34 Alumni relations (brief section 23) — additions to the tables from 11_career.sql
--   alumni_events            event type, end time, capacity, organiser
--   alumni_event_registrations (new) alumni registered for / attending an event
--   alumni_jobs              approval workflow (pending → open → closed / rejected), skills, contact, audit columns
--   alumni_success_stories   company / designation snapshot, excerpt, published_at, ordering
--   alumni_donations         campaign, PAN (80G receipts), anonymous flag, audit columns
--   alumni_mentorships       end date, sessions held
-- Plain ALTER TABLE / CREATE TABLE statements (MySQL 8 / MariaDB 10.4+ compatible). Run once after 01–12.
-- =====================================================================

ALTER TABLE alumni
  ADD KEY idx_alumni_company (company),
  ADD KEY idx_alumni_industry (industry),
  ADD KEY idx_alumni_status_mentor (status, is_mentor);

ALTER TABLE alumni_events
  ADD COLUMN type VARCHAR(20) NOT NULL DEFAULT 'reunion' COMMENT 'reunion|homecoming|webinar|chapter_meet|networking|guest_lecture|mentorship|other' AFTER title,
  ADD COLUMN end_date DATETIME NULL AFTER event_date,
  ADD COLUMN capacity INT UNSIGNED NULL AFTER registration_link,
  ADD COLUMN organiser VARCHAR(150) NULL AFTER capacity,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER status,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_aevents_date (event_date),
  ADD KEY idx_aevents_status (status);

CREATE TABLE alumni_event_registrations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_id INT UNSIGNED NOT NULL,
  alumni_id INT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'registered' COMMENT 'registered|attended|cancelled|no_show',
  guests TINYINT UNSIGNED NOT NULL DEFAULT 0,
  registered_at DATETIME NOT NULL,
  remarks VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_aevent_reg (event_id, alumni_id),
  KEY idx_aevent_reg_alumni (alumni_id),
  CONSTRAINT fk_aevreg_event FOREIGN KEY (event_id) REFERENCES alumni_events(id) ON DELETE CASCADE,
  CONSTRAINT fk_aevreg_alumni FOREIGN KEY (alumni_id) REFERENCES alumni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE alumni_jobs
  ADD COLUMN skills VARCHAR(255) NULL AFTER salary_range,
  ADD COLUMN contact_email VARCHAR(190) NULL AFTER apply_link,
  ADD COLUMN approved_by INT UNSIGNED NULL AFTER status,
  ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
  ADD COLUMN rejection_reason VARCHAR(255) NULL AFTER approved_at,
  ADD COLUMN closed_at DATETIME NULL AFTER rejection_reason,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER closed_at,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_ajobs_status_date (status, last_date);

ALTER TABLE alumni_success_stories
  ADD COLUMN company VARCHAR(190) NULL AFTER title,
  ADD COLUMN designation VARCHAR(150) NULL AFTER company,
  ADD COLUMN excerpt VARCHAR(300) NULL AFTER designation,
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER status,
  ADD COLUMN published_at DATETIME NULL AFTER sort_order,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER published_at,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_astories_status (status, show_on_website);

ALTER TABLE alumni_donations
  ADD COLUMN campaign VARCHAR(150) NULL AFTER purpose,
  ADD COLUMN pan_no VARCHAR(10) NULL AFTER reference_no,
  ADD COLUMN is_anonymous TINYINT(1) NOT NULL DEFAULT 0 AFTER pan_no,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER remarks,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_donations_date (donation_date),
  ADD KEY idx_donations_status (status);

ALTER TABLE alumni_mentorships
  ADD COLUMN end_date DATE NULL AFTER start_date,
  ADD COLUMN sessions_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER end_date,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER notes,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_amentor_status (status);
