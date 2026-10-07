-- =====================================================================
-- 07 Fees & Accounts: fee heads, structures, student fees (invoices),
-- installments, concessions, scholarships, payments, receipts, refunds, expenses
-- =====================================================================

CREATE TABLE fee_heads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  code VARCHAR(20) NOT NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'tuition' COMMENT 'admission|tuition|examination|hostel|transport|library|miscellaneous',
  description VARCHAR(255) NULL,
  is_refundable TINYINT(1) NOT NULL DEFAULT 0,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fee_heads_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_structures (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL COMMENT 'e.g. BBA Year 1 (2026-27)',
  academic_session_id INT UNSIGNED NOT NULL,
  program_id INT UNSIGNED NOT NULL,
  semester_no TINYINT UNSIGNED NULL COMMENT 'NULL = yearly structure',
  year_no TINYINT UNSIGNED NULL,
  total_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  due_date DATE NULL,
  late_fee_per_day DECIMAL(10,2) NOT NULL DEFAULT 0,
  late_fee_max DECIMAL(10,2) NULL,
  installments_allowed TINYINT UNSIGNED NOT NULL DEFAULT 1,
  description VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fs_program (program_id, academic_session_id),
  CONSTRAINT fk_fstruct_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id),
  CONSTRAINT fk_fstruct_program FOREIGN KEY (program_id) REFERENCES programs(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_structure_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fee_structure_id INT UNSIGNED NOT NULL,
  fee_head_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  is_optional TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fsi (fee_structure_id, fee_head_id),
  CONSTRAINT fk_fsi_structure FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE CASCADE,
  CONSTRAINT fk_fsi_head FOREIGN KEY (fee_head_id) REFERENCES fee_heads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scholarships (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(20) NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'percentage' COMMENT 'percentage|fixed',
  value DECIMAL(10,2) NOT NULL,
  category VARCHAR(30) NOT NULL DEFAULT 'scholarship' COMMENT 'scholarship|discount|waiver|sibling|staff_ward|merit|sports',
  criteria TEXT NULL,
  max_amount DECIMAL(12,2) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A fee assigned to a student = invoice
CREATE TABLE student_fees (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_no VARCHAR(40) NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  fee_structure_id INT UNSIGNED NULL,
  academic_session_id INT UNSIGNED NULL,
  title VARCHAR(190) NOT NULL COMMENT 'e.g. Semester 1 Fee 2026-27',
  semester_no TINYINT UNSIGNED NULL,
  gross_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  scholarship_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  fine_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  net_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'gross - discount - scholarship + fine',
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  balance_amount DECIMAL(12,2) GENERATED ALWAYS AS (net_amount - paid_amount) STORED,
  due_date DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|partial|paid|overdue|waived|cancelled',
  remarks VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_student_fees_invoice (invoice_no),
  KEY idx_sf_student (student_id),
  KEY idx_sf_status_due (status, due_date),
  KEY idx_sf_session (academic_session_id),
  CONSTRAINT fk_sf_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_sf_structure FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE SET NULL,
  CONSTRAINT fk_sf_session FOREIGN KEY (academic_session_id) REFERENCES academic_sessions(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE student_fee_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_fee_id INT UNSIGNED NOT NULL,
  fee_head_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id),
  KEY idx_sfi_fee (student_fee_id),
  CONSTRAINT fk_sfi_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE CASCADE,
  CONSTRAINT fk_sfi_head FOREIGN KEY (fee_head_id) REFERENCES fee_heads(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fee_installments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_fee_id INT UNSIGNED NOT NULL,
  installment_no TINYINT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  due_date DATE NOT NULL,
  paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|partial|paid|overdue',
  PRIMARY KEY (id),
  UNIQUE KEY uq_installment (student_fee_id, installment_no),
  CONSTRAINT fk_inst_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Discounts / scholarships applied to a specific student fee
CREATE TABLE fee_concessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_fee_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NOT NULL,
  scholarship_id INT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'discount' COMMENT 'discount|scholarship|waiver',
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'approved' COMMENT 'pending|approved|rejected',
  approved_by INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_conc_fee (student_fee_id),
  CONSTRAINT fk_conc_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE CASCADE,
  CONSTRAINT fk_conc_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_conc_scholarship FOREIGN KEY (scholarship_id) REFERENCES scholarships(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  receipt_no VARCHAR(40) NOT NULL,
  student_id INT UNSIGNED NULL,
  admission_id INT UNSIGNED NULL COMMENT 'admission fee paid before student record exists',
  student_fee_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  fine_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  payment_date DATE NOT NULL,
  mode VARCHAR(20) NOT NULL DEFAULT 'cash' COMMENT 'cash|bank_transfer|cheque|dd|upi|card|online',
  reference_no VARCHAR(100) NULL COMMENT 'UTR / cheque / transaction id',
  bank_name VARCHAR(100) NULL,
  gateway VARCHAR(40) NULL COMMENT 'razorpay|payu|ccavenue ...',
  gateway_txn_id VARCHAR(100) NULL,
  gateway_response JSON NULL,
  purpose VARCHAR(30) NOT NULL DEFAULT 'fee' COMMENT 'fee|admission|hostel|transport|library_fine|exam|other',
  status VARCHAR(20) NOT NULL DEFAULT 'success' COMMENT 'success|pending|failed|refunded|cancelled',
  remarks VARCHAR(255) NULL,
  collected_by INT UNSIGNED NULL,
  cancelled_by INT UNSIGNED NULL,
  cancelled_reason VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_payments_receipt (receipt_no),
  KEY idx_payments_student (student_id),
  KEY idx_payments_date (payment_date),
  KEY idx_payments_status (status),
  KEY idx_payments_fee (student_fee_id),
  CONSTRAINT fk_payments_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_admission FOREIGN KEY (admission_id) REFERENCES admissions(id) ON DELETE SET NULL,
  CONSTRAINT fk_payments_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE admissions ADD CONSTRAINT fk_admissions_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL;

CREATE TABLE payment_receipts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id INT UNSIGNED NOT NULL,
  receipt_no VARCHAR(40) NOT NULL,
  issued_at DATETIME NOT NULL,
  printed_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  last_printed_at DATETIME NULL,
  emailed_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipts_payment (payment_id),
  CONSTRAINT fk_receipts_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  refund_no VARCHAR(40) NOT NULL,
  payment_id INT UNSIGNED NOT NULL,
  student_id INT UNSIGNED NULL,
  amount DECIMAL(12,2) NOT NULL,
  reason VARCHAR(255) NOT NULL,
  refund_date DATE NULL,
  mode VARCHAR(20) NULL,
  reference_no VARCHAR(100) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|processed|rejected',
  requested_by INT UNSIGNED NULL,
  approved_by INT UNSIGNED NULL,
  processed_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_refund_no (refund_no),
  CONSTRAINT fk_refunds_payment FOREIGN KEY (payment_id) REFERENCES payments(id),
  CONSTRAINT fk_refunds_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expense_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  description VARCHAR(255) NULL,
  budget_amount DECIMAL(14,2) NULL COMMENT 'annual budget',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expcat_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE expenses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  expense_no VARCHAR(40) NOT NULL,
  category_id INT UNSIGNED NOT NULL,
  title VARCHAR(190) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  expense_date DATE NOT NULL,
  payment_mode VARCHAR(20) NULL,
  vendor VARCHAR(150) NULL,
  reference_no VARCHAR(100) NULL,
  department_id INT UNSIGNED NULL,
  attachment VARCHAR(255) NULL,
  description TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|approved|paid|rejected',
  approved_by INT UNSIGNED NULL,
  approved_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_expense_no (expense_no),
  KEY idx_expenses_date (expense_date),
  CONSTRAINT fk_expenses_category FOREIGN KEY (category_id) REFERENCES expense_categories(id),
  CONSTRAINT fk_expenses_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
