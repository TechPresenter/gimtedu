-- =====================================================================
-- 37 Website CMS content (unit cms_content): blog, media library, gallery, FAQs, testimonials.
-- Extends the tables created in 12_cms.sql. Plain, portable ALTERs (MySQL 8 / MariaDB 10.4+).
-- =====================================================================

-- Blog categories: manual ordering + timestamps
ALTER TABLE blog_categories
  ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER description,
  ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- Blog posts: who last edited + listing indexes
ALTER TABLE blog_posts
  ADD COLUMN created_by INT UNSIGNED NULL AFTER views,
  ADD COLUMN updated_by INT UNSIGNED NULL AFTER created_by,
  ADD KEY idx_blog_category (category_id, status),
  ADD KEY idx_blog_featured (is_featured, status);

-- Media library: caption, extension, last update
ALTER TABLE media
  ADD COLUMN caption VARCHAR(500) NULL AFTER title,
  ADD COLUMN extension VARCHAR(10) NULL AFTER mime,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  ADD KEY idx_media_path (path(191));

-- Galleries: last update
ALTER TABLE galleries
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- FAQs: contact page flag
ALTER TABLE faqs
  ADD COLUMN show_on_contact TINYINT(1) NOT NULL DEFAULT 0 AFTER show_on_home,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP;

-- Testimonials: batch, video, featured flag
ALTER TABLE testimonials
  ADD COLUMN batch VARCHAR(20) NULL AFTER program_id,
  ADD COLUMN video_url VARCHAR(255) NULL AFTER content,
  ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER rating,
  ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  ADD KEY idx_testimonials_list (status, sort_order);
