-- =====================================================================
-- 23 Faculty & staff management (HR): leave types + extra HR columns
-- Runs after 01-12. Plain, portable DDL (MariaDB 10.4+ / MySQL 8).
-- =====================================================================

-- Leave policy: one row per leave type. employee_leaves.leave_type stores leave_types.code.
CREATE TABLE leave_types (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(30) NOT NULL COMMENT 'casual|sick|earned|duty|maternity|unpaid ...',
  name VARCHAR(80) NOT NULL,
  annual_quota DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT 'days per academic session; 0 = no limit',
  applies_to VARCHAR(10) NOT NULL DEFAULT 'all' COMMENT 'all|faculty|staff',
  gender VARCHAR(10) NULL COMMENT 'male|female; NULL = any (maternity / paternity leave)',
  is_paid TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'unpaid leave counts as loss of pay (LOP)',
  max_consecutive SMALLINT UNSIGNED NULL COMMENT 'max working days in one application',
  color VARCHAR(20) NULL COMMENT 'blue|green|amber|purple|pink|cyan|red|slate',
  description VARCHAR(255) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active' COMMENT 'active|inactive',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_leave_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE employee_leaves
  ADD COLUMN is_half_day TINYINT(1) NOT NULL DEFAULT 0 AFTER days,
  ADD COLUMN applied_by INT UNSIGNED NULL COMMENT 'users.id who submitted (self or on behalf)' AFTER reason,
  ADD COLUMN cancelled_at DATETIME NULL AFTER remarks,
  ADD KEY idx_leaves_dates (from_date, to_date);

ALTER TABLE employee_documents
  ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|verified|rejected' AFTER size_bytes,
  ADD COLUMN verified_by INT UNSIGNED NULL AFTER status,
  ADD COLUMN verified_at DATETIME NULL AFTER verified_by,
  ADD COLUMN remarks VARCHAR(255) NULL AFTER verified_at,
  ADD COLUMN expiry_date DATE NULL AFTER remarks;

ALTER TABLE faculty
  ADD COLUMN bank_name VARCHAR(100) NULL AFTER salary;

ALTER TABLE staff
  ADD COLUMN section VARCHAR(100) NULL COMMENT 'office / section, e.g. Exam Cell, Accounts Office' AFTER department_id,
  ADD COLUMN alternate_phone VARCHAR(30) NULL AFTER phone,
  ADD COLUMN pincode VARCHAR(12) NULL AFTER state,
  ADD COLUMN bank_name VARCHAR(100) NULL AFTER salary,
  ADD COLUMN bank_account VARCHAR(40) NULL AFTER bank_name,
  ADD COLUMN bank_ifsc VARCHAR(20) NULL AFTER bank_account,
  ADD COLUMN pan_no VARCHAR(20) NULL AFTER bank_ifsc;
