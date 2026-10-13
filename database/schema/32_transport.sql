-- =====================================================================
-- 32 Transport unit: extra columns for drivers (licence class, police
-- verification), stops (distance, landmark), allocations (transfer /
-- cancellation trail), maintenance (bill no, km-based due) and the
-- complaint workflow (number, category, priority, assignment, resolution).
-- Runs after 10_campus.sql. Portable MySQL 8 / MariaDB 10.4+ syntax.
-- =====================================================================

ALTER TABLE drivers
  ADD COLUMN emergency_phone VARCHAR(20) NULL AFTER phone,
  ADD COLUMN license_type VARCHAR(20) NULL COMMENT 'LMV|HMV|HPMV|transport|conductor' AFTER license_no,
  ADD COLUMN joining_date DATE NULL AFTER experience_years,
  ADD COLUMN police_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER joining_date,
  ADD COLUMN remarks VARCHAR(255) NULL AFTER status,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_drivers_type_status (type, status);

ALTER TABLE transport_routes
  ADD COLUMN description VARCHAR(255) NULL AFTER end_point,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

ALTER TABLE vehicles
  ADD COLUMN remarks VARCHAR(255) NULL AFTER current_odometer,
  ADD KEY idx_vehicles_route_status (route_id, status);

ALTER TABLE transport_stops
  ADD COLUMN landmark VARCHAR(150) NULL AFTER name,
  ADD COLUMN distance_km DECIMAL(6,1) NULL COMMENT 'distance from the route start' AFTER drop_time;

ALTER TABLE transport_allocations
  ADD COLUMN transferred_from_id INT UNSIGNED NULL AFTER student_fee_id,
  ADD COLUMN remarks VARCHAR(255) NULL AFTER status,
  ADD COLUMN cancelled_at DATETIME NULL AFTER remarks,
  ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER cancelled_at,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER cancelled_by,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_ta_stop (stop_id, status),
  ADD KEY idx_ta_session (academic_session_id, status),
  ADD KEY idx_ta_faculty (faculty_id),
  ADD KEY idx_ta_staff (staff_id),
  ADD CONSTRAINT fk_ta_transferred FOREIGN KEY (transferred_from_id) REFERENCES transport_allocations(id) ON DELETE SET NULL;

ALTER TABLE transport_fuel
  ADD COLUMN remarks VARCHAR(255) NULL AFTER bill_no;

ALTER TABLE transport_maintenance
  ADD COLUMN bill_no VARCHAR(60) NULL AFTER vendor,
  ADD COLUMN next_service_km INT UNSIGNED NULL AFTER next_service_date,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_maint_next (next_service_date),
  ADD KEY idx_maint_status (status);

ALTER TABLE transport_complaints
  ADD COLUMN complaint_no VARCHAR(30) NULL AFTER id,
  ADD COLUMN category VARCHAR(30) NOT NULL DEFAULT 'other' COMMENT 'delay|driver_behaviour|rash_driving|overcrowding|cleanliness|breakdown|route_change|fee|other' AFTER phone,
  ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'medium' COMMENT 'low|medium|high|urgent' AFTER category,
  ADD COLUMN assigned_to INT UNSIGNED NULL AFTER resolution,
  ADD COLUMN resolved_at DATETIME NULL AFTER assigned_to,
  ADD COLUMN resolved_by INT UNSIGNED NULL AFTER resolved_at,
  ADD COLUMN created_by INT UNSIGNED NULL AFTER resolved_by,
  ADD UNIQUE KEY uq_tc_no (complaint_no),
  ADD KEY idx_tc_status (status, created_at);
