-- =====================================================================
-- 12 Website CMS: pages, page builder, menus, banners, homepage sections,
-- blog, media, gallery, FAQs, testimonials, announcements, SEO
-- =====================================================================

CREATE TABLE pages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  template VARCHAR(30) NOT NULL DEFAULT 'default' COMMENT 'default|full_width|landing|sidebar',
  subtitle VARCHAR(255) NULL,
  banner_image VARCHAR(255) NULL,
  excerpt VARCHAR(500) NULL,
  content MEDIUMTEXT NULL COMMENT 'rich text (used when no builder blocks)',
  blocks JSON NULL COMMENT 'page builder blocks: [{type, data}] - see docs/CMS.md',
  parent_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'core pages (home, about...) cannot be deleted',
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|published',
  published_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pages_slug (slug),
  CONSTRAINT fk_pages_parent FOREIGN KEY (parent_id) REFERENCES pages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menus (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  location VARCHAR(30) NOT NULL COMMENT 'header|footer_quick|footer_academics|footer_support|topbar|legal|mobile',
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_menus_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  menu_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  title VARCHAR(100) NOT NULL,
  url VARCHAR(255) NULL COMMENT 'relative (/programs) or absolute URL',
  page_id INT UNSIGNED NULL,
  target VARCHAR(10) NOT NULL DEFAULT '_self',
  icon VARCHAR(40) NULL,
  description VARCHAR(190) NULL COMMENT 'shown in mega menu',
  is_mega TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'render children as mega menu',
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  KEY idx_menu_items_menu (menu_id, parent_id, sort_order),
  CONSTRAINT fk_mi_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
  CONSTRAINT fk_mi_parent FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_mi_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE banners (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL COMMENT 'heading',
  highlight VARCHAR(190) NULL COMMENT 'second heading line in accent colour',
  subheading VARCHAR(500) NULL,
  desktop_image VARCHAR(255) NULL,
  mobile_image VARCHAR(255) NULL,
  cta_text VARCHAR(60) NULL,
  cta_url VARCHAR(255) NULL,
  cta2_text VARCHAR(60) NULL,
  cta2_url VARCHAR(255) NULL,
  placement VARCHAR(30) NOT NULL DEFAULT 'home_hero' COMMENT 'home_hero|page_header|popup|strip',
  sort_order INT NOT NULL DEFAULT 0,
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_banners_placement (placement, status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Home page sections with JSON content (see docs/CMS.md for each section's content shape)
CREATE TABLE homepage_sections (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  section_key VARCHAR(50) NOT NULL COMMENT 'hero|stats|features|programs|why|admission_process|academic_excellence|campus_life|placements|recruiters|testimonials|events_news|faqs|cta',
  title VARCHAR(190) NULL,
  subtitle VARCHAR(500) NULL,
  content JSON NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_hs_key (section_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_categories (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description VARCHAR(255) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  PRIMARY KEY (id),
  UNIQUE KEY uq_blog_cat_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  category_id INT UNSIGNED NULL,
  author_id INT UNSIGNED NULL,
  thumbnail VARCHAR(255) NULL,
  excerpt VARCHAR(500) NULL,
  content MEDIUMTEXT NULL,
  tags VARCHAR(255) NULL COMMENT 'comma separated',
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  type VARCHAR(20) NOT NULL DEFAULT 'news' COMMENT 'news|blog|press|achievement',
  status VARCHAR(20) NOT NULL DEFAULT 'draft' COMMENT 'draft|published|scheduled',
  published_at DATETIME NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_blog_slug (slug),
  KEY idx_blog_status_date (status, published_at),
  CONSTRAINT fk_blog_category FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_blog_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  filename VARCHAR(190) NOT NULL,
  original_name VARCHAR(190) NULL,
  path VARCHAR(255) NOT NULL,
  mime VARCHAR(100) NULL,
  size_bytes INT UNSIGNED NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'image' COMMENT 'image|video|pdf|document|other',
  width INT UNSIGNED NULL,
  height INT UNSIGNED NULL,
  alt_text VARCHAR(255) NULL,
  title VARCHAR(190) NULL,
  folder VARCHAR(60) NULL DEFAULT 'general',
  uploaded_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_media_type (type, created_at),
  KEY idx_media_folder (folder)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE galleries (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  category VARCHAR(40) NULL COMMENT 'campus|events|sports|cultural|convocation|labs',
  description VARCHAR(500) NULL,
  cover_image VARCHAR(255) NULL,
  event_date DATE NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'published',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_galleries_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery_items (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  gallery_id INT UNSIGNED NOT NULL,
  media_id INT UNSIGNED NULL,
  image VARCHAR(255) NULL,
  video_url VARCHAR(255) NULL,
  caption VARCHAR(255) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_gi_gallery (gallery_id, sort_order),
  CONSTRAINT fk_gi_gallery FOREIGN KEY (gallery_id) REFERENCES galleries(id) ON DELETE CASCADE,
  CONSTRAINT fk_gi_media FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  category VARCHAR(40) NOT NULL DEFAULT 'general' COMMENT 'general|admissions|academics|fees|hostel|placement|campus',
  question VARCHAR(500) NOT NULL,
  answer TEXT NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  show_on_home TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_faqs_category (category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testimonials (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  designation VARCHAR(190) NULL COMMENT 'e.g. MBA 2024 · Analyst at Deloitte',
  company VARCHAR(150) NULL,
  photo VARCHAR(255) NULL,
  content TEXT NOT NULL,
  rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
  type VARCHAR(20) NOT NULL DEFAULT 'student' COMMENT 'student|alumni|parent|recruiter',
  program_id INT UNSIGNED NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_testimonials_program FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL,
  content VARCHAR(500) NULL,
  link VARCHAR(255) NULL,
  link_text VARCHAR(60) NULL,
  type VARCHAR(20) NOT NULL DEFAULT 'ticker' COMMENT 'ticker|topbar|popup|banner',
  starts_at DATETIME NULL,
  ends_at DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SEO metadata for any public URL. entity_type = page|program|post|event|notice|route
-- For entity_type 'route', `route` holds the path (e.g. /, /contact, /programs) and entity_id is NULL.
CREATE TABLE seo_settings (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  entity_type VARCHAR(20) NOT NULL,
  entity_id INT UNSIGNED NULL,
  route VARCHAR(190) NULL,
  meta_title VARCHAR(190) NULL,
  meta_description VARCHAR(320) NULL,
  meta_keywords VARCHAR(255) NULL,
  canonical_url VARCHAR(255) NULL,
  og_title VARCHAR(190) NULL,
  og_description VARCHAR(320) NULL,
  og_image VARCHAR(255) NULL,
  robots VARCHAR(40) NOT NULL DEFAULT 'index,follow',
  schema_json MEDIUMTEXT NULL,
  include_in_sitemap TINYINT(1) NOT NULL DEFAULT 1,
  sitemap_priority DECIMAL(2,1) NOT NULL DEFAULT 0.7,
  sitemap_changefreq VARCHAR(10) NOT NULL DEFAULT 'weekly',
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_seo_entity (entity_type, entity_id),
  UNIQUE KEY uq_seo_route (route)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
