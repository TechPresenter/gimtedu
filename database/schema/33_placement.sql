-- =====================================================================
-- 33 Placement unit: company classification & tie-up status, drive
-- openings, offer response trail, training delivery mode and participant
-- remarks. Runs after 11_career.sql. Portable MySQL 8 / MariaDB 10.4+.
-- =====================================================================

ALTER TABLE companies
  ADD COLUMN company_type VARCHAR(30) NULL COMMENT 'mnc|indian_mnc|large_indian|startup|psu|government|sme|ngo' AFTER industry,
  ADD COLUMN headquarters VARCHAR(150) NULL AFTER website,
  ADD COLUMN tie_up_status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'prospect|active|mou_signed|dormant' AFTER is_mou,
  ADD COLUMN notes TEXT NULL COMMENT 'internal placement-cell notes' AFTER description,
  ADD KEY idx_companies_status (status, tie_up_status);

ALTER TABLE placement_drives
  ADD COLUMN openings SMALLINT UNSIGNED NULL COMMENT 'number of positions' AFTER job_type,
  ADD KEY idx_drives_company (company_id, drive_date);

ALTER TABLE placement_applications
  ADD KEY idx_papp_student (student_id, status);

ALTER TABLE placement_interviews
  ADD KEY idx_pint_result (application_id, result);

ALTER TABLE placement_offers
  ADD COLUMN responded_at DATE NULL COMMENT 'date the student accepted / declined' AFTER status,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER remarks,
  ADD KEY idx_offers_status_date (status, offer_date),
  ADD KEY idx_offers_drive (drive_id);

ALTER TABLE placement_trainings
  ADD COLUMN mode VARCHAR(20) NOT NULL DEFAULT 'offline' COMMENT 'offline|online|hybrid' AFTER type,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_ptrain_dates (start_date, status);

ALTER TABLE placement_training_participants
  ADD COLUMN remarks VARCHAR(255) NULL AFTER score,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER remarks;
