-- =====================================================================
-- 27 Fees & accounts unit: installment plans on fee structures, payment
-- allocations (one receipt across several invoices), invoice cancellation /
-- late fee / reminder tracking, collection-time discounts, refund workflow
-- timestamps and expense tax + approval remarks.
-- Runs after 07_fees.sql. Portable MySQL 8 / MariaDB 10.4+ syntax.
-- =====================================================================

-- Installment plan template of a fee structure (percentages of the structure total).
CREATE TABLE fee_structure_installments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  fee_structure_id INT UNSIGNED NOT NULL,
  installment_no TINYINT UNSIGNED NOT NULL,
  label VARCHAR(60) NULL,
  percentage DECIMAL(5,2) NOT NULL,
  due_date DATE NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fs_installment (fee_structure_id, installment_no),
  CONSTRAINT fk_fsinst_structure FOREIGN KEY (fee_structure_id) REFERENCES fee_structures(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- How a payment (receipt) is split across invoices. amount includes fine_amount (late fee part).
CREATE TABLE payment_allocations (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  payment_id INT UNSIGNED NOT NULL,
  student_fee_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  fine_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_alloc_payment (payment_id),
  KEY idx_alloc_fee (student_fee_id),
  CONSTRAINT fk_alloc_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE,
  CONSTRAINT fk_alloc_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE student_fees
  ADD COLUMN late_fee_upto DATE NULL COMMENT 'late fee charged up to this date' AFTER fine_amount,
  ADD COLUMN cancelled_reason VARCHAR(255) NULL AFTER remarks,
  ADD COLUMN cancelled_by INT UNSIGNED NULL AFTER cancelled_reason,
  ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_by,
  ADD COLUMN reminder_count SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER cancelled_at,
  ADD COLUMN last_reminder_at DATETIME NULL AFTER reminder_count;

ALTER TABLE payments
  ADD COLUMN discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0 COMMENT 'concession given at the counter' AFTER fine_amount,
  ADD COLUMN cancelled_at DATETIME NULL AFTER cancelled_reason;

ALTER TABLE fee_concessions
  ADD COLUMN payment_id INT UNSIGNED NULL COMMENT 'discount given while collecting this payment' AFTER scholarship_id,
  ADD KEY idx_conc_payment (payment_id),
  ADD KEY idx_conc_scholarship (scholarship_id),
  ADD CONSTRAINT fk_conc_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL;

ALTER TABLE refunds
  ADD COLUMN remarks VARCHAR(255) NULL AFTER reference_no,
  ADD COLUMN approved_at DATETIME NULL AFTER approved_by,
  ADD COLUMN processed_at DATETIME NULL AFTER processed_by,
  ADD KEY idx_refunds_status (status);

ALTER TABLE expenses
  ADD COLUMN tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount,
  ADD COLUMN remarks VARCHAR(255) NULL COMMENT 'approver / rejection remarks' AFTER status,
  ADD COLUMN paid_at DATETIME NULL AFTER approved_at,
  ADD KEY idx_expenses_status (status),
  ADD KEY idx_expenses_category (category_id, expense_date);
