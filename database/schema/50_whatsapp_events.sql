-- ============================================================================================================
-- 50_whatsapp_events.sql — WhatsApp event engine extras (Event Manager, docs/WHATSAPP.md).
-- schedule_config: settings of the daily time-based events (fee due in N days, overdue on day N, send time …).
-- last_scan_at: last daily scan of a time-based event (the worker scans each active one once a day).
-- updated_by: who last edited the event in the Event Manager.
-- idx_wa_messages_event_log: per-event message statistics (wa_messages.event_log_id).
-- wa_event_fanouts: background queue of bulk events (results published, timetable, notice, holiday, bulk assign …).
--   The ERP request stores one job row; the worker tick expands it into one event per id in budgeted batches.
-- ============================================================================================================

ALTER TABLE wa_events
  ADD COLUMN schedule_config LONGTEXT NULL COMMENT 'JSON for time-based events: {"days":[3,1],"time":"09:00","minutes":60}' AFTER respect_business_hours,
  ADD COLUMN last_scan_at DATETIME NULL COMMENT 'last daily scan of a time-based event' AFTER last_executed_at,
  ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by;

ALTER TABLE wa_messages
  ADD KEY idx_wa_messages_event_log (event_log_id);

CREATE TABLE wa_event_fanouts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_code VARCHAR(80) NOT NULL COMMENT 'first event code of the job (lists / filters)',
  codes VARCHAR(500) NOT NULL COMMENT 'comma list of the event codes fired for every id',
  id_key VARCHAR(40) NOT NULL DEFAULT 'student_id' COMMENT 'payload key each id is passed as (student_id, enquiry_id, ticket_id, contact_id)',
  common_payload LONGTEXT NULL COMMENT 'JSON payload shared by every item',
  ids LONGTEXT NOT NULL COMMENT 'JSON list of ids',
  item_dedupe VARCHAR(150) NULL COMMENT 'dedupe key per item; {id} is replaced by the id (else ":<id>" is appended)',
  opts LONGTEXT NULL COMMENT 'JSON wa_event options (source)',
  total INT UNSIGNED NOT NULL DEFAULT 0,
  processed INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'cursor: ids handled so far',
  fired INT UNSIGNED NOT NULL DEFAULT 0,
  duplicates INT UNSIGNED NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'pending' COMMENT 'pending|running|done|failed|cancelled',
  attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'interrupted runs (expired leases)',
  locked_until DATETIME NULL,
  error TEXT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_wa_event_fanouts_status (status, id),
  KEY idx_wa_event_fanouts_code (event_code, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
