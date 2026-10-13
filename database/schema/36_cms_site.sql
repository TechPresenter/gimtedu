-- =====================================================================
-- 36 Website CMS (unit cms_site): banner accessibility + slide eyebrow
-- See docs/CMS.md section 4. Runs after 12_cms.sql.
-- =====================================================================

ALTER TABLE banners
  ADD COLUMN eyebrow VARCHAR(120) NULL COMMENT 'small label above the heading (HeroSlider eyebrow)' AFTER title,
  ADD COLUMN image_alt VARCHAR(190) NULL COMMENT 'alternative text for the banner image' AFTER mobile_image;

ALTER TABLE pages
  ADD KEY idx_pages_status (status, published_at),
  ADD KEY idx_pages_parent (parent_id, sort_order);

ALTER TABLE announcements
  ADD KEY idx_announcements_type (type, status, sort_order);
