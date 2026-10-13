-- =====================================================================
-- GIMT SmartCampus - 41 Dashboard unit: internal messaging (threads, replies,
-- multi-recipient batches, stars) and indexes for the global search.
-- Runs after 01-12 (+ add-on files); plain ALTERs, portable to MySQL 8 / MariaDB 10.4+.
-- =====================================================================

-- Internal messages: one row per recipient. A message sent to several users shares a batch_key;
-- replies point at their parent and every message of a conversation carries the root id in thread_id.
ALTER TABLE messages
  ADD COLUMN parent_id BIGINT UNSIGNED NULL COMMENT 'message this one replies to' AFTER id,
  ADD COLUMN thread_id BIGINT UNSIGNED NULL COMMENT 'id of the first message of the conversation' AFTER parent_id,
  ADD COLUMN batch_key CHAR(16) NULL COMMENT 'shared by every copy of one message sent to several recipients' AFTER thread_id,
  ADD COLUMN is_starred_by_sender TINYINT(1) NOT NULL DEFAULT 0 AFTER read_at,
  ADD COLUMN is_starred_by_recipient TINYINT(1) NOT NULL DEFAULT 0 AFTER is_starred_by_sender;

ALTER TABLE messages
  ADD KEY idx_msg_thread (thread_id, created_at),
  ADD KEY idx_msg_batch (batch_key),
  ADD KEY idx_msg_inbox (recipient_id, deleted_by_recipient, created_at),
  ADD KEY idx_msg_outbox (sender_id, deleted_by_sender, created_at),
  ADD CONSTRAINT fk_msg_parent FOREIGN KEY (parent_id) REFERENCES messages(id) ON DELETE SET NULL;

-- Global search (Ctrl+K): prefix lookups on people names.
ALTER TABLE students ADD KEY idx_srch_students_last (last_name);
ALTER TABLE faculty ADD KEY idx_srch_faculty_name (first_name, last_name);
ALTER TABLE staff ADD KEY idx_srch_staff_name (first_name, last_name);
ALTER TABLE admissions ADD KEY idx_srch_admissions_name (first_name, last_name);
ALTER TABLE enquiries ADD KEY idx_srch_enquiries_name (name);
ALTER TABLE alumni ADD KEY idx_srch_alumni_name (name);
