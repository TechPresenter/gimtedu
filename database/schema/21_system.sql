-- =====================================================================
-- GIMT SmartCampus - 21 System administration additions
-- (runs after 01_core.sql; plain ALTERs so it works on MySQL 8 and MariaDB 10.4+)
-- =====================================================================

-- Backup catalogue: origin of each backup, integrity checksum and run statistics.
ALTER TABLE backups
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual|auto|pre-restore|seed' AFTER type,
  ADD COLUMN checksum CHAR(64) NULL COMMENT 'SHA-256 of the backup file' AFTER size_bytes,
  ADD COLUMN meta JSON NULL COMMENT 'tables, rows, files, duration_ms, server info' AFTER error,
  ADD COLUMN restore_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER restored_by;

ALTER TABLE backups
  ADD KEY idx_backups_status (status, created_at);

-- Faster security dashboards (failed attempts per user / per day) and audit filters.
ALTER TABLE login_logs
  ADD KEY idx_login_created (created_at);

ALTER TABLE activity_logs
  ADD KEY idx_activity_action (action, created_at),
  ADD KEY idx_activity_status (status, created_at);
