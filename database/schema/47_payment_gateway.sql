-- Online fee payments through a payment gateway (Cashfree Payment Links — app/services/cashfree.php).
-- One row per payment attempt started from the Student Portal; the receipt is created in `payments` (fees_collect())
-- once the gateway confirms the payment (return page or webhook — whichever comes first, idempotent).

CREATE TABLE gateway_orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_ref VARCHAR(45) NOT NULL COMMENT 'our link / order id sent to the gateway, e.g. GIMT-PAY-2026-00012',
  gateway VARCHAR(20) NOT NULL DEFAULT 'cashfree',
  mode VARCHAR(10) NOT NULL DEFAULT 'test' COMMENT 'test|live (gateway_mode when the order was created)',
  student_id INT UNSIGNED NOT NULL,
  student_fee_id INT UNSIGNED NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  status VARCHAR(20) NOT NULL DEFAULT 'created' COMMENT 'created|paid|failed|expired|cancelled|review',
  link_url VARCHAR(500) NULL,
  gateway_ref VARCHAR(64) NULL COMMENT 'gateway link / order id (cf_link_id)',
  gateway_txn_id VARCHAR(100) NULL COMMENT 'gateway order / transaction id of the successful payment',
  payment_id INT UNSIGNED NULL COMMENT 'payments.id of the receipt once recorded',
  status_note VARCHAR(255) NULL,
  response LONGTEXT NULL COMMENT 'last gateway response (JSON)',
  paid_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gateway_orders_ref (order_ref),
  KEY idx_gateway_orders_student (student_id),
  KEY idx_gateway_orders_fee (student_fee_id),
  KEY idx_gateway_orders_status (status),
  CONSTRAINT fk_gateway_orders_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_gateway_orders_fee FOREIGN KEY (student_fee_id) REFERENCES student_fees(id) ON DELETE CASCADE,
  CONSTRAINT fk_gateway_orders_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
