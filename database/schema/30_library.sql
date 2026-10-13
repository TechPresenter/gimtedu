-- =====================================================================
-- 30 Library unit: multi-author books, shelf location, copy remarks,
-- member remarks, return condition + overdue reminders, fine settlement
-- details (receipt, waiver audit). Runs after 10_campus.sql.
-- =====================================================================

CREATE TABLE book_authors (
  book_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NOT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (book_id, author_id),
  KEY idx_book_authors_author (author_id),
  CONSTRAINT fk_book_authors_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
  CONSTRAINT fk_book_authors_author FOREIGN KEY (author_id) REFERENCES authors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE books
  ADD COLUMN shelf_no VARCHAR(30) NULL AFTER rack_no,
  ADD KEY idx_books_category (category_id, status);

ALTER TABLE book_copies
  ADD COLUMN remarks VARCHAR(255) NULL AFTER status,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_copies_barcode (barcode);

ALTER TABLE library_members
  ADD COLUMN remarks VARCHAR(255) NULL AFTER status,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_members_type_status (member_type, status);

ALTER TABLE library_transactions
  ADD COLUMN return_condition VARCHAR(20) NULL COMMENT 'good|damaged' AFTER return_date,
  ADD COLUMN reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER remarks,
  ADD COLUMN last_reminded_at DATETIME NULL AFTER reminder_count,
  ADD KEY idx_lt_issue_date (issue_date);

ALTER TABLE library_fines
  ADD COLUMN days_overdue SMALLINT UNSIGNED NULL AFTER reason,
  ADD COLUMN receipt_no VARCHAR(40) NULL AFTER payment_mode,
  ADD COLUMN waived_by INT UNSIGNED NULL AFTER collected_by,
  ADD COLUMN waived_at DATETIME NULL AFTER waived_by,
  ADD COLUMN waive_reason VARCHAR(255) NULL AFTER waived_at,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY idx_fines_status_created (status, created_at),
  ADD KEY idx_fines_receipt (receipt_no);
