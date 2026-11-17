-- SEO growth (2026-11-17): per-page focus keywords + Twitter fields + indexing status, schema types in the audit,
-- digital presence / submission dashboard, keyword database, AI visibility test log, IndexNow, crawler activity,
-- PageSpeed runs. Portable SQL (runs once after 01–56, like every schema file).

ALTER TABLE seo_settings
  ADD COLUMN focus_keyword VARCHAR(120) NULL AFTER meta_keywords,
  ADD COLUMN secondary_keywords VARCHAR(500) NULL AFTER focus_keyword,
  ADD COLUMN twitter_title VARCHAR(190) NULL AFTER og_image,
  ADD COLUMN twitter_description VARCHAR(320) NULL AFTER twitter_title,
  ADD COLUMN image_alt VARCHAR(190) NULL AFTER twitter_description,
  ADD COLUMN index_status VARCHAR(20) NULL AFTER sitemap_changefreq,
  ADD COLUMN index_checked_at DATETIME NULL AFTER index_status,
  ADD COLUMN index_note VARCHAR(255) NULL AFTER index_checked_at;

ALTER TABLE seo_audit_pages ADD COLUMN schema_types VARCHAR(500) NULL AFTER robots;

-- Digital presence / submission dashboard: one row per platform or check. Status is set by people (or by a
-- verified automatic check), never assumed.
CREATE TABLE seo_presence (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_key VARCHAR(60) NOT NULL,
  category VARCHAR(30) NOT NULL,
  title VARCHAR(150) NOT NULL,
  platform_url VARCHAR(500) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'not_started',
  verification_status VARCHAR(20) NULL,
  connection_status VARCHAR(20) NULL,
  submission_status VARCHAR(20) NULL,
  method VARCHAR(60) NULL,
  profile_url VARCHAR(500) NULL,
  owner_role VARCHAR(60) NULL,
  evidence VARCHAR(500) NULL,
  error_details VARCHAR(500) NULL,
  next_action VARCHAR(255) NULL,
  notes TEXT NULL,
  last_checked_at DATETIME NULL,
  last_submitted_at DATETIME NULL,
  next_review_at DATE NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_seo_presence_key (item_key),
  KEY idx_seo_presence_status (category, status),
  CONSTRAINT fk_seo_presence_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Keyword master database (one primary keyword cluster per landing page).
CREATE TABLE seo_keywords (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  keyword VARCHAR(190) NOT NULL,
  keyword_hash CHAR(40) NOT NULL,
  category VARCHAR(60) NULL,
  intent VARCHAR(20) NULL,
  cluster VARCHAR(100) NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0,
  priority VARCHAR(10) NOT NULL DEFAULT 'medium',
  target_url VARCHAR(500) NULL,
  monthly_volume INT UNSIGNED NULL,
  volume_source VARCHAR(40) NULL,
  difficulty TINYINT UNSIGNED NULL,
  competition VARCHAR(10) NULL,
  current_position DECIMAL(5,1) NULL,
  position_checked_at DATE NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'pending',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_seo_keywords_hash (keyword_hash),
  KEY idx_seo_kw_cluster (cluster),
  KEY idx_seo_kw_category (category),
  CONSTRAINT fk_seo_kw_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI visibility test log (manual entries: what an AI search engine answered for a query on a date).
CREATE TABLE seo_ai_tests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  engine VARCHAR(30) NOT NULL,
  query_text VARCHAR(500) NOT NULL,
  keyword_id INT UNSIGNED NULL,
  tested_on DATE NOT NULL,
  mentioned TINYINT(1) NOT NULL DEFAULT 0,
  cited TINYINT(1) NOT NULL DEFAULT 0,
  cited_url VARCHAR(500) NULL,
  accuracy VARCHAR(20) NULL,
  competitors VARCHAR(500) NULL,
  incorrect_info VARCHAR(1000) NULL,
  corrective_action VARCHAR(500) NULL,
  answer_excerpt VARCHAR(1000) NULL,
  screenshot VARCHAR(255) NULL,
  tested_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_seo_ai_engine (engine, tested_on),
  CONSTRAINT fk_seo_ai_kw FOREIGN KEY (keyword_id) REFERENCES seo_keywords(id) ON DELETE SET NULL,
  CONSTRAINT fk_seo_ai_user FOREIGN KEY (tested_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- IndexNow: last lastmod submitted per URL (diff-based submissions) + one log row per submission.
CREATE TABLE seo_indexnow_urls (
  path_hash CHAR(40) NOT NULL PRIMARY KEY,
  path VARCHAR(500) NOT NULL,
  last_lastmod VARCHAR(25) NULL,
  submitted_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE seo_indexnow_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  trigger_type VARCHAR(20) NOT NULL,
  url_count INT UNSIGNED NOT NULL DEFAULT 0,
  urls_sample VARCHAR(1000) NULL,
  http_status SMALLINT UNSIGNED NULL,
  response VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_seo_indexnow_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Search engine / AI crawler activity: daily hit counters per bot family (no IPs, no personal data).
CREATE TABLE seo_bot_hits (
  hit_date DATE NOT NULL,
  bot VARCHAR(40) NOT NULL,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  last_path VARCHAR(500) NULL,
  PRIMARY KEY (hit_date, bot)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- PageSpeed Insights runs started from the admin (Google PSI API).
CREATE TABLE seo_pagespeed_runs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  url VARCHAR(500) NOT NULL,
  strategy VARCHAR(10) NOT NULL,
  performance_score TINYINT UNSIGNED NULL,
  seo_score TINYINT UNSIGNED NULL,
  accessibility_score TINYINT UNSIGNED NULL,
  best_practices_score TINYINT UNSIGNED NULL,
  lcp_ms INT UNSIGNED NULL,
  cls DECIMAL(6,3) NULL,
  tbt_ms INT UNSIGNED NULL,
  fcp_ms INT UNSIGNED NULL,
  field_lcp_ms INT UNSIGNED NULL,
  field_inp_ms INT UNSIGNED NULL,
  field_cls DECIMAL(6,3) NULL,
  opportunities TEXT NULL,
  error VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_seo_psi_url (url(191), created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
