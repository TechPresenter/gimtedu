-- =====================================================================
-- 31 Hostel unit: contact / visiting hours on hostels, block status,
-- allotment numbers + planned end date + transfer chain + vacate audit on
-- allocations, complaint tickets with staff assignment and workflow
-- timestamps, visitor gate passes with ID proof type.
-- Runs after 10_campus.sql. Portable MySQL 8 / MariaDB 10.4+ syntax.
-- =====================================================================

ALTER TABLE hostels
  ADD COLUMN email VARCHAR(190) NULL AFTER warden_phone,
  ADD COLUMN description TEXT NULL AFTER amenities,
  ADD COLUMN visiting_hours VARCHAR(80) NULL COMMENT 'e.g. 10:00 AM - 7:00 PM' AFTER description,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE hostel_blocks
  ADD COLUMN description VARCHAR(255) NULL AFTER floors,
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'active' AFTER description,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER status;

ALTER TABLE hostel_rooms
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_rooms_layout (hostel_id, block_id, floor_no),
  ADD KEY idx_rooms_status (status);

ALTER TABLE hostel_beds
  ADD KEY idx_beds_status (status);

ALTER TABLE hostel_allocations
  ADD COLUMN allocation_no VARCHAR(30) NULL COMMENT 'allotment letter number' AFTER id,
  ADD COLUMN valid_until DATE NULL COMMENT 'planned end of stay' AFTER allocated_on,
  ADD COLUMN transferred_from_id INT UNSIGNED NULL COMMENT 'previous allocation when the student changed room' AFTER bed_id,
  ADD COLUMN vacate_reason VARCHAR(255) NULL AFTER remarks,
  ADD COLUMN vacated_by INT UNSIGNED NULL AFTER allocated_by,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD UNIQUE KEY uq_ha_no (allocation_no),
  ADD KEY idx_ha_status (status, hostel_id),
  ADD KEY idx_ha_bed (bed_id, status),
  ADD CONSTRAINT fk_ha_transferred_from FOREIGN KEY (transferred_from_id) REFERENCES hostel_allocations(id) ON DELETE SET NULL;

ALTER TABLE hostel_complaints
  ADD COLUMN complaint_no VARCHAR(30) NULL AFTER id,
  ADD COLUMN assigned_staff_id INT UNSIGNED NULL AFTER assigned_to,
  ADD COLUMN assigned_at DATETIME NULL AFTER assigned_staff_id,
  ADD COLUMN closed_at DATETIME NULL AFTER resolved_at,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER closed_at,
  ADD UNIQUE KEY uq_hc_no (complaint_no),
  ADD KEY idx_hc_hostel (hostel_id, status),
  ADD CONSTRAINT fk_hc_staff FOREIGN KEY (assigned_staff_id) REFERENCES staff(id) ON DELETE SET NULL;

ALTER TABLE hostel_visitors
  ADD COLUMN pass_no VARCHAR(30) NULL AFTER id,
  ADD COLUMN id_proof_type VARCHAR(30) NULL COMMENT 'aadhaar|driving_licence|voter_id|pan|passport|other' AFTER phone,
  ADD COLUMN visitors_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER purpose,
  ADD COLUMN vehicle_no VARCHAR(20) NULL AFTER visitors_count,
  ADD COLUMN remarks VARCHAR(255) NULL AFTER check_out,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER approved_by,
  ADD UNIQUE KEY uq_hv_pass (pass_no),
  ADD KEY idx_hv_inside (hostel_id, check_out);
