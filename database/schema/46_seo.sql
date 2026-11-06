-- =====================================================================
-- 46 SEO & tracking platform (unit seo_platform)
--   redirects        admin-managed 301/302/307/308/410 rules (Website CMS -> Redirects)
--   seo_404_log      URLs that answered 404 (suggests redirects; no IPs or personal data stored)
--   seo_audits       SEO audit runs (CMS -> SEO -> Audit): crawl of the site's own URLs
--   seo_audit_pages  one row per crawled URL of an audit run
-- Settings rows (captcha, consent, error monitoring, maps ...) are seeded by database/seed/09_seo.php.
-- =====================================================================

CREATE TABLE IF NOT EXISTS redirects (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  from_path VARCHAR(500) NOT NULL COMMENT 'Normalised path on this site (+ optional ?query), e.g. /old-page',
  from_hash CHAR(40) NOT NULL COMMENT 'sha1(lower(from_path)) - case-insensitive unique lookups',
  to_url VARCHAR(1000) NULL COMMENT 'Target path (/new-page) or absolute URL; NULL for 410 Gone',
  to_path VARCHAR(500) NULL COMMENT 'Normalised internal target path (chain/loop detection); NULL when external',
  status_code SMALLINT UNSIGNED NOT NULL DEFAULT 301 COMMENT '301|302|307|308|410',
  preserve_query TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Append the visitor''s query string to the target',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  last_hit_at DATETIME NULL,
  source VARCHAR(20) NOT NULL DEFAULT 'manual' COMMENT 'manual|import|system|404',
  notes VARCHAR(500) NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_redirects_from (from_hash),
  KEY idx_redirects_to_path (to_path(190)),
  KEY idx_redirects_active (is_active, status_code),
  CONSTRAINT fk_redirects_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_redirects_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_404_log (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  path VARCHAR(500) NOT NULL,
  path_hash CHAR(40) NOT NULL COMMENT 'sha1(lower(path))',
  hits INT UNSIGNED NOT NULL DEFAULT 1,
  bot_hits INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Hits from search engine crawlers',
  last_referrer VARCHAR(500) NULL COMMENT 'Referring URL of the latest hit (internal referrers point to a broken link)',
  first_seen_at DATETIME NOT NULL,
  last_seen_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seo_404_path (path_hash),
  KEY idx_seo_404_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_audits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status VARCHAR(20) NOT NULL DEFAULT 'queued' COMMENT 'queued|running|completed|failed|cancelled',
  mode VARCHAR(10) NOT NULL DEFAULT 'inline' COMMENT 'inline = steps driven by the admin browser; worker = background CLI process',
  base_url VARCHAR(255) NOT NULL,
  max_pages SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  pages_found INT UNSIGNED NOT NULL DEFAULT 0,
  pages_crawled INT UNSIGNED NOT NULL DEFAULT 0,
  errors_count INT UNSIGNED NOT NULL DEFAULT 0,
  warnings_count INT UNSIGNED NOT NULL DEFAULT 0,
  summary JSON NULL,
  message VARCHAR(500) NULL,
  heartbeat_at DATETIME NULL,
  started_by INT UNSIGNED NULL,
  started_at DATETIME NULL,
  finished_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_seo_audits_status (status),
  CONSTRAINT fk_seo_audits_user FOREIGN KEY (started_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_audit_pages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  audit_id INT UNSIGNED NOT NULL,
  url VARCHAR(1000) NOT NULL,
  path VARCHAR(500) NOT NULL COMMENT 'Path + query relative to the site root',
  path_hash CHAR(40) NOT NULL,
  kind VARCHAR(10) NOT NULL DEFAULT 'page' COMMENT 'page (HTML, parsed) | file (linked document/image, status only) | seo (robots.txt, sitemaps)',
  source VARCHAR(10) NOT NULL DEFAULT 'link' COMMENT 'start|sitemap|link',
  in_sitemap TINYINT(1) NOT NULL DEFAULT 0,
  depth TINYINT UNSIGNED NOT NULL DEFAULT 0,
  found_on VARCHAR(500) NULL COMMENT 'First page linking here',
  state VARCHAR(10) NOT NULL DEFAULT 'queued' COMMENT 'queued|done|skipped',
  status_code SMALLINT UNSIGNED NULL,
  redirect_to VARCHAR(1000) NULL,
  content_type VARCHAR(100) NULL,
  response_ms INT UNSIGNED NULL,
  bytes INT UNSIGNED NULL,
  title VARCHAR(500) NULL,
  description VARCHAR(1000) NULL,
  h1 VARCHAR(500) NULL,
  h1_count SMALLINT UNSIGNED NULL,
  images SMALLINT UNSIGNED NULL,
  images_no_alt SMALLINT UNSIGNED NULL,
  canonical VARCHAR(1000) NULL,
  robots VARCHAR(100) NULL,
  links JSON NULL COMMENT 'Internal link paths found on the page',
  issues JSON NULL,
  error VARCHAR(255) NULL,
  crawled_at DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seo_audit_page (audit_id, path_hash),
  KEY idx_seo_audit_state (audit_id, state),
  CONSTRAINT fk_seo_audit_pages_audit FOREIGN KEY (audit_id) REFERENCES seo_audits(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
