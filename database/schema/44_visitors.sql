-- =====================================================================
-- 44 Website visitor counter (footer "Unique Visitors / Total Visits")
-- Anonymous: a visitor is a random first-party id (cookie), stored only as a SHA-256 hash.
-- =====================================================================

CREATE TABLE site_visitors (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  visitor_hash CHAR(64) NOT NULL COMMENT 'sha256 of the random gimt_vid cookie value',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  last_visit_day DATE NOT NULL,
  visits INT UNSIGNED NOT NULL DEFAULT 1,
  pageviews INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_site_visitors_hash (visitor_hash),
  KEY idx_site_visitors_first_seen (first_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE site_visit_daily (
  day DATE NOT NULL,
  new_visitors INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'first-ever visits on this day',
  unique_visitors INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'distinct visitors seen on this day',
  visits INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'sessions (30 min inactivity starts a new visit)',
  pageviews INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
