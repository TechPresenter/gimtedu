-- =====================================================================
-- Prerit Welfare Foundation - complete database (schema + default data)
-- MySQL 5.7+ / MariaDB 10.3+  |  utf8mb4
--
-- 1. Create an empty database and import this file (phpMyAdmin > Import).
-- 2. Set INSTALL_KEY in pwf-config.php (or config.php).
-- 3. Open https://YOUR-DOMAIN/install.php to create the first administrator.
--    No administrator account with a known password is created by this file.
--
-- Default content below is descriptive text about the foundation's work only.
-- It contains no donations, beneficiaries, registration numbers, financial
-- figures, testimonials or impact statistics - add verified data via /admin/.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+05:30';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS schemes, page_views, marketing_events, not_found_log, activity_logs, notifications, tasks, application_history, data_requests, newsletter_subscribers,
    feedback, contact_enquiries, certificates, faqs, testimonials, success_stories, blog_posts, gallery_items, resources,
    team_members, event_registrations, events, scholarships, beneficiaries, internship_applications, staff_applications,
    teacher_applications, volunteer_applications, payment_webhook_events, donation_receipts, donations, campaign_expenses,
    campaign_updates, campaigns, programs, media, redirects, menu_items, menus, page_sections, pages, website_settings,
    members, password_resets, email_verifications, remember_tokens, user_roles, role_permissions, permissions, users, roles,
    admins, volunteers, gallery, website_pages;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- USERS, ROLES & PERMISSIONS
-- =====================================================================
CREATE TABLE roles (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                VARCHAR(80)  NOT NULL,
    slug                VARCHAR(80)  NOT NULL,
    description         VARCHAR(255) DEFAULT NULL,
    is_staff            TINYINT(1)   NOT NULL DEFAULT 0,   -- may open the admin panel
    is_system           TINYINT(1)   NOT NULL DEFAULT 0,   -- cannot be deleted
    allow_self_register TINYINT(1)   NOT NULL DEFAULT 0,   -- selectable on the public register form
    sort_order          INT          NOT NULL DEFAULT 0,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    perm_key    VARCHAR(60)  NOT NULL,
    label       VARCHAR(150) NOT NULL,
    group_name  VARCHAR(60)  NOT NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_key (perm_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
    role_id        INT UNSIGNED NOT NULL,
    permission_id  INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_rp_permission (permission_id),
    CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_rp_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name                   VARCHAR(120) NOT NULL,
    email                  VARCHAR(190) NOT NULL,
    mobile                 VARCHAR(15)  DEFAULT NULL,
    password_hash          VARCHAR(255) NOT NULL,
    status                 ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active',
    email_verified_at      DATETIME     DEFAULT NULL,
    address                VARCHAR(255) DEFAULT NULL,
    city                   VARCHAR(80)  DEFAULT NULL,
    state                  VARCHAR(80)  DEFAULT NULL,
    pincode                VARCHAR(10)  DEFAULT NULL,
    avatar                 VARCHAR(255) DEFAULT NULL,
    communication_consent  TINYINT(1)   NOT NULL DEFAULT 0,
    privacy_accepted_at    DATETIME     DEFAULT NULL,
    must_change_password   TINYINT(1)   NOT NULL DEFAULT 0,
    session_version        INT UNSIGNED NOT NULL DEFAULT 1, -- bump to sign the user out everywhere
    totp_secret            VARCHAR(64)  DEFAULT NULL,       -- optional two-step login for staff (authenticator app)
    totp_enabled           TINYINT(1)   NOT NULL DEFAULT 0,
    last_login_at          DATETIME     DEFAULT NULL,
    last_login_ip          VARCHAR(45)  DEFAULT NULL,
    created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_mobile (mobile),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
    user_id      INT UNSIGNED NOT NULL,
    role_id      INT UNSIGNED NOT NULL,
    assigned_by  INT UNSIGNED DEFAULT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    KEY idx_ur_role (role_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE,
    CONSTRAINT fk_ur_assigned FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Remember me": the selector is public, only a SHA-256 of the validator is stored
CREATE TABLE remember_tokens (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         INT UNSIGNED NOT NULL,
    selector        CHAR(24)     NOT NULL,
    validator_hash  CHAR(64)     NOT NULL,
    expires_at      DATETIME     NOT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_remember_selector (selector),
    KEY idx_remember_user (user_id),
    CONSTRAINT fk_remember_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_verifications (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_verify_token (token_hash),
    KEY idx_verify_user (user_id),
    CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Single-use, expiring password reset tokens (only the hash is stored)
CREATE TABLE password_resets (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    token_hash  CHAR(64)     NOT NULL,
    expires_at  DATETIME     NOT NULL,
    used_at     DATETIME     DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_resets_token (token_hash),
    KEY idx_resets_user (user_id),
    CONSTRAINT fk_resets_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    membership_no      VARCHAR(40)  DEFAULT NULL,
    membership_type    ENUM('general','volunteer','student','life') NOT NULL DEFAULT 'general',
    gender             ENUM('female','male','other','prefer_not') DEFAULT NULL,
    date_of_birth      DATE         DEFAULT NULL,
    occupation         VARCHAR(120) DEFAULT NULL,
    address            VARCHAR(255) DEFAULT NULL,
    city               VARCHAR(80)  DEFAULT NULL,
    state              VARCHAR(80)  DEFAULT NULL,
    pincode            VARCHAR(10)  DEFAULT NULL,
    areas_of_interest  VARCHAR(255) DEFAULT NULL,
    motivation         TEXT         DEFAULT NULL,
    status             ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    valid_until        DATE         DEFAULT NULL,
    admin_notes        TEXT         DEFAULT NULL,
    approved_by        INT UNSIGNED DEFAULT NULL,
    approved_at        DATETIME     DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_members_user (user_id),
    UNIQUE KEY uq_members_no (membership_no),
    KEY idx_members_status (status),
    CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_members_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- CMS: SETTINGS, PAGES, SECTIONS, MENUS, REDIRECTS, MEDIA
-- =====================================================================
CREATE TABLE website_settings (
    setting_key    VARCHAR(100) NOT NULL,
    setting_value  MEDIUMTEXT   DEFAULT NULL,
    updated_by     INT UNSIGNED DEFAULT NULL,
    updated_at     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every public URL is a row here. slug "about" => /about/ ; "programs/healthcare" => /programs/healthcare/
CREATE TABLE pages (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id         INT UNSIGNED DEFAULT NULL,
    slug              VARCHAR(190) NOT NULL,
    template          VARCHAR(40)  NOT NULL DEFAULT 'default',
    title             VARCHAR(200) NOT NULL,
    banner_eyebrow    VARCHAR(80)  DEFAULT NULL,
    banner_subtitle   VARCHAR(300) DEFAULT NULL,
    banner_image      VARCHAR(255) DEFAULT NULL,
    banner_buttons    TEXT         DEFAULT NULL,          -- JSON [{"label","url","style","icon"}]
    meta_title        VARCHAR(200) DEFAULT NULL,
    meta_description  VARCHAR(300) DEFAULT NULL,
    canonical_url     VARCHAR(255) DEFAULT NULL,
    og_image          VARCHAR(255) DEFAULT NULL,
    settings          TEXT         DEFAULT NULL,          -- JSON, template specific
    status            ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    is_system         TINYINT(1)   NOT NULL DEFAULT 0,    -- template pages cannot be deleted
    show_in_sitemap   TINYINT(1)   NOT NULL DEFAULT 1,
    noindex           TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order        INT          NOT NULL DEFAULT 0,
    published_at      DATETIME     DEFAULT NULL,
    created_by        INT UNSIGNED DEFAULT NULL,
    updated_by        INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pages_slug (slug),
    KEY idx_pages_template (template),
    KEY idx_pages_status (status),
    CONSTRAINT fk_pages_parent FOREIGN KEY (parent_id) REFERENCES pages (id) ON DELETE SET NULL,
    CONSTRAINT fk_pages_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_pages_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Content blocks of a page, rendered in sort_order. See render_sections() for section types.
CREATE TABLE page_sections (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    page_id       INT UNSIGNED NOT NULL,
    section_type  VARCHAR(40)  NOT NULL,
    anchor        VARCHAR(80)  DEFAULT NULL,
    pill          VARCHAR(100) DEFAULT NULL,
    title         VARCHAR(255) DEFAULT NULL,
    intro         TEXT         DEFAULT NULL,
    body          MEDIUMTEXT   DEFAULT NULL,             -- sanitised HTML
    image         VARCHAR(255) DEFAULT NULL,
    items         MEDIUMTEXT   DEFAULT NULL,             -- JSON array
    settings      TEXT         DEFAULT NULL,             -- JSON object
    background    VARCHAR(20)  NOT NULL DEFAULT 'white', -- white|tint|cream|green
    status        ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
    sort_order    INT          NOT NULL DEFAULT 0,
    updated_by    INT UNSIGNED DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_sections_page (page_id, sort_order),
    CONSTRAINT fk_sections_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE CASCADE,
    CONSTRAINT fk_sections_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menus (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu_key     VARCHAR(60)  NOT NULL,
    name         VARCHAR(120) NOT NULL,
    description  VARCHAR(255) DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_menus_key (menu_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    menu_id       INT UNSIGNED NOT NULL,
    parent_id     INT UNSIGNED DEFAULT NULL,
    label         VARCHAR(80)  NOT NULL,
    url           VARCHAR(255) NOT NULL,                 -- site path (/about/) or https:// URL
    icon          VARCHAR(40)  DEFAULT NULL,
    description   VARCHAR(150) DEFAULT NULL,
    open_new_tab  TINYINT(1)   NOT NULL DEFAULT 0,
    status        ENUM('visible','hidden') NOT NULL DEFAULT 'visible',
    sort_order    INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_menu_items_menu (menu_id, parent_id, sort_order),
    CONSTRAINT fk_menu_items_menu FOREIGN KEY (menu_id) REFERENCES menus (id) ON DELETE CASCADE,
    CONSTRAINT fk_menu_items_parent FOREIGN KEY (parent_id) REFERENCES menu_items (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE redirects (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_path  VARCHAR(255) NOT NULL,                 -- no leading/trailing slash, e.g. "about.php" or "old-page"
    target_url   VARCHAR(255) NOT NULL,                 -- "/about/" or https://...
    status_code  SMALLINT     NOT NULL DEFAULT 301,
    hits         INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_redirects_source (source_path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE media (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    path           VARCHAR(255) NOT NULL,
    original_name  VARCHAR(200) DEFAULT NULL,
    mime_type      VARCHAR(80)  DEFAULT NULL,
    file_size      INT UNSIGNED DEFAULT NULL,
    width          SMALLINT UNSIGNED DEFAULT NULL,
    height         SMALLINT UNSIGNED DEFAULT NULL,
    alt_text       VARCHAR(255) DEFAULT NULL,
    uploaded_by    INT UNSIGNED DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_media_path (path),
    CONSTRAINT fk_media_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- PROGRAMMES
-- =====================================================================
CREATE TABLE programs (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(120) NOT NULL,
    title         VARCHAR(150) NOT NULL,
    summary       VARCHAR(300) NOT NULL,
    icon          VARCHAR(40)  NOT NULL DEFAULT 'heart',
    tone          VARCHAR(20)  NOT NULL DEFAULT 'green',  -- green|gold|blue|orange
    cover_image   VARCHAR(255) DEFAULT NULL,
    key_points    TEXT         DEFAULT NULL,              -- JSON array of short strings
    page_id       INT UNSIGNED DEFAULT NULL,              -- the detail page (its slug is the URL)
    show_on_home  TINYINT(1)   NOT NULL DEFAULT 1,
    status        ENUM('published','draft') NOT NULL DEFAULT 'published',
    sort_order    INT          NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_programs_slug (slug),
    KEY idx_programs_status (status, sort_order),
    CONSTRAINT fk_programs_page FOREIGN KEY (page_id) REFERENCES pages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- CAMPAIGNS & DONATIONS
-- =====================================================================
CREATE TABLE campaigns (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    title              VARCHAR(200)  NOT NULL,
    slug               VARCHAR(220)  NOT NULL,
    program_id         INT UNSIGNED  DEFAULT NULL,
    category           VARCHAR(60)   NOT NULL DEFAULT 'general',
    short_description  VARCHAR(300)  NOT NULL,
    description        MEDIUMTEXT    NOT NULL,
    goal_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,   -- 0 = no target shown
    image              VARCHAR(255)  DEFAULT NULL,
    location           VARCHAR(150)  DEFAULT NULL,
    start_date         DATE          DEFAULT NULL,
    end_date           DATE          DEFAULT NULL,
    status             ENUM('proposal','draft','active','completed','closed','rejected') NOT NULL DEFAULT 'draft',
    is_featured        TINYINT(1)    NOT NULL DEFAULT 0,
    is_urgent          TINYINT(1)    NOT NULL DEFAULT 0,
    meta_title         VARCHAR(200)  DEFAULT NULL,
    meta_description   VARCHAR(300)  DEFAULT NULL,
    submitted_by       INT UNSIGNED  DEFAULT NULL,       -- fundraiser proposals from members
    organizer_name     VARCHAR(120)  DEFAULT NULL,
    organizer_email    VARCHAR(190)  DEFAULT NULL,
    organizer_phone    VARCHAR(20)   DEFAULT NULL,
    proposal_notes     TEXT          DEFAULT NULL,
    review_notes       TEXT          DEFAULT NULL,
    reviewed_by        INT UNSIGNED  DEFAULT NULL,
    reviewed_at        DATETIME      DEFAULT NULL,
    created_by         INT UNSIGNED  DEFAULT NULL,
    updated_by         INT UNSIGNED  DEFAULT NULL,
    created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaigns_slug (slug),
    KEY idx_campaigns_status (status),
    KEY idx_campaigns_category (category),
    CONSTRAINT fk_campaigns_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_submitted FOREIGN KEY (submitted_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_reviewed FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaign_updates (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    campaign_id   INT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    body          TEXT         NOT NULL,
    image         VARCHAR(255) DEFAULT NULL,
    status        ENUM('draft','published') NOT NULL DEFAULT 'published',
    published_at  DATETIME     DEFAULT NULL,
    created_by    INT UNSIGNED DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cu_campaign (campaign_id, status),
    CONSTRAINT fk_cu_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_cu_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Authorised spending recorded against a campaign (for utilisation reports)
CREATE TABLE campaign_expenses (
    id             INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    campaign_id    INT UNSIGNED  NOT NULL,
    expense_date   DATE          NOT NULL,
    category       VARCHAR(80)   NOT NULL,
    description    VARCHAR(500)  NOT NULL,
    amount         DECIMAL(12,2) NOT NULL,
    vendor         VARCHAR(150)  DEFAULT NULL,
    document_path  VARCHAR(255)  DEFAULT NULL,           -- private supporting document
    status         ENUM('recorded','approved','rejected') NOT NULL DEFAULT 'recorded',
    is_published   TINYINT(1)    NOT NULL DEFAULT 0,     -- include in the public utilisation report
    recorded_by    INT UNSIGNED  DEFAULT NULL,
    approved_by    INT UNSIGNED  DEFAULT NULL,
    approved_at    DATETIME      DEFAULT NULL,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ce_campaign (campaign_id, status),
    CONSTRAINT fk_ce_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE CASCADE,
    CONSTRAINT fk_ce_recorded FOREIGN KEY (recorded_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ce_approved FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Only status = 'success' counts towards totals (set after server-side gateway verification)
CREATE TABLE donations (
    id                  INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    donation_ref        VARCHAR(40)   NOT NULL,
    access_token        CHAR(64)      NOT NULL,
    user_id             INT UNSIGNED  DEFAULT NULL,
    campaign_id         INT UNSIGNED  DEFAULT NULL,
    donor_name          VARCHAR(120)  NOT NULL,
    donor_email         VARCHAR(190)  NOT NULL,
    donor_phone         VARCHAR(20)   DEFAULT NULL,
    donor_pan           VARCHAR(10)   DEFAULT NULL,
    donor_address       VARCHAR(255)  DEFAULT NULL,
    amount              DECIMAL(12,2) NOT NULL,
    currency            CHAR(3)       NOT NULL DEFAULT 'INR',
    is_anonymous        TINYINT(1)    NOT NULL DEFAULT 0,
    message             VARCHAR(500)  DEFAULT NULL,
    payment_method      ENUM('online','bank_transfer','cheque','upi','cash') NOT NULL DEFAULT 'online',
    gateway             VARCHAR(30)   NOT NULL DEFAULT 'razorpay',
    gateway_order_id    VARCHAR(80)   DEFAULT NULL,
    gateway_payment_id  VARCHAR(80)   DEFAULT NULL,
    gateway_signature   VARCHAR(255)  DEFAULT NULL,
    gateway_method      VARCHAR(40)   DEFAULT NULL,
    offline_reference   VARCHAR(100)  DEFAULT NULL,
    status              ENUM('pending','success','failed','refunded') NOT NULL DEFAULT 'pending',
    failure_reason      VARCHAR(255)  DEFAULT NULL,
    paid_at             DATETIME      DEFAULT NULL,
    verified_via        ENUM('checkout','webhook','admin_recheck','offline') DEFAULT NULL,
    verified_by         INT UNSIGNED  DEFAULT NULL,
    reconciled_at       DATETIME      DEFAULT NULL,
    reconciled_by       INT UNSIGNED  DEFAULT NULL,
    refund_id           VARCHAR(80)   DEFAULT NULL,
    refunded_at         DATETIME      DEFAULT NULL,
    admin_notes         TEXT          DEFAULT NULL,
    ip_address          VARCHAR(45)   DEFAULT NULL,
    created_at          TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_donations_ref (donation_ref),
    UNIQUE KEY uq_donations_order (gateway_order_id),
    UNIQUE KEY uq_donations_payment (gateway_payment_id),
    KEY idx_donations_status (status),
    KEY idx_donations_user (user_id),
    KEY idx_donations_campaign (campaign_id),
    KEY idx_donations_offline_ref (offline_reference),
    KEY idx_donations_email (donor_email),
    KEY idx_donations_paid (paid_at),
    CONSTRAINT fk_donations_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_donations_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_donations_verified FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_donations_reconciled FOREIGN KEY (reconciled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every webhook delivery; (gateway, event_id) is unique so duplicate deliveries are ignored
CREATE TABLE payment_webhook_events (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    gateway       VARCHAR(30)  NOT NULL DEFAULT 'razorpay',
    event_id      VARCHAR(100) NOT NULL,
    event_type    VARCHAR(60)  NOT NULL,
    payload_hash  CHAR(64)     NOT NULL,
    donation_id   INT UNSIGNED DEFAULT NULL,
    result        VARCHAR(255) DEFAULT NULL,
    received_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processed_at  DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_event (gateway, event_id),
    KEY idx_webhook_type (event_type),
    CONSTRAINT fk_webhook_donation FOREIGN KEY (donation_id) REFERENCES donations (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE donation_receipts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    donation_id     INT UNSIGNED NOT NULL,
    receipt_no      VARCHAR(40)  NOT NULL,
    financial_year  VARCHAR(9)   NOT NULL,
    status          ENUM('issued','cancelled') NOT NULL DEFAULT 'issued',
    issued_at       DATETIME     NOT NULL,
    emailed_at      DATETIME     DEFAULT NULL,
    email_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipts_donation (donation_id),
    UNIQUE KEY uq_receipts_no (receipt_no),
    CONSTRAINT fk_receipts_donation FOREIGN KEY (donation_id) REFERENCES donations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- RECRUITMENT & SUPPORT APPLICATIONS
-- Status flow: submitted > under_review > (info_requested) > shortlisted > interview_scheduled
--              > selected > onboarding > active  |  rejected | withdrawn
-- =====================================================================
CREATE TABLE volunteer_applications (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_no  VARCHAR(40)  DEFAULT NULL,
    user_id         INT UNSIGNED DEFAULT NULL,
    name            VARCHAR(120) NOT NULL,
    email           VARCHAR(190) NOT NULL,
    phone           VARCHAR(20)  NOT NULL,
    date_of_birth   DATE         DEFAULT NULL,
    gender          ENUM('female','male','other','prefer_not') DEFAULT NULL,
    address         VARCHAR(255) DEFAULT NULL,
    city            VARCHAR(80)  NOT NULL,
    state           VARCHAR(80)  DEFAULT NULL,
    pincode         VARCHAR(10)  DEFAULT NULL,
    occupation      VARCHAR(120) DEFAULT NULL,
    education       VARCHAR(150) DEFAULT NULL,
    interests       VARCHAR(255) NOT NULL,
    availability    VARCHAR(60)  NOT NULL,
    skills          VARCHAR(500) DEFAULT NULL,
    experience      TEXT         DEFAULT NULL,
    motivation      TEXT         NOT NULL,
    status          ENUM('submitted','under_review','info_requested','shortlisted','interview_scheduled','selected','onboarding','active','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    interview_at    DATETIME     DEFAULT NULL,
    interview_mode  VARCHAR(60)  DEFAULT NULL,
    info_request    TEXT         DEFAULT NULL,
    coordinator_id  INT UNSIGNED DEFAULT NULL,
    admin_notes     TEXT         DEFAULT NULL,
    reviewed_by     INT UNSIGNED DEFAULT NULL,
    reviewed_at     DATETIME     DEFAULT NULL,
    ip_address      VARCHAR(45)  DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vol_appno (application_no),
    KEY idx_vol_status (status),
    KEY idx_vol_user (user_id),
    KEY idx_vol_email (email),
    CONSTRAINT fk_vol_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_vol_coord FOREIGN KEY (coordinator_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_vol_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE teacher_applications (
    id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_no       VARCHAR(40)  DEFAULT NULL,
    user_id              INT UNSIGNED DEFAULT NULL,
    name                 VARCHAR(120) NOT NULL,
    email                VARCHAR(190) NOT NULL,
    phone                VARCHAR(20)  NOT NULL,
    date_of_birth        DATE         DEFAULT NULL,
    gender               ENUM('female','male','other','prefer_not') DEFAULT NULL,
    address              VARCHAR(255) DEFAULT NULL,
    city                 VARCHAR(80)  NOT NULL,
    state                VARCHAR(80)  DEFAULT NULL,
    pincode              VARCHAR(10)  DEFAULT NULL,
    qualification        VARCHAR(150) NOT NULL,
    subjects             VARCHAR(255) NOT NULL,
    classes_preferred    VARCHAR(100) DEFAULT NULL,
    languages            VARCHAR(150) DEFAULT NULL,
    teaching_experience  VARCHAR(60)  DEFAULT NULL,
    teaching_mode        ENUM('in_person','online','both') NOT NULL DEFAULT 'in_person',
    availability         VARCHAR(60)  NOT NULL,
    resume_path          VARCHAR(255) DEFAULT NULL,
    motivation           TEXT         NOT NULL,
    status               ENUM('submitted','under_review','info_requested','shortlisted','interview_scheduled','selected','onboarding','active','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    interview_at         DATETIME     DEFAULT NULL,
    interview_mode       VARCHAR(60)  DEFAULT NULL,
    info_request         TEXT         DEFAULT NULL,
    coordinator_id       INT UNSIGNED DEFAULT NULL,
    admin_notes          TEXT         DEFAULT NULL,
    reviewed_by          INT UNSIGNED DEFAULT NULL,
    reviewed_at          DATETIME     DEFAULT NULL,
    ip_address           VARCHAR(45)  DEFAULT NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tch_appno (application_no),
    KEY idx_tch_status (status),
    KEY idx_tch_user (user_id),
    KEY idx_tch_email (email),
    CONSTRAINT fk_tch_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tch_coord FOREIGN KEY (coordinator_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_tch_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE staff_applications (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_no        VARCHAR(40)  DEFAULT NULL,
    user_id               INT UNSIGNED DEFAULT NULL,
    position              VARCHAR(120) NOT NULL,
    name                  VARCHAR(120) NOT NULL,
    email                 VARCHAR(190) NOT NULL,
    phone                 VARCHAR(20)  NOT NULL,
    date_of_birth         DATE         DEFAULT NULL,
    gender                ENUM('female','male','other','prefer_not') DEFAULT NULL,
    address               VARCHAR(255) DEFAULT NULL,
    city                  VARCHAR(80)  NOT NULL,
    state                 VARCHAR(80)  DEFAULT NULL,
    pincode               VARCHAR(10)  DEFAULT NULL,
    qualification         VARCHAR(150) NOT NULL,
    experience_years      DECIMAL(4,1) NOT NULL DEFAULT 0.0,
    current_organization  VARCHAR(150) DEFAULT NULL,
    notice_period         VARCHAR(60)  DEFAULT NULL,
    resume_path           VARCHAR(255) DEFAULT NULL,
    cover_letter          TEXT         NOT NULL,
    status                ENUM('submitted','under_review','info_requested','shortlisted','interview_scheduled','selected','onboarding','active','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    interview_at          DATETIME     DEFAULT NULL,
    interview_mode        VARCHAR(60)  DEFAULT NULL,
    info_request          TEXT         DEFAULT NULL,
    coordinator_id        INT UNSIGNED DEFAULT NULL,
    admin_notes           TEXT         DEFAULT NULL,
    reviewed_by           INT UNSIGNED DEFAULT NULL,
    reviewed_at           DATETIME     DEFAULT NULL,
    ip_address            VARCHAR(45)  DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stf_appno (application_no),
    KEY idx_stf_status (status),
    KEY idx_stf_user (user_id),
    KEY idx_stf_email (email),
    CONSTRAINT fk_stf_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_stf_coord FOREIGN KEY (coordinator_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_stf_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE internship_applications (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_no   VARCHAR(40)  DEFAULT NULL,
    user_id          INT UNSIGNED DEFAULT NULL,
    name             VARCHAR(120) NOT NULL,
    email            VARCHAR(190) NOT NULL,
    phone            VARCHAR(20)  NOT NULL,
    date_of_birth    DATE         DEFAULT NULL,
    gender           ENUM('female','male','other','prefer_not') DEFAULT NULL,
    address          VARCHAR(255) DEFAULT NULL,
    city             VARCHAR(80)  NOT NULL,
    state            VARCHAR(80)  DEFAULT NULL,
    pincode          VARCHAR(10)  DEFAULT NULL,
    institution      VARCHAR(200) NOT NULL,
    course           VARCHAR(150) NOT NULL,
    year_of_study    VARCHAR(40)  DEFAULT NULL,
    internship_area  VARCHAR(120) NOT NULL,
    duration         VARCHAR(40)  NOT NULL,
    preferred_start  DATE         DEFAULT NULL,
    mode             ENUM('in_person','online','both') NOT NULL DEFAULT 'in_person',
    skills           VARCHAR(500) DEFAULT NULL,
    resume_path      VARCHAR(255) DEFAULT NULL,
    motivation       TEXT         NOT NULL,
    status           ENUM('submitted','under_review','info_requested','shortlisted','interview_scheduled','selected','onboarding','active','rejected','withdrawn') NOT NULL DEFAULT 'submitted',
    interview_at     DATETIME     DEFAULT NULL,
    interview_mode   VARCHAR(60)  DEFAULT NULL,
    info_request     TEXT         DEFAULT NULL,
    coordinator_id   INT UNSIGNED DEFAULT NULL,
    admin_notes      TEXT         DEFAULT NULL,
    reviewed_by      INT UNSIGNED DEFAULT NULL,
    reviewed_at      DATETIME     DEFAULT NULL,
    ip_address       VARCHAR(45)  DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_int_appno (application_no),
    KEY idx_int_status (status),
    KEY idx_int_user (user_id),
    KEY idx_int_email (email),
    CONSTRAINT fk_int_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_int_coord FOREIGN KEY (coordinator_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_int_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE beneficiaries (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    reference_no    VARCHAR(40)   DEFAULT NULL,
    user_id         INT UNSIGNED  DEFAULT NULL,
    name            VARCHAR(120)  NOT NULL,
    email           VARCHAR(190)  DEFAULT NULL,
    phone           VARCHAR(20)   NOT NULL,
    gender          ENUM('female','male','other','prefer_not') DEFAULT NULL,
    age             TINYINT UNSIGNED DEFAULT NULL,
    address         VARCHAR(255)  NOT NULL,
    city            VARCHAR(80)   NOT NULL,
    state           VARCHAR(80)   DEFAULT NULL,
    pincode         VARCHAR(10)   DEFAULT NULL,
    family_members  TINYINT UNSIGNED DEFAULT NULL,
    annual_income   DECIMAL(12,2) DEFAULT NULL,
    help_category   ENUM('education','health','women','livelihood','food','disaster','other') NOT NULL,
    description     TEXT          NOT NULL,
    document_path   VARCHAR(255)  DEFAULT NULL,
    consent         TINYINT(1)    NOT NULL DEFAULT 0,
    status          ENUM('pending','under_review','approved','assisted','rejected') NOT NULL DEFAULT 'pending',
    admin_notes     TEXT          DEFAULT NULL,
    reviewed_by     INT UNSIGNED  DEFAULT NULL,
    reviewed_at     DATETIME      DEFAULT NULL,
    ip_address      VARCHAR(45)   DEFAULT NULL,
    created_at      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ben_ref (reference_no),
    KEY idx_ben_status (status),
    KEY idx_ben_user (user_id),
    CONSTRAINT fk_ben_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_ben_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scholarships (
    id                    INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    application_no        VARCHAR(40)   DEFAULT NULL,
    user_id               INT UNSIGNED  DEFAULT NULL,
    student_name          VARCHAR(120)  NOT NULL,
    guardian_name         VARCHAR(120)  NOT NULL,
    email                 VARCHAR(190)  NOT NULL,
    phone                 VARCHAR(20)   NOT NULL,
    date_of_birth         DATE          DEFAULT NULL,
    gender                ENUM('female','male','other','prefer_not') DEFAULT NULL,
    address               VARCHAR(255)  NOT NULL,
    city                  VARCHAR(80)   NOT NULL,
    state                 VARCHAR(80)   DEFAULT NULL,
    pincode               VARCHAR(10)   DEFAULT NULL,
    institution           VARCHAR(200)  NOT NULL,
    course_class          VARCHAR(120)  NOT NULL,
    academic_year         VARCHAR(20)   NOT NULL,
    last_exam_percentage  DECIMAL(5,2)  DEFAULT NULL,
    annual_family_income  DECIMAL(12,2) NOT NULL,
    reason                TEXT          NOT NULL,
    document_path         VARCHAR(255)  DEFAULT NULL,
    status                ENUM('pending','under_review','shortlisted','approved','rejected','disbursed') NOT NULL DEFAULT 'pending',
    admin_notes           TEXT          DEFAULT NULL,
    reviewed_by           INT UNSIGNED  DEFAULT NULL,
    reviewed_at           DATETIME      DEFAULT NULL,
    ip_address            VARCHAR(45)   DEFAULT NULL,
    created_at            TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_sch_appno (application_no),
    KEY idx_sch_status (status),
    KEY idx_sch_user (user_id),
    CONSTRAINT fk_sch_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_sch_reviewer FOREIGN KEY (reviewed_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timeline of status changes shown to applicants and staff
CREATE TABLE application_history (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_type      VARCHAR(40)  NOT NULL,          -- volunteer|teacher|staff|internship|scholarship|beneficiary|membership
    application_id        INT UNSIGNED NOT NULL,
    status                VARCHAR(40)  NOT NULL,
    note                  TEXT         DEFAULT NULL,
    visible_to_applicant  TINYINT(1)   NOT NULL DEFAULT 1,
    created_by            INT UNSIGNED DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_history_app (application_type, application_id),
    CONSTRAINT fk_history_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks assigned to volunteers / teachers by coordinators
CREATE TABLE tasks (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED NOT NULL,
    title         VARCHAR(200) NOT NULL,
    details       TEXT         DEFAULT NULL,
    due_date      DATE         DEFAULT NULL,
    status        ENUM('assigned','in_progress','done','cancelled') NOT NULL DEFAULT 'assigned',
    assigned_by   INT UNSIGNED DEFAULT NULL,
    completed_at  DATETIME     DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tasks_user (user_id, status),
    CONSTRAINT fk_tasks_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_assigner FOREIGN KEY (assigned_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- EVENTS, MEDIA & CONTENT
-- =====================================================================
CREATE TABLE events (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title                 VARCHAR(200) NOT NULL,
    slug                  VARCHAR(220) NOT NULL,
    event_type            ENUM('event','awareness_day','drive','camp','webinar') NOT NULL DEFAULT 'event',
    program_id            INT UNSIGNED DEFAULT NULL,
    summary               VARCHAR(300) NOT NULL,
    description           MEDIUMTEXT   NOT NULL,
    image                 VARCHAR(255) DEFAULT NULL,
    location              VARCHAR(200) NOT NULL,
    start_datetime        DATETIME     NOT NULL,
    end_datetime          DATETIME     DEFAULT NULL,
    registration_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
    capacity              INT UNSIGNED DEFAULT NULL,
    status                ENUM('draft','published','cancelled','archived') NOT NULL DEFAULT 'draft',
    created_by            INT UNSIGNED DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_slug (slug),
    KEY idx_events_status_date (status, start_datetime),
    CONSTRAINT fk_events_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE SET NULL,
    CONSTRAINT fk_events_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_registrations (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id    INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED DEFAULT NULL,
    name        VARCHAR(120) NOT NULL,
    email       VARCHAR(190) NOT NULL,
    phone       VARCHAR(20)  NOT NULL,
    message     VARCHAR(500) DEFAULT NULL,
    status      ENUM('registered','attended','cancelled') NOT NULL DEFAULT 'registered',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_event_reg (event_id, email),
    KEY idx_event_reg_user (user_id),
    CONSTRAINT fk_event_reg_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_reg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gallery_items (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    media_type         ENUM('image','video') NOT NULL DEFAULT 'image',
    title              VARCHAR(150) NOT NULL,
    category           VARCHAR(60)  NOT NULL DEFAULT 'general',
    image_path         VARCHAR(255) DEFAULT NULL,
    video_url          VARCHAR(255) DEFAULT NULL,        -- YouTube link (embedded via youtube-nocookie.com)
    description        VARCHAR(300) DEFAULT NULL,
    consent_confirmed  TINYINT(1)   NOT NULL DEFAULT 0,  -- consent for identifiable people
    sort_order         INT          NOT NULL DEFAULT 0,
    status             ENUM('published','hidden') NOT NULL DEFAULT 'published',
    uploaded_by        INT UNSIGNED DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gallery_type (media_type, status, category),
    CONSTRAINT fk_gallery_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_posts (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title             VARCHAR(220) NOT NULL,
    slug              VARCHAR(240) NOT NULL,
    category          ENUM('blog','news','press') NOT NULL DEFAULT 'blog',
    program_id        INT UNSIGNED DEFAULT NULL,
    excerpt           VARCHAR(400) NOT NULL,
    content           MEDIUMTEXT   NOT NULL,
    image             VARCHAR(255) DEFAULT NULL,
    author_name       VARCHAR(120) DEFAULT NULL,
    meta_title        VARCHAR(200) DEFAULT NULL,
    meta_description  VARCHAR(300) DEFAULT NULL,
    status            ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at      DATETIME     DEFAULT NULL,
    views             INT UNSIGNED NOT NULL DEFAULT 0,
    created_by        INT UNSIGNED DEFAULT NULL,
    updated_by        INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blog_slug (slug),
    KEY idx_blog_status (status, published_at),
    KEY idx_blog_category (category),
    CONSTRAINT fk_blog_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE SET NULL,
    CONSTRAINT fk_blog_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_blog_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Success stories: publish identifiable people only with documented consent
CREATE TABLE success_stories (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title              VARCHAR(220) NOT NULL,
    slug               VARCHAR(240) NOT NULL,
    program_id         INT UNSIGNED DEFAULT NULL,
    person_name        VARCHAR(120) DEFAULT NULL,
    location           VARCHAR(150) DEFAULT NULL,
    excerpt            VARCHAR(400) NOT NULL,
    content            MEDIUMTEXT   NOT NULL,
    image              VARCHAR(255) DEFAULT NULL,
    consent_confirmed  TINYINT(1)   NOT NULL DEFAULT 0,
    status             ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    published_at       DATETIME     DEFAULT NULL,
    created_by         INT UNSIGNED DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stories_slug (slug),
    KEY idx_stories_status (status, published_at),
    CONSTRAINT fk_stories_program FOREIGN KEY (program_id) REFERENCES programs (id) ON DELETE SET NULL,
    CONSTRAINT fk_stories_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testimonials (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name               VARCHAR(120) NOT NULL,
    designation        VARCHAR(150) DEFAULT NULL,
    message            TEXT         NOT NULL,
    photo              VARCHAR(255) DEFAULT NULL,
    consent_confirmed  TINYINT(1)   NOT NULL DEFAULT 0,
    sort_order         INT          NOT NULL DEFAULT 0,
    status             ENUM('published','hidden') NOT NULL DEFAULT 'hidden',
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_testimonials_status (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE team_members (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(120) NOT NULL,
    designation   VARCHAR(150) NOT NULL,
    member_group  ENUM('management','team','advisor','volunteer') NOT NULL DEFAULT 'team',
    bio           TEXT         DEFAULT NULL,
    photo         VARCHAR(255) DEFAULT NULL,
    linkedin_url  VARCHAR(255) DEFAULT NULL,
    sort_order    INT          NOT NULL DEFAULT 0,
    status        ENUM('published','hidden') NOT NULL DEFAULT 'published',
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_team_group (member_group, status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Public documents: registration certificates, reports, digital resources
CREATE TABLE resources (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title          VARCHAR(200) NOT NULL,
    category       ENUM('certification','annual_report','impact_report','financial_statement','utilization_report','digital_resource','press_kit') NOT NULL,
    description    VARCHAR(500) DEFAULT NULL,
    file_path      VARCHAR(255) DEFAULT NULL,
    external_url   VARCHAR(255) DEFAULT NULL,
    campaign_id    INT UNSIGNED DEFAULT NULL,
    document_date  DATE         DEFAULT NULL,
    status         ENUM('published','hidden') NOT NULL DEFAULT 'published',
    sort_order     INT          NOT NULL DEFAULT 0,
    uploaded_by    INT UNSIGNED DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resources_category (category, status),
    CONSTRAINT fk_resources_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_resources_user FOREIGN KEY (uploaded_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Government / welfare schemes directory (enter only verified details with an official source link)
CREATE TABLE schemes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title             VARCHAR(200) NOT NULL,
    slug              VARCHAR(220) NOT NULL,
    category          VARCHAR(60)  NOT NULL DEFAULT 'general',   -- education|health|women|livelihood|housing|social_security|agriculture|general
    authority         VARCHAR(200) DEFAULT NULL,                  -- ministry / department / state
    summary           VARCHAR(400) NOT NULL,
    eligibility       TEXT         DEFAULT NULL,
    benefits          TEXT         DEFAULT NULL,
    how_to_apply      TEXT         DEFAULT NULL,
    documents_needed  TEXT         DEFAULT NULL,
    official_url      VARCHAR(255) DEFAULT NULL,
    source_checked_on DATE         DEFAULT NULL,                  -- when details were last verified against the official source
    image             VARCHAR(255) DEFAULT NULL,
    help_available    TINYINT(1)   NOT NULL DEFAULT 1,            -- foundation volunteers can help with the application
    status            ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    sort_order        INT          NOT NULL DEFAULT 0,
    created_by        INT UNSIGNED DEFAULT NULL,
    updated_by        INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schemes_slug (slug),
    KEY idx_schemes_status (status, category, sort_order),
    CONSTRAINT fk_schemes_created FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_schemes_updated FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    question    VARCHAR(300) NOT NULL,
    answer      TEXT         NOT NULL,
    category    VARCHAR(60)  NOT NULL DEFAULT 'general',
    sort_order  INT          NOT NULL DEFAULT 0,
    status      ENUM('published','hidden') NOT NULL DEFAULT 'published',
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_faqs_status (status, category, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Certificates issued to registered users (volunteering, internship, membership ...)
CREATE TABLE certificates (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_no    VARCHAR(40)  NOT NULL,
    user_id           INT UNSIGNED NOT NULL,
    recipient_name    VARCHAR(120) NOT NULL,
    certificate_type  ENUM('volunteer','internship','teaching','membership','appreciation','participation') NOT NULL,
    title             VARCHAR(200) NOT NULL,
    description       VARCHAR(500) DEFAULT NULL,
    issue_date        DATE         NOT NULL,
    file_path         VARCHAR(255) DEFAULT NULL,
    status            ENUM('pending','approved','revoked') NOT NULL DEFAULT 'pending',
    issued_by         INT UNSIGNED DEFAULT NULL,
    approved_by       INT UNSIGNED DEFAULT NULL,
    approved_at       DATETIME     DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificates_no (certificate_no),
    KEY idx_certificates_user (user_id, status),
    CONSTRAINT fk_cert_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_cert_issuer FOREIGN KEY (issued_by) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_cert_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- COMMUNICATION
-- =====================================================================
CREATE TABLE contact_enquiries (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no  VARCHAR(40)  DEFAULT NULL,
    type          ENUM('contact','grievance','partnership','media','fraud_report') NOT NULL DEFAULT 'contact',
    user_id       INT UNSIGNED DEFAULT NULL,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    phone         VARCHAR(20)  DEFAULT NULL,
    subject       VARCHAR(200) NOT NULL,
    message       TEXT         NOT NULL,
    status        ENUM('new','in_progress','resolved','closed') NOT NULL DEFAULT 'new',
    admin_notes   TEXT         DEFAULT NULL,
    handled_by    INT UNSIGNED DEFAULT NULL,
    ip_address    VARCHAR(45)  DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_enquiries_ref (reference_no),
    KEY idx_enquiries_status (status, type),
    CONSTRAINT fk_enquiries_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_enquiries_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE feedback (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no  VARCHAR(40)  DEFAULT NULL,
    user_id       INT UNSIGNED DEFAULT NULL,
    name          VARCHAR(120) NOT NULL,
    email         VARCHAR(190) NOT NULL,
    category      VARCHAR(60)  NOT NULL DEFAULT 'general',
    rating        TINYINT UNSIGNED DEFAULT NULL,
    message       TEXT         NOT NULL,
    status        ENUM('new','reviewed','actioned','closed') NOT NULL DEFAULT 'new',
    admin_notes   TEXT         DEFAULT NULL,
    handled_by    INT UNSIGNED DEFAULT NULL,
    ip_address    VARCHAR(45)  DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_feedback_ref (reference_no),
    KEY idx_feedback_status (status),
    CONSTRAINT fk_feedback_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_feedback_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email              VARCHAR(190) NOT NULL,
    name               VARCHAR(120) DEFAULT NULL,
    status             ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
    consent_text       VARCHAR(255) DEFAULT NULL,
    consented_at       DATETIME     DEFAULT NULL,
    source             VARCHAR(60)  DEFAULT NULL,
    unsubscribe_token  CHAR(64)     NOT NULL,
    ip_address         VARCHAR(45)  DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_newsletter_email (email),
    UNIQUE KEY uq_newsletter_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Personal data access / deletion / correction requests
CREATE TABLE data_requests (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id       INT UNSIGNED DEFAULT NULL,
    email         VARCHAR(190) NOT NULL,
    request_type  ENUM('access','deletion','correction') NOT NULL,
    details       TEXT         DEFAULT NULL,
    status        ENUM('pending','in_progress','completed','rejected') NOT NULL DEFAULT 'pending',
    response      TEXT         DEFAULT NULL,
    handled_by    INT UNSIGNED DEFAULT NULL,
    completed_at  DATETIME     DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_dr_status (status),
    CONSTRAINT fk_dr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_dr_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id          INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED  NOT NULL,
    title       VARCHAR(200)  NOT NULL,
    message     VARCHAR(1000) NOT NULL,
    link        VARCHAR(255)  DEFAULT NULL,
    type        VARCHAR(40)   NOT NULL DEFAULT 'info',   -- info|success|warning|security
    read_at     DATETIME      DEFAULT NULL,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user (user_id, read_at),
    CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED DEFAULT NULL,
    is_staff    TINYINT(1)   NOT NULL DEFAULT 0,
    action      VARCHAR(80)  NOT NULL,
    details     VARCHAR(500) DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    user_agent  VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_action_ip (action, ip_address, created_at),
    KEY idx_logs_user (user_id, action, created_at),
    KEY idx_logs_created (created_at),
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- ANALYTICS, MARKETING & SEO MONITORING (first-party, privacy friendly)
-- No IP addresses are stored: visitor_hash is a salted daily hash.
-- =====================================================================
CREATE TABLE page_views (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    path            VARCHAR(255) NOT NULL,
    page_id         INT UNSIGNED DEFAULT NULL,
    referrer_host   VARCHAR(190) DEFAULT NULL,
    utm_source      VARCHAR(100) DEFAULT NULL,
    utm_medium      VARCHAR(100) DEFAULT NULL,
    utm_campaign    VARCHAR(150) DEFAULT NULL,
    device          ENUM('desktop','mobile','tablet','bot') NOT NULL DEFAULT 'desktop',
    visitor_hash    CHAR(64)     NOT NULL,
    is_logged_in    TINYINT(1)   NOT NULL DEFAULT 0,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_pv_created (created_at),
    KEY idx_pv_path (path(100), created_at),
    KEY idx_pv_source (utm_source, created_at),
    KEY idx_pv_visitor (visitor_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversions (donation started / confirmed, applications, sign-ups) with the visit's traffic source
CREATE TABLE marketing_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_type    VARCHAR(60)   NOT NULL,      -- donation_started|donation_success|volunteer_application|teacher_application|staff_application|internship_application|registration|newsletter_signup|event_registration|contact_enquiry|fundraiser_proposal
    reference_id  INT UNSIGNED  DEFAULT NULL,
    value         DECIMAL(12,2) DEFAULT NULL,  -- verified amount for donation_success only
    landing_path  VARCHAR(255)  DEFAULT NULL,
    referrer_host VARCHAR(190)  DEFAULT NULL,
    utm_source    VARCHAR(100)  DEFAULT NULL,
    utm_medium    VARCHAR(100)  DEFAULT NULL,
    utm_campaign  VARCHAR(150)  DEFAULT NULL,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_me_type (event_type, created_at),
    KEY idx_me_source (utm_source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Missing pages people tried to open (fix them with Admin > Redirects)
CREATE TABLE not_found_log (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    path           VARCHAR(255) NOT NULL,
    referrer_host  VARCHAR(190) DEFAULT NULL,
    hits           INT UNSIGNED NOT NULL DEFAULT 1,
    first_seen     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen      DATETIME     DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_404_path (path)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DEFAULT DATA: roles & permissions
-- =====================================================================
INSERT INTO roles (id, name, slug, description, is_staff, is_system, allow_self_register, sort_order) VALUES
(1,  'Administrator',          'administrator',          'Full access to every admin module, users, roles and settings.', 1, 1, 0, 1),
(2,  'Content Editor',         'content_editor',         'Pages, menus, programmes, blog, stories, events, gallery, resources, team and FAQs.', 1, 1, 0, 2),
(3,  'Campaign Manager',       'campaign_manager',       'Fundraising campaigns, updates, proposals, expenses and campaign reports.', 1, 1, 0, 3),
(4,  'Donation Manager',       'donation_manager',       'Donations, reconciliation, receipts, refunds and financial reports.', 1, 1, 0, 4),
(5,  'Volunteer Coordinator',  'volunteer_coordinator',  'Volunteer, teacher, staff and internship applications, tasks and certificates.', 1, 1, 0, 5),
(6,  'Volunteer Teacher',      'volunteer_teacher',      'Approved volunteer teacher (assigned by a coordinator).', 0, 1, 0, 6),
(7,  'Registered Volunteer',   'registered_volunteer',   'Volunteer whose application has been activated (added on approval).', 0, 1, 0, 7),
(8,  'Member',                 'member',                 'Approved member (added when a membership application is approved).', 0, 1, 0, 8),
(9,  'Donor',                  'donor',                  'Donates to the foundation and keeps receipts in one place.', 0, 1, 1, 9),
(10, 'Beneficiary Applicant',  'beneficiary_applicant',  'Requests support or a scholarship.', 0, 1, 1, 10),
(11, 'Supporter',              'supporter',              'Website account to follow your donations, applications, events and certificates.', 0, 1, 1, 6);

INSERT INTO permissions (perm_key, label, group_name, sort_order) VALUES
('dashboard.view',        'View admin dashboard',                                   'General', 1),
('pages.manage',          'Create, edit, publish and delete pages & sections',      'Website', 10),
('menus.manage',          'Manage header, topbar and footer menus',                 'Website', 11),
('media.manage',          'Upload and manage images & media library',               'Website', 12),
('settings.manage',       'Website settings: branding, contact, social, SEO, alerts','Website', 13),
('redirects.manage',      'Manage URL redirects',                                   'Website', 14),
('programs.manage',       'Manage programmes',                                      'Content', 20),
('blog.manage',           'Manage blog, news and press releases',                   'Content', 21),
('stories.manage',        'Manage success stories',                                 'Content', 22),
('resources.manage',      'Manage certificates, reports and digital resources',     'Content', 23),
('events.manage',         'Manage events, awareness calendar and registrations',    'Content', 24),
('gallery.manage',        'Manage photo and video gallery',                         'Content', 25),
('team.manage',           'Manage management body and team members',               'Content', 26),
('testimonials.manage',   'Manage testimonials',                                    'Content', 27),
('faqs.manage',           'Manage FAQs',                                            'Content', 28),
('schemes.manage',        'Manage welfare schemes directory',                       'Content', 29),
('campaigns.manage',      'Create and edit campaigns and campaign updates',         'Fundraising', 30),
('campaigns.proposals',   'Review fundraiser proposals',                            'Fundraising', 31),
('campaigns.expenses',    'Record and approve campaign expenses',                   'Fundraising', 32),
('donations.view',        'View donations',                                         'Fundraising', 33),
('donations.manage',      'Reconcile, refund and record offline donations',         'Fundraising', 34),
('receipts.manage',       'Generate and resend receipts',                           'Fundraising', 35),
('recruitment.view',      'View volunteer, teacher, staff and internship applications', 'People', 40),
('recruitment.manage',    'Review applications, interviews, onboarding and tasks',  'People', 41),
('certificates.manage',   'Issue and approve certificates',                         'People', 42),
('beneficiaries.manage',  'Help requests and scholarships (sensitive data)',        'People', 43),
('members.view',          'View registered users and members',                      'People', 44),
('members.manage',        'Approve members, suspend or deactivate accounts',        'People', 45),
('users.manage',          'Assign roles to users',                                  'People', 46),
('roles.manage',          'Edit roles and permissions',                             'People', 47),
('data_requests.manage',  'Process personal data access/deletion requests',         'People', 48),
('enquiries.manage',      'Contact enquiries, grievances and fraud reports',        'Communication', 50),
('feedback.manage',       'Feedback submissions',                                   'Communication', 51),
('newsletter.manage',     'Newsletter subscribers and consent',                     'Communication', 52),
('reports.view',          'View reports',                                           'Reports', 60),
('exports.run',           'Export CSV reports',                                     'Reports', 61),
('analytics.view',        'Website analytics, marketing and SEO reports',           'Reports', 62),
('newsletter.send',       'Send newsletter emails to consenting subscribers',      'Communication', 53),
('security.manage',       'Security centre: sessions, failed logins, health checks','System', 72),
('widgets.manage',        'Floating widgets, announcement bar and fraud alert',     'Website', 15),
('logs.view',             'View audit logs',                                        'System', 70),
('backups.manage',        'Backups, exports and data retention',                    'System', 71);

-- Administrator: every permission (also enforced in code)
INSERT INTO role_permissions (role_id, permission_id) SELECT 1, id FROM permissions;
INSERT INTO role_permissions (role_id, permission_id) SELECT 2, id FROM permissions WHERE perm_key IN
 ('dashboard.view','pages.manage','menus.manage','media.manage','redirects.manage','analytics.view','programs.manage','blog.manage','stories.manage','resources.manage','events.manage','gallery.manage','team.manage','testimonials.manage','faqs.manage','schemes.manage');
INSERT INTO role_permissions (role_id, permission_id) SELECT 3, id FROM permissions WHERE perm_key IN
 ('dashboard.view','media.manage','campaigns.manage','campaigns.proposals','campaigns.expenses','donations.view','reports.view','exports.run','analytics.view');
INSERT INTO role_permissions (role_id, permission_id) SELECT 4, id FROM permissions WHERE perm_key IN
 ('dashboard.view','donations.view','donations.manage','receipts.manage','campaigns.expenses','reports.view','exports.run');
INSERT INTO role_permissions (role_id, permission_id) SELECT 5, id FROM permissions WHERE perm_key IN
 ('dashboard.view','recruitment.view','recruitment.manage','certificates.manage','events.manage','members.view');

-- =====================================================================
-- DEFAULT DATA: website settings (all editable in Admin > Settings)
-- Registration numbers / bank details are intentionally blank: enter only
-- verified values. Blank values are never displayed on the website.
-- =====================================================================
INSERT INTO website_settings (setting_key, setting_value) VALUES
('site_name', 'Prerit Welfare Foundation'),
('site_tagline', 'मिलकर बनाये बेहतर कल'),
('site_tagline_en', 'Together, let us build a better tomorrow'),
('brand_slogan', 'Empowering Communities • Spreading Hope • Creating Change'),
('brand_slogan_hi', 'बेहतर बनाये बेहतर बनें'),
('site_description', 'Prerit Welfare Foundation is a nonprofit organization dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care, and skill development.'),
('logo_path', ''),
('logo_horizontal_path', ''),
('favicon_path', ''),
('topbar_enabled', '1'),
('topbar_text', 'Welcome to Prerit Welfare Foundation'),
('header_cta_label', 'Donate Now'),
('header_cta_url', '/donate/'),
('footer_about_text', 'Prerit Welfare Foundation is a nonprofit organization dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care, and skill development. We strive to build a fair and inclusive society where everyone can live with dignity and equal opportunity.'),
('footer_copyright', '© {year} Prerit Welfare Foundation. All Rights Reserved.'),
('contact_email', 'info@preritwelfarefoundation.org'),
('contact_phone', ''),
('whatsapp_number', ''),
('contact_address', 'New Delhi, 110051, India'),
('office_hours', ''),
('map_embed_url', ''),
('admin_notify_email', 'info@preritwelfarefoundation.org'),
('social_facebook', ''),
('social_youtube', ''),
('social_twitter', ''),
('social_linkedin', ''),
('social_instagram', ''),
('legal_name', 'Prerit Welfare Foundation'),
('registration_type', ''),
('registration_number', ''),
('registration_date', ''),
('pan_number', ''),
('reg_12a_number', ''),
('reg_80g_number', ''),
('csr1_number', ''),
('darpan_id', ''),
('fcra_number', ''),
('bank_account_name', ''),
('bank_name', ''),
('bank_account_number', ''),
('bank_ifsc', ''),
('bank_branch', ''),
('upi_id', ''),
('online_donations_enabled', '1'),
('donation_min_amount', '100'),
('donation_presets', '500,1000,2500,5000'),
('receipt_signatory', 'Authorised Signatory'),
('receipt_footer_note', 'This is a computer-generated receipt for a donation received by Prerit Welfare Foundation.'),
('staff_positions', ''),
('internship_areas', 'Education,Health,Women Empowerment,Environment,Social Media & Communication,Fundraising,Research & Documentation'),
('allow_registration', '1'),
('require_email_verification', '1'),
('impact_stats', '[]'),
('seo_title_suffix', ' | Prerit Welfare Foundation'),
('seo_default_description', 'Prerit Welfare Foundation, New Delhi: empowering underprivileged communities through education, healthcare, women empowerment, environment and skills.'),
('seo_default_og_image', ''),
('google_site_verification', ''),
('ga_measurement_id', ''),
('analytics_enabled', '1'),
('analytics_respect_dnt', '1'),
('analytics_retention_days', '400'),
('twitter_handle', ''),
('robots_txt_extra', ''),
('seo_noindex_site', '0'),
('share_buttons_enabled', '1'),
('newsletter_enabled', '1'),
('newsletter_consent_text', 'I agree to receive occasional email updates from Prerit Welfare Foundation. I can unsubscribe at any time.'),
('footer_show_newsletter', '1'),
('footer_show_contact', '1'),
('footer_show_social', '1'),
('footer_show_registration', '1'),
('footer_bottom_links', '1'),
('auth_panel_title', 'Together, let us build a better tomorrow'),
('auth_panel_text', 'Sign in to track your donations and receipts, follow your volunteer and teacher applications, register for events and download your certificates.'),
('auth_panel_image', ''),
-- floating widgets
('widget_whatsapp_enabled', '1'),
('widget_whatsapp_message', 'Hello Prerit Welfare Foundation, I would like to know more about your work.'),
('widget_contact_enabled', '1'),
('widget_help_enabled', '1'),
('widget_help_greeting', 'Namaste! I can help you find information about donating, volunteering, teaching, internships, receipts and support. This is an automated help assistant, not a live chat.'),
('widget_back_to_top', '1'),
('motion_effects_enabled', '1'),
-- site announcement (bar at the top or a one-time popup)
('announcement_enabled', '0'),
('announcement_style', 'bar'),
('announcement_text', ''),
('announcement_link_label', ''),
('announcement_link_url', ''),
('announcement_starts', ''),
('announcement_ends', ''),
('announcement_version', '1'),
-- fraud / impersonation advisory: enable ONLY after a genuine, verified incident or risk.
('fraud_alert_enabled', '0'),
('fraud_alert_title', 'Beware of fraud'),
('fraud_alert_message', 'We have received reports of people misusing the name of Prerit Welfare Foundation. Please verify any request for money or personal details before you respond.'),
('fraud_alert_title_hi', 'धोखाधड़ी से सावधान'),
('fraud_alert_message_hi', 'हमें प्रेरित वेलफेयर फाउंडेशन के नाम का दुरुपयोग किए जाने की सूचनाएँ मिली हैं। पैसे या व्यक्तिगत जानकारी के किसी भी अनुरोध का उत्तर देने से पहले उसकी पुष्टि करें।'),
('fraud_alert_points', 'Do not share OTPs, passwords, card or bank details with anyone.\nDonate only through the Donate page on this website or to the foundation''s own bank account.\nIf someone contacts you in our name, verify it using the contact details on this website.'),
('fraud_alert_report_text', 'To report a suspicious call, message, website or social media account, use the reporting form or write to us. For financial fraud you can also call the national cyber crime helpline 1930 or report at cybercrime.gov.in.'),
('fraud_alert_verified_note', ''),
('fraud_alert_version', '1'),
('fraud_alert_show', 'once_per_visit'),
-- grievance redressal (shown on /grievance-redressal/ only when filled in)
('grievance_officer_name', ''),
('grievance_officer_email', ''),
('grievance_ack_days', ''),
('grievance_resolution_days', ''),
-- data retention
('retention_logs_days', '365'),
('retention_rejected_applications_days', '730'),
('retention_enquiries_days', '730'),
('retention_last_run', ''),
('installed_at', '');

-- =====================================================================
-- DEFAULT DATA: page registry (content sections are added further below)
-- =====================================================================
INSERT INTO pages (slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, is_system, show_in_sitemap, noindex, sort_order) VALUES
('home',                    'home',               'Home', NULL, NULL, NULL, 'Prerit Welfare Foundation, New Delhi: empowering underprivileged communities through education, healthcare, women empowerment, environment and skills.', NULL, 1, 1, 0, 1),
('about',                   'default',            'About Us', 'About us', 'Together, let us build a better tomorrow - मिलकर बनाये बेहतर कल', 'assets/images/hero/about.svg', 'Learn about Prerit Welfare Foundation, a nonprofit organization in New Delhi working for education, health, women empowerment, environment and skill development.', NULL, 1, 1, 0, 10),
('our-mission-vision',      'default',            'Mission & Vision', 'About us', 'What drives everything we do', 'assets/images/hero/community.svg', 'The mission, vision and values of Prerit Welfare Foundation.', NULL, 1, 1, 0, 11),
('management-body',         'team',               'Management Body', 'About us', 'The people responsible for the governance of the foundation', 'assets/images/hero/team.svg', 'The management body of Prerit Welfare Foundation, New Delhi: the people responsible for the governance and direction of the foundation.', '{"group":"management"}', 1, 1, 0, 12),
('ngo-details',             'ngo_details',        'NGO Details', 'About us', 'Registration and organisation details', 'assets/images/hero/transparency.svg', 'Registration, legal and contact details of Prerit Welfare Foundation, New Delhi - shown here as they are verified and entered by the foundation.', NULL, 1, 1, 0, 13),
('volunteers',              'team',               'Our Team', 'About us', 'The team and volunteers behind our work', 'assets/images/hero/team.svg', 'Meet the team and volunteers of Prerit Welfare Foundation who carry out our education, health, women empowerment, environment and relief work.', '{"group":"team"}', 1, 1, 0, 14),
('achievements',            'default',            'Achievements', 'About us', 'Milestones in our journey', 'assets/images/hero/reports.svg', 'Achievements and milestones of Prerit Welfare Foundation.', NULL, 1, 1, 0, 15),
('certifications',          'documents',          'Certificates', 'About us', 'Registration and recognition certificates', 'assets/images/hero/transparency.svg', 'Registration certificates and recognitions of Prerit Welfare Foundation.', '{"category":"certification"}', 1, 1, 0, 16),
('gallery',                 'gallery',            'Photo Gallery', 'Media', 'Moments from our programmes, events and campaigns', 'assets/images/hero/gallery.svg', 'Photos from the programmes, events and campaigns of Prerit Welfare Foundation.', '{"media_type":"image"}', 1, 1, 0, 17),
('video-gallery',           'gallery',            'Video Gallery', 'Media', 'Watch our work on the ground', 'assets/images/hero/gallery.svg', 'Videos from the programmes and events of Prerit Welfare Foundation, played in YouTube privacy-enhanced mode and shared only with consent.', '{"media_type":"video"}', 1, 1, 0, 18),
('become-a-volunteer',      'volunteer',          'Volunteer with Us', 'Get involved', 'Give your time and skills to build a better tomorrow', 'assets/images/hero/volunteer.svg', 'Volunteer with Prerit Welfare Foundation in education, health, women empowerment, environment and skill development.', NULL, 1, 1, 0, 20),
('volunteer-teacher',       'teacher',            'Volunteer Teacher Programme', 'Get involved', 'Share your knowledge with children who need it most', 'assets/images/hero/teacher.svg', 'Become a volunteer teacher with Prerit Welfare Foundation, in person or online.', NULL, 1, 1, 0, 21),
('volunteer-staff',         'staff',              'Volunteer Staff Application', 'Get involved', 'Join our team and build a career with purpose', 'assets/images/hero/career.svg', 'Apply for staff and volunteer staff positions at Prerit Welfare Foundation.', NULL, 1, 1, 0, 22),
('internships',             'internship',         'Internships', 'Get involved', 'Learn by doing - internships for students and young professionals', 'assets/images/hero/career.svg', 'Internship opportunities at Prerit Welfare Foundation for students and young professionals.', NULL, 1, 1, 0, 23),
('campaigns',               'campaigns',          'Donate / Fundraise', 'Fundraising', 'Choose a cause - progress shows verified donations only', 'assets/images/hero/campaigns.svg', 'Support fundraising campaigns of Prerit Welfare Foundation, including disaster relief, education and food distribution.', NULL, 1, 1, 0, 24),
('awareness-campaign',      'default',            'Awareness Campaign', 'Get involved', 'Spreading knowledge that changes lives', 'assets/images/hero/events.svg', 'Awareness campaigns by Prerit Welfare Foundation on health, education, environment and rights.', NULL, 1, 1, 0, 25),
('skill-development',       'program',            'Skill Development & Training', 'Our programmes', 'Training for jobs and enterprise opportunities', 'assets/images/programs/skill-development.svg', 'Skill development and training by Prerit Welfare Foundation - vocational and digital training, career guidance and livelihood support.', '{"program":"skill-development"}', 1, 1, 0, 26),
('blog',                    'blog',               'Blogs & Articles', 'Media', 'Stories, updates and ideas from our work', 'assets/images/hero/blog.svg', 'Articles and news from Prerit Welfare Foundation about our work in education, healthcare, women empowerment, environment and skill development.', '{"categories":["blog","news"]}', 1, 1, 0, 27),
('success-stories',         'stories',            'Success Stories', 'Media', 'Real stories of change from the communities we serve', 'assets/images/hero/community.svg', 'Stories of change from the communities served by Prerit Welfare Foundation, published only with the consent of the people featured.', NULL, 1, 1, 0, 28),
('media',                   'blog',               'Media / Press Releases', 'Media', 'Press releases and media coverage', 'assets/images/hero/blog.svg', 'Press releases and media resources from Prerit Welfare Foundation, New Delhi, about our programmes, campaigns, events and community work.', '{"categories":["press"]}', 1, 1, 0, 29),
('digital-resources',       'documents',          'Digital Resources', 'Media', 'Guides, toolkits and learning resources', 'assets/images/hero/reports.svg', 'Free guides, toolkits and learning resources from Prerit Welfare Foundation on education, health, women empowerment, environment and skills.', '{"category":"digital_resource"}', 1, 1, 0, 30),
('membership',              'membership',         'Membership', 'Get involved', 'Become a member of Prerit Welfare Foundation', 'assets/images/hero/membership.svg', 'Become a member of Prerit Welfare Foundation: membership types, how to apply, and how every application is reviewed and approved by the foundation.', NULL, 1, 1, 0, 31),
('scholarship',             'scholarship',        'Scholarship Application', 'Support', 'Helping deserving students continue their education', 'assets/images/hero/scholarship.svg', 'Apply online for scholarship support from Prerit Welfare Foundation for students who need help to continue their education, and follow your application.', NULL, 1, 1, 0, 32),
('request-help',            'request_help',       'Request Help', 'Support', 'Tell us how we can support you or someone you know', 'assets/images/hero/help.svg', 'Request support from Prerit Welfare Foundation for education, health, livelihood, food or disaster relief.', NULL, 1, 1, 0, 33),
('beneficiary-support',     'default',            'Beneficiary Support', 'Support', 'How individuals and families can request support', 'assets/images/hero/help.svg', 'How individuals and families can request support from Prerit Welfare Foundation.', NULL, 1, 1, 0, 34),
('schemes',                 'schemes',            'Welfare Schemes', 'Support', 'Government and welfare schemes - and how we can help you apply', 'assets/images/hero/help.svg', 'Information on government and welfare schemes for education, health, women, livelihood and social security, with help to apply.', NULL, 1, 1, 0, 36),
('application-status',      'application_status', 'Application Status', 'Support', 'Track your application with your reference number', 'assets/images/hero/status.svg', 'Check the status of your volunteer, teacher, internship, staff, scholarship or help application.', NULL, 1, 1, 0, 35),
('programs',                'programs',           'Our Programmes', 'Our work', 'Empowering vulnerable and underserved communities', 'assets/images/hero/community.svg', 'Our programmes: education, healthcare, women and youth empowerment, rural development, environment, skills, digital learning, scholarships, food and relief.', NULL, 1, 1, 0, 40),
('events-page',             'events',             'Events', 'Events & community', 'Camps, drives, awareness programmes and community events', 'assets/images/hero/events.svg', 'Upcoming and past camps, drives, awareness programmes and community events of Prerit Welfare Foundation, with free registration where it is open.', NULL, 1, 1, 0, 50),
('event-details',           'event_detail',       'Event Details', 'Events & community', NULL, 'assets/images/hero/events.svg', 'Event details.', NULL, 1, 0, 0, 51),
('awareness-calendar',      'calendar',           'Awareness Calendar', 'Events & community', 'Important awareness days and our planned activities', 'assets/images/hero/events.svg', 'A month-by-month calendar of national and international awareness days on education, health, women, environment and more, with ways to take part.', NULL, 1, 1, 0, 52),
('impact-reports',          'documents',          'Impact Reports', 'Transparency', 'Reporting on our programmes and outcomes', 'assets/images/hero/reports.svg', 'Impact and annual reports of Prerit Welfare Foundation on our programmes and how they are run, published here as they become available.', '{"category":"impact_report","also":["annual_report"]}', 1, 1, 0, 53),
('campaign',                'campaign_detail',    'Campaign', 'Fundraising', NULL, 'assets/images/hero/campaigns.svg', 'Campaign details.', NULL, 1, 0, 0, 60),
('donate',                  'donate',             'Donate Now', 'Donate', 'Your support helps us empower underprivileged communities', 'assets/images/hero/donate.svg', 'Donate online securely to Prerit Welfare Foundation and receive a receipt for every verified donation.', NULL, 1, 1, 0, 61),
('donation-success',        'donation_success',   'Thank You', NULL, NULL, NULL, 'Donation status.', NULL, 1, 0, 1, 62),
('donation-failed',         'donation_failed',    'Payment Not Completed', NULL, NULL, NULL, 'Donation status.', NULL, 1, 0, 1, 63),
('donation-receipt',        'donation_receipt',   'Donation Receipt', NULL, NULL, NULL, 'Donation receipt.', NULL, 1, 0, 1, 64),
('start-a-fundraiser',      'fundraiser',         'Start a Fundraiser', 'Fundraising', 'Propose a fundraising campaign for a cause you care about', 'assets/images/hero/campaigns.svg', 'Propose a fundraiser with Prerit Welfare Foundation for a cause linked to our work. Every proposal is reviewed, and donations go to the foundation.', NULL, 1, 1, 0, 65),
('financial-transparency',  'transparency',       'Financial Transparency', 'Transparency', 'Accountability for every rupee received', 'assets/images/hero/transparency.svg', 'Financial transparency, verified donation totals and registration details of Prerit Welfare Foundation.', NULL, 1, 1, 0, 66),
('utilization-reports',     'utilization',        'Campaign Utilisation Reports', 'Transparency', 'How campaign funds have been used', 'assets/images/hero/reports.svg', 'How campaign funds of Prerit Welfare Foundation are used: verified donations compared with the approved, published expenses of each campaign.', NULL, 1, 1, 0, 67),
('contact',                 'contact',            'Contact Us', 'Contact', 'We would love to hear from you', 'assets/images/hero/contact.svg', 'Contact Prerit Welfare Foundation in New Delhi by email or our enquiry form about volunteering, partnerships and CSR, donations or support from our programmes.', '{"form":"contact"}', 1, 1, 0, 70),
('feedback-form',           'feedback',           'Feedback Form', 'Contact', 'Help us improve - share your feedback', 'assets/images/hero/contact.svg', 'Tell Prerit Welfare Foundation what you think about our programmes, events, volunteering or this website. Your feedback helps us improve.', NULL, 1, 1, 0, 71),
('grievance-redressal',     'contact',            'Grievance Redressal', 'Policies', 'Raise a concern or complaint with us', 'assets/images/hero/policy.svg', 'Raise a concern or complaint with Prerit Welfare Foundation and see how grievances are received, acknowledged, reviewed and answered.', '{"form":"grievance"}', 1, 1, 0, 72),
('report-fraud',            'contact',            'Report Fraud or Misuse', 'Safety', 'Tell us about suspicious requests made in our name', 'assets/images/hero/policy.svg', 'Report a suspicious call, message, website or account using the name of Prerit Welfare Foundation.', '{"form":"fraud_report"}', 1, 1, 0, 73),
('privacy-policy',          'policy',             'Privacy Policy', 'Policies', 'How we collect, use and protect your information', 'assets/images/hero/policy.svg', 'How Prerit Welfare Foundation collects, uses, stores and protects personal information of donors, volunteers, applicants and visitors, and your rights.', NULL, 1, 1, 0, 74),
('terms-conditions',        'policy',             'Terms & Conditions', 'Policies', 'Terms of use of this website', 'assets/images/hero/policy.svg', 'Terms and conditions for using the Prerit Welfare Foundation website, covering donations, accounts, applications, content and acceptable use.', NULL, 1, 1, 0, 75),
('donation-refund-policy',  'policy',             'Donation & Refund Policy', 'Policies', 'How donations are received, used and refunded', 'assets/images/hero/policy.svg', 'How Prerit Welfare Foundation receives and verifies donations, issues receipts, uses funds and handles refund requests for duplicate or mistaken payments.', NULL, 1, 1, 0, 76),
('volunteer-policy',        'policy',             'Volunteer Policy', 'Policies', 'Guidelines for volunteers, interns and volunteer teachers', 'assets/images/hero/policy.svg', 'The volunteer policy and code of conduct of Prerit Welfare Foundation for volunteers, interns and volunteer teachers, including safeguarding rules.', NULL, 1, 1, 0, 77),
('child-protection-policy', 'policy',             'Child Protection Policy', 'Policies', 'Our commitment to keeping children safe', 'assets/images/hero/policy.svg', 'Child protection and safeguarding policy of Prerit Welfare Foundation.', NULL, 1, 1, 0, 78),
('newsletter',              'newsletter',         'Newsletter', 'Stay connected', 'Occasional updates about our work, events and campaigns', 'assets/images/hero/contact.svg', 'Subscribe to occasional email updates from Prerit Welfare Foundation about programmes, events, campaigns and volunteering. Unsubscribe at any time.', NULL, 1, 1, 0, 79),
('faq',                     'faq',                'Frequently Asked Questions', 'Help', 'Quick answers about donations, volunteering and support', 'assets/images/hero/faq.svg', 'Answers to common questions about donating, volunteering and getting support.', NULL, 1, 1, 0, 79),
('sitemap',                 'sitemap',            'Sitemap', NULL, 'All pages on our website', NULL, 'Every page of the Prerit Welfare Foundation website in one list - programmes, campaigns, events, volunteering, policies and more.', NULL, 1, 1, 0, 80),
('not-found',               'not_found',          'Page Not Found', NULL, 'Sorry, we could not find that page', NULL, 'Page not found.', NULL, 1, 0, 1, 81),
('login',                   'login',              'Login', NULL, NULL, NULL, 'Log in to your Prerit Welfare Foundation account.', NULL, 1, 0, 1, 90),
('register',                'register',           'Create an Account', NULL, NULL, NULL, 'Create an account with Prerit Welfare Foundation.', NULL, 1, 0, 0, 91),
('forgot-password',         'forgot_password',    'Forgot Password', NULL, NULL, NULL, 'Reset your password.', NULL, 1, 0, 1, 92),
('reset-password',          'reset_password',     'Choose a New Password', NULL, NULL, NULL, 'Choose a new password.', NULL, 1, 0, 1, 93),
('verify-email',            'verify_email',       'Verify Your Email', NULL, NULL, NULL, 'Email verification.', NULL, 1, 0, 1, 94),
('dashboard',               'member',             'My Dashboard', NULL, NULL, NULL, 'Member dashboard.', NULL, 1, 0, 1, 95),
('logout',                  'logout',             'Logout', NULL, NULL, NULL, 'Logout.', NULL, 1, 0, 1, 96);

UPDATE pages SET published_at = NOW();
UPDATE pages c JOIN pages p ON p.slug = 'about' SET c.parent_id = p.id
 WHERE c.slug IN ('our-mission-vision','management-body','ngo-details','volunteers','achievements','certifications');
UPDATE pages c JOIN pages p ON p.slug = 'gallery' SET c.parent_id = p.id WHERE c.slug = 'video-gallery';
UPDATE pages c JOIN pages p ON p.slug = 'events-page' SET c.parent_id = p.id WHERE c.slug IN ('event-details','awareness-calendar');
UPDATE pages c JOIN pages p ON p.slug = 'campaigns' SET c.parent_id = p.id WHERE c.slug IN ('campaign','start-a-fundraiser','utilization-reports');
UPDATE pages c JOIN pages p ON p.slug = 'become-a-volunteer' SET c.parent_id = p.id WHERE c.slug IN ('volunteer-teacher','volunteer-staff','internships');
UPDATE pages c JOIN pages p ON p.slug = 'blog' SET c.parent_id = p.id WHERE c.slug = 'media';
UPDATE pages c JOIN pages p ON p.slug = 'contact' SET c.parent_id = p.id WHERE c.slug IN ('feedback-form','report-fraud');
UPDATE pages c JOIN pages p ON p.slug = 'programs' SET c.parent_id = p.id WHERE c.slug = 'skill-development';

-- =====================================================================
-- DEFAULT DATA: menus (Admin > Menus)
-- =====================================================================
INSERT INTO menus (id, menu_key, name, description) VALUES
(1, 'main',            'Main navigation',          'Header menu. Items with children become dropdowns.'),
(2, 'footer_about',    'Footer: About',            'First footer link column'),
(3, 'footer_involved', 'Footer: Get Involved',     'Second footer link column'),
(4, 'footer_legal',    'Footer: Contact & Legal',  'Third footer link column'),
(5, 'topbar',          'Top bar: Apply links',     'Quick "apply" links shown in the top bar above the header');

INSERT INTO menu_items (id, menu_id, parent_id, label, url, icon, description, sort_order) VALUES
(1,  1, NULL, 'Home',       '/', NULL, NULL, 1),
(2,  1, NULL, 'About',      '/about/', NULL, NULL, 2),
(3,  1, NULL, 'Events',     '/events-page/', NULL, NULL, 3),
(4,  1, NULL, 'Gallery',    '/gallery/', NULL, NULL, 4),
(5,  1, NULL, 'Pages',      '/sitemap/', NULL, NULL, 5),
(6,  1, NULL, 'Programmes', '/programs/', NULL, NULL, 6),
(7,  1, NULL, 'Campaigns',  '/campaigns/', NULL, NULL, 7),
(8,  1, NULL, 'Volunteer',  '/become-a-volunteer/', NULL, NULL, 8),
(9,  1, NULL, 'Blog',       '/blog/', NULL, NULL, 9),
(10, 1, NULL, 'Contact',    '/contact/', NULL, NULL, 10);

INSERT INTO menu_items (menu_id, parent_id, label, url, icon, description, sort_order) VALUES
(1, 2, 'About Us',              '/about/', 'info', 'Who we are and what we do', 1),
(1, 2, 'Mission & Vision',      '/our-mission-vision/', 'target', 'What drives us', 2),
(1, 2, 'Management Body',       '/management-body/', 'users', 'Governance of the foundation', 3),
(1, 2, 'NGO Details',           '/ngo-details/', 'file-text', 'Registration and organisation details', 4),
(1, 2, 'Our Team',              '/volunteers/', 'heart', 'The people behind our work', 5),
(1, 2, 'Achievements',          '/achievements/', 'award', 'Milestones in our journey', 6),
(1, 2, 'Certificates',          '/certifications/', 'shield', 'Registrations and recognition', 7),
(1, 2, 'Our Gallery',           '/gallery/', 'image', 'Photos and videos', 8),
(1, 3, 'Upcoming Events',       '/events-page/', 'calendar', 'Camps, drives and programmes', 1),
(1, 3, 'Awareness Calendar',    '/awareness-calendar/', 'calendar', 'Important awareness days', 2),
(1, 3, 'Awareness Campaign',    '/awareness-campaign/', 'megaphone', 'Spreading knowledge', 3),
(1, 3, 'Success Stories',       '/success-stories/', 'star', 'Real stories of change', 4),
(1, 3, 'Impact Reports',        '/impact-reports/', 'bar-chart', 'Reporting on our work', 5),
(1, 4, 'Photo Gallery',         '/gallery/', 'image', NULL, 1),
(1, 4, 'Video Gallery',         '/video-gallery/', 'play', NULL, 2),
(1, 5, 'Membership',            '/membership/', 'id-card', 'Become a member', 1),
(1, 5, 'Skill Development & Training', '/skill-development/', 'tool', 'Training and livelihoods', 2),
(1, 5, 'Media / Press Releases','/media/', 'file-text', 'Press and media', 3),
(1, 5, 'Digital Resources',     '/digital-resources/', 'download', 'Guides and toolkits', 4),
(1, 5, 'Financial Transparency','/financial-transparency/', 'shield', 'Accountability', 5),
(1, 5, 'Scholarship',           '/scholarship/', 'book', 'Apply for support', 6),
(1, 5, 'Request Help',          '/request-help/', 'life-buoy', 'Ask for support', 7),
(1, 5, 'Welfare Schemes',       '/schemes/', 'list', 'Schemes and how to apply', 7),
(1, 5, 'Application Status',    '/application-status/', 'search', 'Track your application', 8),
(1, 5, 'FAQ',                   '/faq/', 'help-circle', 'Common questions', 9),
(1, 5, 'Report Fraud',          '/report-fraud/', 'alert-triangle', 'Report misuse of our name', 10),
(1, 6, 'All Programmes',        '/programs/', 'grid', 'Overview of our work', 1),
(1, 6, 'Education & Literacy',  '/programs/education-literacy/', 'book', NULL, 2),
(1, 6, 'Healthcare',            '/programs/healthcare/', 'heart', NULL, 3),
(1, 6, 'Women Empowerment',     '/programs/women-empowerment/', 'users', NULL, 4),
(1, 6, 'Youth Empowerment',     '/programs/youth-empowerment/', 'zap', NULL, 5),
(1, 6, 'Rural Development',     '/programs/rural-development/', 'home', NULL, 6),
(1, 6, 'Environment Protection','/programs/environment-protection/', 'leaf', NULL, 7),
(1, 6, 'Skill Development',     '/skill-development/', 'tool', NULL, 8),
(1, 6, 'Digital Learning',      '/programs/digital-learning/', 'monitor', NULL, 9),
(1, 6, 'Scholarship & Educational Support', '/programs/scholarship-educational-support/', 'award', NULL, 10),
(1, 6, 'Food Distribution',     '/programs/food-distribution/', 'package', NULL, 11),
(1, 6, 'Disaster Relief',       '/programs/disaster-relief/', 'life-buoy', NULL, 12),
(1, 7, 'All Campaigns',         '/campaigns/', 'heart', 'Choose a cause', 1),
(1, 7, 'Donate Now',            '/donate/', 'gift', 'General donation', 2),
(1, 7, 'Start a Fundraiser',    '/start-a-fundraiser/', 'flag', 'Raise funds for a cause', 3),
(1, 7, 'Utilisation Reports',   '/utilization-reports/', 'bar-chart', 'How funds were used', 4),
(1, 7, 'Financial Transparency','/financial-transparency/', 'shield', 'Verified totals', 5),
(1, 8, 'Volunteer with Us',     '/become-a-volunteer/', 'heart', 'Give your time and skills', 1),
(1, 8, 'Volunteer Teacher Programme', '/volunteer-teacher/', 'book', 'Teach children in need', 2),
(1, 8, 'Volunteer Staff Application', '/volunteer-staff/', 'briefcase', 'Work with us', 3),
(1, 8, 'Internships',           '/internships/', 'award', 'Learn by doing', 4),
(1, 8, 'Application Status',    '/application-status/', 'search', 'Track your application', 5),
(1, 9, 'Blogs & Articles',      '/blog/', 'file-text', NULL, 1),
(1, 9, 'Success Stories',       '/success-stories/', 'star', NULL, 2),
(1, 9, 'Media / Press Releases','/media/', 'megaphone', NULL, 3),
(1, 9, 'Digital Resources',     '/digital-resources/', 'download', NULL, 4),
(1, 10, 'Contact Us',           '/contact/', 'mail', NULL, 1),
(1, 10, 'Feedback Form',        '/feedback-form/', 'message', NULL, 2),
(1, 10, 'Grievance Redressal',  '/grievance-redressal/', 'shield', NULL, 3),
(1, 10, 'Report Fraud',         '/report-fraud/', 'alert-triangle', NULL, 4);

INSERT INTO menu_items (menu_id, parent_id, label, url, sort_order) VALUES
(2, NULL, 'About Us',            '/about/', 1),
(2, NULL, 'Mission & Vision',    '/our-mission-vision/', 2),
(2, NULL, 'Management Body',     '/management-body/', 3),
(2, NULL, 'NGO Details',         '/ngo-details/', 4),
(2, NULL, 'Our Team',            '/volunteers/', 5),
(2, NULL, 'Achievements',        '/achievements/', 6),
(2, NULL, 'Certificates',        '/certifications/', 7),
(2, NULL, 'Our Gallery',         '/gallery/', 8),
(3, NULL, 'Volunteer with Us',   '/become-a-volunteer/', 1),
(3, NULL, 'Internships',         '/internships/', 2),
(3, NULL, 'Donate / Fundraise',  '/campaigns/', 3),
(3, NULL, 'Awareness Campaign',  '/awareness-campaign/', 4),
(3, NULL, 'Skill Development & Training', '/skill-development/', 5),
(3, NULL, 'Blogs & Articles',    '/blog/', 6),
(3, NULL, 'Success Stories',     '/success-stories/', 7),
(3, NULL, 'Media / Press Releases', '/media/', 8),
(3, NULL, 'Digital Resources',   '/digital-resources/', 9),
(3, NULL, 'Welfare Schemes',     '/schemes/', 10),
(3, NULL, 'Events',              '/events-page/', 11),
(3, NULL, 'Video Gallery',       '/video-gallery/', 12),
(4, NULL, 'Feedback Form',       '/feedback-form/', 1),
(4, NULL, 'Privacy Policy',      '/privacy-policy/', 2),
(4, NULL, 'Terms & Conditions',  '/terms-conditions/', 3),
(4, NULL, 'Donation & Refund Policy', '/donation-refund-policy/', 4),
(4, NULL, 'Volunteer Policy',    '/volunteer-policy/', 5),
(4, NULL, 'Child Protection Policy', '/child-protection-policy/', 6),
(4, NULL, 'Grievance Redressal', '/grievance-redressal/', 7),
(4, NULL, 'Report Fraud',        '/report-fraud/', 8),
(4, NULL, 'FAQ',                 '/faq/', 9),
(4, NULL, 'Sitemap',             '/sitemap/', 10);

INSERT INTO menu_items (menu_id, parent_id, label, url, icon, sort_order) VALUES
(5, NULL, 'Apply as Volunteer',  '/become-a-volunteer/', 'heart', 1),
(5, NULL, 'Volunteer Teacher',   '/volunteer-teacher/', 'book', 2),
(5, NULL, 'Job Apply',           '/volunteer-staff/', 'briefcase', 3),
(5, NULL, 'Internship',          '/internships/', 'award', 4);

-- =====================================================================
-- DEFAULT DATA: permanent redirects from earlier addresses
-- =====================================================================
INSERT INTO redirects (source_path, target_url) VALUES
('index.php', '/'), ('about.php', '/about/'), ('our-team.php', '/volunteers/'), ('our-work.php', '/programs/'),
('education.php', '/programs/education-literacy/'), ('healthcare.php', '/programs/healthcare/'),
('women-empowerment.php', '/programs/women-empowerment/'), ('youth-empowerment.php', '/programs/youth-empowerment/'),
('rural-development.php', '/programs/rural-development/'), ('environment.php', '/programs/environment-protection/'),
('skill-development.php', '/skill-development/'), ('disaster-relief.php', '/programs/disaster-relief/'),
('campaigns.php', '/campaigns/'), ('campaign-details.php', '/campaigns/'), ('donate.php', '/donate/'),
('blog.php', '/blog/'), ('blog-details.php', '/blog/'), ('gallery.php', '/gallery/'), ('contact.php', '/contact/'),
('faq.php', '/faq/'), ('membership.php', '/membership/'), ('scholarship.php', '/scholarship/'),
('request-help.php', '/request-help/'), ('application-status.php', '/application-status/'),
('success-stories.php', '/success-stories/'), ('financial-transparency.php', '/financial-transparency/'),
('apply-volunteer.php', '/become-a-volunteer/'), ('volunteer.php', '/become-a-volunteer/'),
('volunteer-teacher.php', '/volunteer-teacher/'), ('apply-teacher.php', '/volunteer-teacher/'),
('volunteer-staff.php', '/volunteer-staff/'), ('apply-staff.php', '/volunteer-staff/'),
('beneficiary.php', '/beneficiary-support/'), ('impact-reports.php', '/impact-reports/'), ('terms.php', '/terms-conditions/'),
('donation-policy.php', '/donation-refund-policy/'), ('grievance.php', '/grievance-redressal/'), ('privacy-policy.php', '/privacy-policy/'),
('volunteer-policy.php', '/volunteer-policy/'), ('child-protection-policy.php', '/child-protection-policy/'),
('login.php', '/login/'), ('register.php', '/register/'), ('logout.php', '/logout/'),
('forgot-password.php', '/forgot-password/'), ('reset-password.php', '/reset-password/'),
('member-dashboard.php', '/dashboard/'), ('member-profile.php', '/dashboard/profile/'), ('my-applications.php', '/dashboard/applications/'),
('my-donations.php', '/dashboard/donations/'), ('my-certificates.php', '/dashboard/certificates/'),
('events.php', '/events-page/'), ('event-details.php', '/events-page/'), ('sitemap.php', '/sitemap/'),
('donation-success.php', '/donate/'), ('donation-failed.php', '/donate/'), ('donation-receipt.php', '/dashboard/donations/'),
('our-work', '/programs/'), ('about-us', '/about/'), ('contact-us', '/contact/'), ('events', '/events-page/'),
('donate-now', '/donate/'), ('volunteer', '/become-a-volunteer/'), ('mission-vision', '/our-mission-vision/'),
('team', '/volunteers/'), ('our-team', '/volunteers/'), ('terms', '/terms-conditions/'), ('feedback', '/feedback-form/'),
('programs/skill-development', '/skill-development/'), ('programs/education', '/programs/education-literacy/'),
('programs/environment', '/programs/environment-protection/'), ('wp-login.php', '/login/');

-- =====================================================================
-- MODULE CONTENT (page sections, programmes, campaigns as drafts, FAQs,
-- policies, awareness days). Descriptive text only - no invented data.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Module M1
-- ---------------------------------------------------------------------
-- =====================================================================
-- M1 (site management) seed: content sections for About, Mission & Vision, Achievements,
-- Awareness Campaign and Beneficiary Support. Descriptive content only - no figures, names,
-- testimonials or registration numbers. Re-runnable: the sections of these pages are replaced.
-- Module section types (campaigns_grid, events_list, documents_list, awareness_days, schemes_list)
-- render nothing until their module and data exist. timeline has no seeded milestones.
-- =====================================================================
SET NAMES utf8mb4;
DELETE FROM page_sections WHERE page_id IN (SELECT id FROM pages WHERE slug IN ('about', 'our-mission-vision', 'achievements', 'awareness-campaign', 'beneficiary-support'));

-- about
SET @p := (SELECT id FROM pages WHERE slug = 'about');
UPDATE pages SET meta_description = 'About Prerit Welfare Foundation, a New Delhi nonprofit working for education, healthcare, women empowerment, environment and skill development.' WHERE id = @p;
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'who-we-are', 'Who we are', 'A nonprofit working for a fair and inclusive society', 'Prerit Welfare Foundation is a nonprofit organisation based in New Delhi, dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care and skill development.', '<p>We work with children, women, young people and families who have been left behind - not as passive recipients of charity, but as partners in building a better future for themselves and their neighbourhoods. Our volunteers, volunteer teachers and supporters give their time and skills so that practical help reaches the people who need it.</p>', 'assets/images/hero/about.svg', '["Learning support for children and adults","Health awareness, camps and referral support","Skills, livelihoods and Self-Help Groups for women","Care for the environment and sustainable living","Relief support during floods and emergencies"]', '{"image_alt":"Illustration of volunteers and community members working together","buttons":[{"label":"Our programmes","url":"/programs/","style":"primary","icon":"grid"},{"label":"Contact us","url":"/contact/","style":"outline","icon":"mail"}]}', 'white', 'visible', 1),
(@p, 'richtext', 'our-purpose', 'Our purpose', 'Why we do this work', NULL, '<p>Across India, many families still face barriers that others take for granted: a child who cannot continue school, a woman with skills but no way to earn from them, a household that does not know which welfare scheme it is entitled to, or a community hit by floods with nowhere to turn.</p><p>Prerit Welfare Foundation exists to help close these gaps. We bring together volunteers, teachers, donors and local partners to provide practical support - learning, health awareness, skills, relief and guidance - delivered with respect for the people we serve.</p><p>In Hindi, <em>prerit</em> (प्रेरित) means <em>inspired</em>. Our hope is that every activity inspires the next one: a student who is helped today becomes a volunteer tomorrow. Our motto says it simply - <strong>मिलकर बनाये बेहतर कल</strong>, together let us build a better tomorrow.</p>', NULL, NULL, '{"width":"narrow"}', 'tint', 'visible', 2),
(@p, 'features', 'what-we-do', 'What we do', 'Our areas of work', 'Each programme combines awareness, practical help and follow-up. Explore a programme to see what it involves and how you can take part.', NULL, NULL, '[{"icon":"book","title":"Education & Literacy","text":"Literacy drives, community learning circles, study material and support for schools.","link":"/programs/education-literacy/","link_label":"Explore"},{"icon":"heart","title":"Healthcare","text":"Health camps and screenings, awareness on prevention and help to reach referral services.","link":"/programs/healthcare/","link_label":"Explore"},{"icon":"users","title":"Women Empowerment","text":"Skill development for women, Self-Help Group formation and support, rights and financial literacy.","link":"/programs/women-empowerment/","link_label":"Explore"},{"icon":"zap","title":"Youth Empowerment","text":"Career guidance and mentoring, leadership, life and digital skills, and community service.","link":"/programs/youth-empowerment/","link_label":"Explore"},{"icon":"home","title":"Rural Development","text":"Community awareness drives, livelihood activities and information on government welfare schemes.","link":"/programs/rural-development/","link_label":"Explore"},{"icon":"leaf","title":"Environment Protection","text":"Tree plantation, waste management and sustainable living in schools and neighbourhoods.","link":"/programs/environment-protection/","link_label":"Explore"},{"icon":"tool","title":"Skill Development","text":"Vocational and digital training, career guidance, placement support and entrepreneurship.","link":"/skill-development/","link_label":"Explore"},{"icon":"package","title":"Food Distribution","text":"Food and essential supplies for families facing hardship, distributed with dignity by volunteers.","link":"/programs/food-distribution/","link_label":"Explore"},{"icon":"life-buoy","title":"Disaster Relief","text":"Relief, hygiene and health support coordinated with volunteers during floods and emergencies.","link":"/programs/disaster-relief/","link_label":"Explore"}]', '{"cols":3}', 'white', 'visible', 3),
(@p, 'steps', 'how-we-work', 'How we work', 'From need to action', 'A simple, transparent process guides every activity we run.', NULL, NULL, '[{"icon":"search","title":"Listen and understand","text":"We speak with community members, local volunteers and partners to understand real needs before planning anything."},{"icon":"target","title":"Plan with the community","text":"Activities are designed together with the people they are meant for, using the resources and skills available locally."},{"icon":"users","title":"Act with volunteers","text":"Volunteers, volunteer teachers and staff carry out the work, following our volunteer and child protection policies."},{"icon":"file-text","title":"Record and report","text":"We keep records of what was done and publish reports and campaign utilisation details once they are verified."}]', '{"align":"center"}', 'tint', 'visible', 4),
(@p, 'highlights', 'values', 'What guides us', 'Our values', 'The principles behind every decision we take. Read more on our Mission & Vision page.', NULL, NULL, '[{"icon":"heart","title":"Dignity","text":"Every person is treated with respect and their privacy is protected."},{"icon":"shield","title":"Transparency","text":"We publish verified information about donations and spending."},{"icon":"users","title":"Inclusion","text":"Our work is open to people of every background, gender and faith."},{"icon":"check-circle","title":"Accountability","text":"We answer to communities, donors and the law, and welcome questions."}]', NULL, 'white', 'visible', 5),
(@p, 'link_cards', 'governance', 'Governance & transparency', 'Accountable for every rupee and every action', 'Donors and communities have a right to know how the foundation is run. These pages - and our impact and utilisation reports - are updated as verified documents become available.', NULL, NULL, '[{"icon":"file-text","title":"NGO Details","text":"Registration and organisation details","url":"/ngo-details/"},{"icon":"users","title":"Management Body","text":"The people responsible for governance","url":"/management-body/"},{"icon":"shield","title":"Financial Transparency","text":"Verified donation totals and accountability","url":"/financial-transparency/"},{"icon":"award","title":"Certificates","text":"Registration and recognition certificates","url":"/certifications/"}]', NULL, 'cream', 'visible', 6),
(@p, 'callout', NULL, NULL, 'Safe, respectful and consent-based', 'We follow a child protection policy, volunteers agree to a code of conduct, and we publish photos or stories of the people we support only with their documented consent.', NULL, NULL, NULL, '{"tone":"blue","icon":"shield","width":"narrow","size":"sm"}', 'white', 'visible', 7),
(@p, 'cta', NULL, NULL, 'Join us in building a better tomorrow', 'Volunteer your time, teach, intern with us or support a campaign - every contribution helps a community move forward.', NULL, NULL, NULL, '{"hindi_tagline":1,"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"donate","icon":"users","size":"lg"},{"label":"Donate Now","url":"/donate/","style":"light","icon":"heart","size":"lg"}]}', 'white', 'visible', 8);

-- our-mission-vision
SET @p := (SELECT id FROM pages WHERE slug = 'our-mission-vision');
UPDATE pages SET meta_description = 'Mission, vision, values and commitments of Prerit Welfare Foundation - building a fair and inclusive society where everyone lives with dignity.' WHERE id = @p;
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'mission', 'Our mission', 'Empowering underprivileged communities', 'Our mission is to empower underprivileged communities through education, healthcare, women empowerment, environmental care and skill development.', '<p>We work alongside communities to open doors that poverty, lack of information or circumstance have closed - so that children can learn, families can stay healthy, women and young people can earn with dignity, and neighbourhoods can care for their environment.</p>', 'assets/images/hero/community.svg', '["Make learning accessible to every child","Bring health awareness and care closer to home","Help women and young people build skills and livelihoods","Encourage communities to protect their environment","Stand with families during emergencies"]', '{"image_alt":"Illustration of a community coming together"}', 'white', 'visible', 1),
(@p, 'split', 'vision', 'Our vision', 'A fair and inclusive society', 'We strive to build a fair and inclusive society where everyone can live with dignity and equal opportunity.', '<p>We imagine communities where a person''s place of birth, gender or income does not decide their future; where help reaches the people who need it; and where those who have been helped go on to help others.</p>', 'assets/images/hero/volunteer.svg', NULL, '{"reverse":1,"image_alt":"Illustration of volunteers lending a helping hand"}', 'tint', 'visible', 2),
(@p, 'quote', NULL, NULL, 'मिलकर बनाये बेहतर कल', NULL, NULL, NULL, NULL, '{"caption":"Together, let us build a better tomorrow - the motto of Prerit Welfare Foundation"}', 'cream', 'visible', 3),
(@p, 'features', 'values', 'What guides us', 'Our values', 'These values shape how we plan, how we work with people and how we use every contribution.', NULL, NULL, '[{"icon":"heart","title":"Dignity","text":"Every person we work with is treated with respect. Help is offered without judgement and personal information is kept private."},{"icon":"shield","title":"Transparency","text":"We publish verified information about donations, spending and our registration - never estimates or assumptions."},{"icon":"users","title":"Inclusion","text":"Our activities are open to people of every gender, caste, religion, ability and background."},{"icon":"check-circle","title":"Accountability","text":"We answer to the communities we serve, to our donors and to the law, and we welcome questions and grievances."},{"icon":"home","title":"Community first","text":"Local people shape the work. We support and strengthen communities rather than speak for them."},{"icon":"award","title":"Spirit of service","text":"We value every hour that volunteers give, and help them learn and grow while they serve."}]', '{"cols":3,"style":"soft"}', 'white', 'visible', 4),
(@p, 'steps', 'approach', 'Our approach', 'How we turn intentions into action', NULL, NULL, NULL, '[{"icon":"megaphone","title":"Awareness","text":"We help people know their rights, entitlements and options - through drives, camps and conversations."},{"icon":"link","title":"Access","text":"We connect people to services: schools, health facilities, training and government welfare schemes."},{"icon":"tool","title":"Ability","text":"We build skills and confidence through training, mentoring and community learning circles."},{"icon":"refresh","title":"Continuity","text":"We follow up, so that support leads to lasting change rather than one-time help."}]', '{"align":"center"}', 'tint', 'visible', 5),
(@p, 'checklist', 'commitments', 'Our commitments', 'What you can expect from us', 'Commitments we make to the people we serve, our volunteers and our supporters.', NULL, NULL, '["Online donations are counted and receipted only after the payment gateway confirms them.","We record campaign spending and publish utilisation reports.","Registration details and certificates are published exactly as issued.","Photos and stories of the people we support are shared only with their consent.","Children are protected by our child protection policy and volunteers follow a code of conduct.","Personal information is handled as described in our privacy policy.","Every grievance is acknowledged and answered through our grievance redressal process.","We never ask for money to process a request for help."]', '{"cols":2}', 'white', 'visible', 6),
(@p, 'link_cards', NULL, 'Learn more', 'Get to know us better', NULL, NULL, NULL, '[{"icon":"info","title":"About Us","text":"Who we are and what we do","url":"/about/"},{"icon":"grid","title":"Our Programmes","text":"Education, health, livelihoods and more","url":"/programs/"},{"icon":"users","title":"Management Body","text":"Governance of the foundation","url":"/management-body/"},{"icon":"shield","title":"Financial Transparency","text":"Verified totals and reports","url":"/financial-transparency/"}]', NULL, 'cream', 'visible', 7),
(@p, 'cta', NULL, NULL, 'Be part of the change', 'Our mission needs many hands. Volunteer, teach, intern or donate - and help us build a fairer tomorrow.', NULL, NULL, NULL, '{"hindi_tagline":1,"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"donate","icon":"users","size":"lg"},{"label":"Explore Programmes","url":"/programs/","style":"light","icon":"grid","size":"lg"}]}', 'white', 'visible', 8);

-- achievements
SET @p := (SELECT id FROM pages WHERE slug = 'achievements');
UPDATE pages SET meta_description = 'Verified milestones, completed campaigns, events and certificates of Prerit Welfare Foundation, published as our work is documented.' WHERE id = @p;
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'callout', NULL, NULL, 'How we publish achievements', 'This page grows as our work is documented. We add milestones, completed campaigns, events and recognitions only after verifying them against our records and documents - nothing here is estimated.', NULL, NULL, NULL, '{"tone":"green","icon":"check-circle","width":"narrow","size":"sm"}', 'white', 'visible', 1),
(@p, 'timeline', 'milestones', 'Milestones', 'Our journey', 'Key moments in the foundation''s work, added as they are verified.', NULL, NULL, NULL, '{"empty_text":"Milestones will be published here once they have been verified from our records.","width":"narrow"}', 'white', 'visible', 2),
(@p, 'campaigns_grid', 'completed-campaigns', 'Campaigns', 'Completed campaigns', 'Fundraising campaigns that have finished. Amounts shown are verified donations only.', NULL, NULL, NULL, '{"status":"completed","limit":6}', 'tint', 'visible', 3),
(@p, 'events_list', 'past-events', 'Events', 'Past events and activities', 'Camps, drives and awareness programmes we have organised.', NULL, NULL, NULL, '{"when":"past","limit":6}', 'white', 'visible', 4),
(@p, 'documents_list', 'recognition', 'Recognition', 'Registrations and certificates', 'Certificates are published exactly as issued by the authorities.', NULL, NULL, NULL, '{"category":"certification"}', 'cream', 'visible', 5),
(@p, 'link_cards', NULL, 'See the impact', 'Explore our work in detail', NULL, NULL, NULL, '[{"icon":"bar-chart","title":"Impact Reports","text":"Annual and programme reports","url":"/impact-reports/"},{"icon":"star","title":"Success Stories","text":"Real stories, shared with consent","url":"/success-stories/"},{"icon":"image","title":"Photo Gallery","text":"Moments from our activities","url":"/gallery/"},{"icon":"pie-chart","title":"Utilisation Reports","text":"How campaign funds were used","url":"/utilization-reports/"}]', NULL, 'white', 'visible', 6),
(@p, 'cta', NULL, NULL, 'Help us write the next chapter', 'Every volunteer hour and every verified donation becomes part of the foundation''s story.', NULL, NULL, NULL, '{"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"donate","icon":"users","size":"lg"},{"label":"Support a Campaign","url":"/campaigns/","style":"light","icon":"heart","size":"lg"}]}', 'white', 'visible', 7);

-- awareness-campaign
SET @p := (SELECT id FROM pages WHERE slug = 'awareness-campaign');
UPDATE pages SET meta_description = 'Awareness campaigns by Prerit Welfare Foundation on health, hygiene, education, environment, digital literacy, welfare schemes and cyber safety.' WHERE id = @p;
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'about-awareness', 'Awareness campaigns', 'Knowledge that changes lives', 'Many problems in our communities can be prevented or solved when people have the right information at the right time.', '<p>Our awareness campaigns take simple, practical information to people where they are - in neighbourhoods, schools, colleges, community centres and online. Volunteers use conversations, demonstrations, posters, street plays and small group sessions so that the message is understood and remembered.</p>', 'assets/images/hero/events.svg', '["Led by briefed volunteers, often with local partners","In simple Hindi, English or the local language","Focused on information people can act on","Linked to services and schemes people can use"]', '{"image_alt":"Illustration of an awareness programme in a community","buttons":[{"label":"Volunteer for campaigns","url":"/become-a-volunteer/","style":"primary","icon":"users"},{"label":"Awareness calendar","url":"/awareness-calendar/","style":"outline","icon":"calendar"}]}', 'white', 'visible', 1),
(@p, 'features', 'themes', 'Themes', 'What we raise awareness about', 'Topics are chosen with communities, schools and partners, and around national and international awareness days.', NULL, NULL, '[{"icon":"heart","title":"Health","text":"Prevention of common illnesses, nutrition, regular check-ups and when to seek medical care."},{"icon":"sun","title":"Hygiene & sanitation","text":"Handwashing, safe drinking water, clean surroundings and menstrual hygiene."},{"icon":"book","title":"Education","text":"Why every child should be in school - enrolment, regular attendance and support to continue studies."},{"icon":"graduation","title":"Girl child education","text":"Encouraging families to educate their daughters and helping girls stay in school."},{"icon":"leaf","title":"Environment","text":"Tree plantation, waste segregation, saving water and reducing single-use plastic."},{"icon":"smartphone","title":"Digital & financial literacy","text":"Using phones and digital payments safely, opening bank accounts and saving regularly."},{"icon":"list","title":"Welfare schemes","text":"Information on government schemes, who is eligible and the documents needed to apply."},{"icon":"shield","title":"Cyber safety","text":"Spotting online fraud, never sharing OTPs or passwords, and reporting on the national helpline 1930."}]', '{"cols":4}', 'tint', 'visible', 2),
(@p, 'steps', 'how-it-works', 'How campaigns run', 'From planning to follow-up', NULL, NULL, NULL, '[{"icon":"calendar","title":"Choose the theme and place","text":"We pick a topic based on local needs or an awareness day, and agree a venue with community members or a school."},{"icon":"users","title":"Brief the volunteers","text":"Volunteers are briefed on the topic, the key messages and how to answer common questions."},{"icon":"megaphone","title":"Reach people","text":"Sessions, door-to-door conversations, demonstrations or street plays share the message in simple language."},{"icon":"check-circle","title":"Connect and follow up","text":"People are guided to the services and schemes they need, and their questions shape the next campaign."}]', '{"align":"center"}', 'white', 'visible', 3),
(@p, 'awareness_days', 'awareness-days', 'Awareness calendar', 'Upcoming awareness days', 'Important national and international days around which we plan activities.', NULL, NULL, NULL, '{"limit":6}', 'cream', 'visible', 4),
(@p, 'events_list', 'events', 'Events', 'Upcoming awareness activities', 'Join an upcoming session, drive or camp near you.', NULL, NULL, NULL, '{"when":"upcoming","limit":6}', 'white', 'visible', 5),
(@p, 'features', 'get-involved', 'Get involved', 'Bring an awareness campaign to your community', NULL, NULL, NULL, '[{"icon":"home","title":"Host a session","text":"Schools, colleges, resident welfare associations and community groups can invite us. Tell us the topic, place, preferred dates and expected audience.","link":"/contact/","link_label":"Contact us"},{"icon":"users","title":"Volunteer for campaigns","text":"Help prepare material, speak to people, translate or organise. No special qualification is needed - every volunteer is briefed.","link":"/become-a-volunteer/","link_label":"Apply as a volunteer"},{"icon":"award","title":"Intern with us","text":"Students can take part in campaigns as part of an internship in communication, social work or public health.","link":"/internships/","link_label":"Internships"}]', '{"cols":3}', 'tint', 'visible', 6),
(@p, 'faqs', NULL, 'Questions', 'Awareness campaigns: common questions', NULL, NULL, NULL, '[{"q":"How can our school or society invite you for a session?","a":"Write to us through the contact page with the topic you are interested in, the place, your preferred dates and the expected number of participants. Our team will get back to you to plan it together."},{"q":"Can I suggest a topic?","a":"Yes. Tell us about an issue that affects your community and we will consider it when planning campaigns."},{"q":"Do volunteers need special training?","a":"No special qualification is needed. Volunteers are briefed on the topic and the key messages before each campaign."},{"q":"Where can I see upcoming campaigns?","a":"Upcoming activities are listed on the Events page and important dates are in the Awareness Calendar."}]', '{"width":"narrow","open_first":1}', 'white', 'visible', 7),
(@p, 'cta', NULL, NULL, 'Spread awareness with us', 'One conversation can change a family''s health, savings or future. Help us start more of them.', NULL, NULL, NULL, '{"hindi_tagline":1,"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"donate","icon":"users","size":"lg"},{"label":"Invite us","url":"/contact/","style":"light","icon":"mail","size":"lg"}]}', 'white', 'visible', 8);

-- beneficiary-support
SET @p := (SELECT id FROM pages WHERE slug = 'beneficiary-support');
UPDATE pages SET meta_description = 'How individuals and families can request support from Prerit Welfare Foundation: who can apply, types of help, process and documents.' WHERE id = @p;
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'overview', 'Support for individuals and families', 'Asking for help is the first step', 'If you or someone you know is facing hardship, you can ask Prerit Welfare Foundation for support.', '<p>We review every request carefully and confidentially. Depending on the need, our resources and the activities running in your area, we may be able to help directly, connect you with another organisation or service, or guide you to a government welfare scheme.</p>', 'assets/images/hero/help.svg', '["Applying is free - the foundation never asks for money to process a request","Your details are kept confidential","Track your request with your reference number"]', '{"image_alt":"Illustration of a helping hand reaching out to a family","buttons":[{"label":"Request help","url":"/request-help/","style":"donate","icon":"life-buoy"},{"label":"Check application status","url":"/application-status/","style":"outline","icon":"search"}]}', 'white', 'visible', 1),
(@p, 'checklist', 'who-can-ask', 'Who can ask', 'Who can request support', 'Anyone facing genuine hardship can apply, for themselves or on behalf of someone else with their permission. Support may be requested by or for:', NULL, NULL, '["Children and students at risk of leaving school","Women, including widows and single mothers, looking for a livelihood","Elderly people and persons with disabilities without support","Families facing a medical emergency who need guidance","Households affected by floods, fire or other disasters","Families who need help applying for welfare schemes"]', '{"cols":2}', 'tint', 'visible', 2),
(@p, 'features', 'types', 'Types of support', 'How we may be able to help', 'What we can offer depends on the programmes and campaigns running at the time.', NULL, NULL, '[{"icon":"book","title":"Education","text":"Study material, guidance on admissions and scholarships, and learning support.","link":"/scholarship/","link_label":"Scholarship application"},{"icon":"heart","title":"Health","text":"Health awareness, information about camps and help to reach referral services.","link":"/programs/healthcare/","link_label":"Healthcare programme"},{"icon":"tool","title":"Livelihood & skills","text":"Skill training, career guidance and support for women''s Self-Help Groups.","link":"/skill-development/","link_label":"Skill development"},{"icon":"package","title":"Food & essentials","text":"Food and essential supplies during distribution drives, when available.","link":"/programs/food-distribution/","link_label":"Food distribution"},{"icon":"life-buoy","title":"Disaster relief","text":"Relief support for families affected by floods and emergencies, through relief campaigns.","link":"/programs/disaster-relief/","link_label":"Disaster relief"},{"icon":"list","title":"Welfare schemes","text":"Information about government schemes and help to prepare the application.","link":"/schemes/","link_label":"Welfare schemes"}]', '{"cols":3}', 'white', 'visible', 3),
(@p, 'steps', 'process', 'The process', 'What happens after you apply', NULL, NULL, NULL, '[{"icon":"file-text","title":"Submit a request","text":"Fill in the Request Help form with your details and describe your situation. You receive a reference number."},{"icon":"search","title":"Review","text":"Our team reviews the request and may call you to understand your needs better or ask for documents."},{"icon":"check-circle","title":"Decision","text":"We let you know whether and how we can help, or which other service or scheme may support you."},{"icon":"heart","title":"Support and follow-up","text":"If support is approved, we explain what will be provided and when, and follow up afterwards."}]', '{"align":"center"}', 'tint', 'visible', 4),
(@p, 'checklist', 'documents', 'Documents', 'Documents that are usually helpful', 'Keep these ready if you have them. We will tell you if a particular document is needed for your request.', NULL, NULL, '["Aadhaar card or another identity proof","Address proof","Income certificate or ration card, if available","Medical prescriptions or reports, for health-related requests","Fee receipt or admission letter, for education requests","Bank account details - only when we ask for them for a scheme application"]', '{"cols":2}', 'white', 'visible', 5),
(@p, 'callout', NULL, NULL, 'What we cannot do', 'We cannot promise support for every request, give cash loans, or guarantee the outcome of a government scheme application. Our help depends on available resources and verified needs. If anyone asks you for money to approve a request, do not pay - the foundation never charges for help. Please report it to us.', NULL, NULL, NULL, '{"tone":"gold","icon":"alert-circle","width":"narrow","size":"sm"}', 'white', 'visible', 6),
(@p, 'highlights', 'privacy', 'Our commitment', 'Your privacy and dignity', NULL, NULL, NULL, '[{"icon":"lock","title":"Confidential","text":"Your details are seen only by the team handling your request."},{"icon":"shield","title":"No publicity without consent","text":"We never share your photo or story without your permission."},{"icon":"heart","title":"Respect","text":"You will be treated with courtesy, whatever the outcome."},{"icon":"file-text","title":"Your data, your choice","text":"You can ask to see, correct or delete your personal information."}]', NULL, 'cream', 'visible', 7),
(@p, 'schemes_list', 'schemes', 'Welfare schemes', 'Government schemes you may be eligible for', 'Details are checked against official sources. Our volunteers can help you prepare the application.', NULL, NULL, NULL, '{"limit":6}', 'white', 'visible', 8),
(@p, 'faqs', NULL, 'Questions', 'Beneficiary support: common questions', NULL, NULL, NULL, '[{"q":"Do I need to pay anything to request help?","a":"No. Requesting help is free. If anyone asks you for money in the name of Prerit Welfare Foundation, do not pay and report it to us through the Report Fraud page."},{"q":"How long does the review take?","a":"It depends on the type of request and the information we need. You can check the current status at any time on the Application Status page using your reference number."},{"q":"Can I apply on behalf of someone else?","a":"Yes, with their permission. Give their details along with your own contact details, and explain your relationship to them."},{"q":"What if my request cannot be supported?","a":"We will let you know, and wherever possible we will suggest another organisation, service or government scheme that may help."},{"q":"Is my information shared with anyone?","a":"Your information is used only to assess and handle your request, as described in our privacy policy. With your consent, we may share necessary details with a partner organisation or government office that can help you."}]', '{"width":"narrow","open_first":1}', 'tint', 'visible', 9),
(@p, 'cta', NULL, NULL, 'Need support? We are here to listen.', 'Tell us about your situation - the form takes only a few minutes.', NULL, NULL, NULL, '{"buttons":[{"label":"Request help","url":"/request-help/","style":"donate","icon":"life-buoy","size":"lg"},{"label":"Check application status","url":"/application-status/","style":"light","icon":"search","size":"lg"}]}', 'white', 'visible', 10);

-- ---------------------------------------------------------------------
-- Module M2
-- ---------------------------------------------------------------------
-- =====================================================================
-- M2 seed: programmes, programme pages & sections, Programs overview and Welfare Schemes page sections.
-- Generated by scratchpad/v2/m2/gen-seed.php. Re-runnable: sections of these pages are deleted and
-- re-inserted; pages and programmes are upserted by slug (ids stay stable for linked campaigns/events).
-- No welfare schemes are seeded: scheme details must be verified by staff against official sources.
-- Content only describes the foundation's work - no figures, beneficiaries, partners or testimonials.
-- =====================================================================
SET NAMES utf8mb4;

-- 1. Remove this module's previous sections (pages and programmes are upserted below)
DELETE FROM page_sections WHERE page_id IN (SELECT id FROM (SELECT id FROM pages WHERE slug IN ('programs', 'schemes', 'programs/education-literacy', 'programs/healthcare', 'programs/women-empowerment', 'programs/youth-empowerment', 'programs/rural-development', 'programs/environment-protection', 'skill-development', 'programs/digital-learning', 'programs/scholarship-educational-support', 'programs/food-distribution', 'programs/disaster-relief')) AS m2p);

SET @programs_page := (SELECT id FROM pages WHERE slug = 'programs');

-- 2. Programme detail pages (/programs/<slug>/); Skill Development uses the existing /skill-development/ page
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/education-literacy', 'program', 'Education & Literacy', 'Our programmes', 'Building strong futures through learning and school support', 'assets/images/programs/education.svg', 'Education & Literacy at Prerit Welfare Foundation: literacy drives, community learning circles, study material and school support for children and adults.', '{"program":"education-literacy"}', 'published', 0, 1, 0, 41, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/healthcare', 'program', 'Healthcare', 'Our programmes', 'Accessible care and awareness for every family', 'assets/images/programs/healthcare.svg', 'Healthcare at Prerit Welfare Foundation: health camps, screenings, awareness on prevention, hygiene and nutrition, and referral support for families.', '{"program":"healthcare"}', 'published', 0, 1, 0, 42, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/women-empowerment', 'program', 'Women Empowerment', 'Our programmes', 'Strengthening livelihoods and leadership among women', 'assets/images/programs/women-empowerment.svg', 'Women Empowerment programme of Prerit Welfare Foundation: skill training, Self-Help Group support, rights and financial literacy awareness for women.', '{"program":"women-empowerment"}', 'published', 0, 1, 0, 43, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/youth-empowerment', 'program', 'Youth Empowerment', 'Our programmes', 'Helping young people discover their potential', 'assets/images/programs/youth-empowerment.svg', 'Youth Empowerment at Prerit Welfare Foundation: career guidance, mentoring, leadership, life and digital skills, and community service for youth.', '{"program":"youth-empowerment"}', 'published', 0, 1, 0, 44, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/rural-development', 'program', 'Rural Development', 'Our programmes', 'Strengthening villages and rural communities', 'assets/images/programs/rural-development.svg', 'Rural Development at Prerit Welfare Foundation: community awareness drives, livelihood activities and awareness of welfare schemes in villages.', '{"program":"rural-development"}', 'published', 0, 1, 0, 45, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/environment-protection', 'program', 'Environment Protection', 'Our programmes', 'Promoting a cleaner, greener and sustainable lifestyle', 'assets/images/programs/environment.svg', 'Environment Protection programme of Prerit Welfare Foundation: tree plantation drives, waste management, cleanliness drives and sustainable living awareness.', '{"program":"environment-protection"}', 'published', 0, 1, 0, 46, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/digital-learning', 'program', 'Digital Learning', 'Our programmes', 'Digital skills and online safety for everyone', 'assets/images/programs/digital-learning.svg', 'Digital Learning programme of Prerit Welfare Foundation: basic computer and smartphone skills, online safety and safe digital payments for every age group.', '{"program":"digital-learning"}', 'published', 0, 1, 0, 47, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/scholarship-educational-support', 'program', 'Scholarship & Educational Support', 'Our programmes', 'Helping deserving students continue their education', 'assets/images/programs/scholarship.svg', 'Scholarship & Educational Support: apply online for scholarship support, study material and guidance for students from economically weaker families.', '{"program":"scholarship-educational-support"}', 'published', 0, 1, 0, 48, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/food-distribution', 'program', 'Food Distribution', 'Our programmes', 'Food and essentials for families in need, with dignity', 'assets/images/programs/food-distribution.svg', 'Food Distribution programme of Prerit Welfare Foundation: ration kits, cooked food in emergencies and essential supplies for families facing hardship.', '{"program":"food-distribution"}', 'published', 0, 1, 0, 49, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO pages (parent_id, slug, template, title, banner_eyebrow, banner_subtitle, banner_image, meta_description, settings, status, is_system, show_in_sitemap, noindex, sort_order, published_at) VALUES (@programs_page, 'programs/disaster-relief', 'program', 'Disaster Relief', 'Our programmes', 'Standing with communities in times of crisis', 'assets/images/programs/disaster-relief.svg', 'Disaster Relief by Prerit Welfare Foundation: food, essential supplies, hygiene and health support for communities affected by floods and other disasters.', '{"program":"disaster-relief"}', 'published', 0, 1, 0, 50, NOW()) ON DUPLICATE KEY UPDATE parent_id = VALUES(parent_id), template = VALUES(template), title = VALUES(title), banner_eyebrow = VALUES(banner_eyebrow), banner_subtitle = VALUES(banner_subtitle), banner_image = VALUES(banner_image), meta_description = VALUES(meta_description), settings = VALUES(settings), status = VALUES(status), sort_order = VALUES(sort_order);
UPDATE pages SET parent_id = @programs_page WHERE slug = 'skill-development';

-- 3. Programmes (upsert by slug) linked to their pages
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('education-literacy', 'Education & Literacy', 'Literacy drives, community learning circles, study material and school support, so that every child – and every adult who missed out – can keep learning.', 'book', 'blue', NULL, '["Literacy drives & community learning circles","Study material distribution","School collaboration & remedial support","Volunteer teaching, in person and online"]', (SELECT id FROM pages WHERE slug = 'programs/education-literacy'), 1, 'published', 10) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('healthcare', 'Healthcare', 'Health camps, screenings, awareness on prevention and help in reaching the right care, so that families in underserved communities can stay healthy.', 'heart', 'green', NULL, '["Health camps & screenings","Awareness & prevention campaigns","Referral support to hospitals and clinics","Hygiene and nutrition awareness"]', (SELECT id FROM pages WHERE slug = 'programs/healthcare'), 1, 'published', 20) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('women-empowerment', 'Women Empowerment', 'Skill training, Self-Help Groups, and awareness of rights and financial literacy, helping women build livelihoods, confidence and leadership.', 'users', 'orange', NULL, '["Skill development for women","Self-Help Group (SHG) formation & support","Rights & financial literacy awareness","Leadership and confidence building"]', (SELECT id FROM pages WHERE slug = 'programs/women-empowerment'), 1, 'published', 30) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('youth-empowerment', 'Youth Empowerment', 'Career guidance, mentoring, leadership, life and digital skills, and community service opportunities that help young people discover their potential.', 'zap', 'gold', NULL, '["Career guidance & mentoring","Leadership, life & digital skills","Community service opportunities","Awareness on health, safety and civic responsibility"]', (SELECT id FROM pages WHERE slug = 'programs/youth-empowerment'), 1, 'published', 40) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('rural-development', 'Rural Development', 'Community awareness drives, livelihood activities and awareness of government welfare schemes that help villages and rural communities grow stronger.', 'home', 'teal', NULL, '["Community awareness drives","Livelihood & skill-building activities","Awareness of government welfare schemes","Health, hygiene and sanitation awareness"]', (SELECT id FROM pages WHERE slug = 'programs/rural-development'), 0, 'published', 50) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('environment-protection', 'Environment Protection', 'Tree plantation, waste management and sustainable living – promoting a cleaner, greener and healthier environment for the communities we serve.', 'leaf', 'green', NULL, '["Tree plantation drives","Waste management initiatives","Sustainable living practices","Environmental awareness in schools and communities"]', (SELECT id FROM pages WHERE slug = 'programs/environment-protection'), 1, 'published', 60) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('skill-development', 'Skill Development & Training', 'Vocational and digital training, career guidance and placement support, and guidance on entrepreneurship that help people find work or start an enterprise.', 'tool', 'blue', NULL, '["Vocational & digital training","Career guidance & placement support","Entrepreneurship & livelihood opportunities","Soft skills and interview preparation"]', (SELECT id FROM pages WHERE slug = 'skill-development'), 1, 'published', 70) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('digital-learning', 'Digital Learning', 'Basic computer and smartphone skills, online safety and safe digital payments, helping children, women, adults and senior citizens take part confidently in a digital world.', 'monitor', 'teal', NULL, '["Basic computer & smartphone skills","Online safety & cyber awareness","Safe digital payments (UPI & banking)","Using online services and forms"]', (SELECT id FROM pages WHERE slug = 'programs/digital-learning'), 0, 'published', 80) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('scholarship-educational-support', 'Scholarship & Educational Support', 'Scholarship support, study material and guidance for deserving students from economically weaker families, so that money does not end their education.', 'award', 'gold', NULL, '["Scholarship applications for deserving students","Study material & fee support based on need","Guidance on courses & government scholarships","Mentoring by volunteers"]', (SELECT id FROM pages WHERE slug = 'programs/scholarship-educational-support'), 0, 'published', 90) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('food-distribution', 'Food Distribution', 'Food and essential supplies for families facing hunger or hardship, distributed with dignity by our volunteers – relief campaigns are opened only when there is a real need.', 'package', 'green', NULL, '["Ration kits & essential supplies","Cooked food during emergencies","Distribution based on verified need","Nutrition awareness"]', (SELECT id FROM pages WHERE slug = 'programs/food-distribution'), 1, 'published', 100) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);
INSERT INTO programs (slug, title, summary, icon, tone, cover_image, key_points, page_id, show_on_home, status, sort_order) VALUES ('disaster-relief', 'Disaster Relief', 'Food, essential supplies, hygiene and health support for communities hit by floods and other disasters, coordinated with volunteers – relief campaigns are opened only when there is a real need.', 'life-buoy', 'orange', NULL, '["Food & essential supplies","Hygiene & health support","Relief coordination with volunteers","Support for recovery after the emergency"]', (SELECT id FROM pages WHERE slug = 'programs/disaster-relief'), 1, 'published', 110) ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), icon = VALUES(icon), tone = VALUES(tone), key_points = VALUES(key_points), page_id = VALUES(page_id), show_on_home = VALUES(show_on_home), status = VALUES(status), sort_order = VALUES(sort_order);

-- 4. Programme page sections
SET @pid := (SELECT id FROM pages WHERE slug = 'programs/education-literacy');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'Every child deserves the chance to learn', 'In many underserved families, children fall behind in school because of a lack of study material, guidance at home or a quiet place to learn. Adults who missed out on schooling often find everyday tasks – reading a notice, filling a form or managing money – difficult.', '<p>Our Education &amp; Literacy programme brings learning closer to these families. Volunteers and educators run literacy drives and small community learning circles, help children keep up with school, and make sure that the cost of books and stationery does not keep anyone away from learning.</p><p>The programme is open to learners of every caste, religion and gender, and all our learning activities are free for learners.</p>', 'assets/images/hero/teacher.svg', '["Learning gaps that grow if they are not addressed early","Families who cannot afford books and stationery","First-generation learners with no help with homework","Adults, especially women, who want to learn to read and write"]', '{"image_alt":"Illustration of a volunteer teacher","buttons":[{"label":"Become a volunteer teacher","url":"/volunteer-teacher/","style":"primary","icon":"book"},{"label":"Support education","url":"/donate/","style":"outline","icon":"heart"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Education & Literacy', 'What we do', 'Practical, community-based activities that help learners of every age build confidence with reading, writing and numbers.', NULL, NULL, '[{"icon":"book","title":"Literacy drives","text":"Community-based drives that help children and adults build basic reading, writing and number skills, with sessions planned around the learners’ daily routine."},{"icon":"users","title":"Community learning circles","text":"Small groups that meet regularly in the neighbourhood to revise school work, read together and build a reading habit in a safe, friendly space."},{"icon":"package","title":"Study material distribution","text":"Notebooks, books, school bags and stationery for children who need them, distributed on the basis of verified need."},{"icon":"graduation","title":"School collaboration & support","text":"Working alongside schools and teachers to support students who need extra help, encourage regular attendance and reduce drop-outs."},{"icon":"star","title":"Volunteer teaching","text":"Graduates, teachers and students volunteer their time – in person or online – to teach school subjects, spoken English and computer basics.","link":"/volunteer-teacher/","link_label":"Become a volunteer teacher"},{"icon":"award","title":"Scholarship support","text":"Deserving students from economically weaker families can apply for support to continue their studies.","link":"/scholarship/","link_label":"Apply for a scholarship"}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"search","title":"Understand the need","text":"We listen to families, schools and community members to understand where learners are struggling."},{"icon":"users","title":"Form learning groups","text":"Learners are grouped by level so that each session matches what they need to learn next."},{"icon":"book","title":"Teach & support","text":"Volunteers and educators run regular sessions and provide study material where it is needed."},{"icon":"check-circle","title":"Review progress","text":"We follow each learner’s progress with their family and adjust the support so that no one is left behind."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Learners who need a helping hand', 'Our education activities are open to learners from vulnerable and underserved communities, regardless of caste, religion or gender.', NULL, NULL, '["School-going children who need extra learning support","Children at risk of dropping out of school","First-generation learners","Adults, especially women, seeking basic literacy","Students from economically weaker families","Parents who want to support their children’s learning at home"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Fund study material, learning circles and scholarships. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Help at learning circles, literacy drives and study material distributions.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"graduation","title":"Teach","text":"Share a few hours a week as a volunteer teacher, in person or online.","link":"/volunteer-teacher/","link_label":"Apply to teach"},{"icon":"megaphone","title":"Spread the word","text":"Tell families who need support about our learning circles and the scholarship programme.","link":"/scholarship/","link_label":"Scholarship details"},{"icon":"briefcase","title":"Partner with us","text":"Schools, colleges, companies and community groups can write to us about running learning activities together. To give books or stationery, please contact us first.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"education-literacy","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"education","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Who can join the learning circles?","a":"Children and adults from underserved communities who want to improve their reading, writing or school learning can join. Please contact us to find out whether sessions are running near you."},{"q":"Is there any fee for the learning sessions?","a":"No. Our education activities are free for learners. If anyone asks you for money in our name, please report it to us."},{"q":"Can I volunteer to teach online?","a":"Yes. When you apply as a volunteer teacher you can choose in-person, online or both. Every volunteer teacher must follow our Child Protection Policy."},{"q":"How do I apply for a scholarship?","a":"Fill in the scholarship application form on our website. You will receive a reference number to track the status of your application."},{"q":"Can I donate books or stationery instead of money?","a":"Yes. Please contact us before sending anything so that we can tell you what is currently needed."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help a child keep learning', 'Your support provides study material, learning sessions and scholarships for learners who need them most.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer Teacher","url":"/volunteer-teacher/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/healthcare');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'Good health is the foundation of a good life', 'For many families, a visit to a doctor means losing a day’s wages, travelling a long distance or not knowing where to go at all. Small health problems are often ignored until they become serious.', '<p>Our Healthcare programme works to close this gap. We organise health camps and screenings with qualified doctors and health workers, run awareness sessions on prevention, hygiene and nutrition, and help people reach the right hospital, clinic or government health service when they need further care.</p><p>We do not replace doctors or hospitals – we help families connect with them in time.</p>', 'assets/images/hero/help.svg', '["Low awareness of the early signs of illness","Distance and cost of reaching a doctor","Women and elderly people who postpone their own care","Confusion about where to go for further treatment"]', '{"image_alt":"Illustration of support for families","buttons":[{"label":"Request health support","url":"/request-help/","style":"primary","icon":"life-buoy"},{"label":"Volunteer at a health camp","url":"/become-a-volunteer/","style":"outline","icon":"users"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Healthcare', 'What we do', 'Activities planned with local communities and carried out with qualified medical professionals wherever medical advice is involved.', NULL, NULL, '[{"icon":"activity","title":"Health camps","text":"Camps in communities and partner venues where qualified doctors and health workers offer general check-ups and advice."},{"icon":"check-circle","title":"Screenings","text":"Basic screenings such as blood pressure, blood sugar, eyesight and anaemia checks, with advice on the next steps where needed."},{"icon":"megaphone","title":"Awareness & prevention","text":"Sessions on hygiene, nutrition, seasonal illnesses, menstrual health and healthy habits, in simple language."},{"icon":"map-pin","title":"Referral support","text":"Guidance on where to go for further treatment, and help in reaching hospitals, clinics and government health services."},{"icon":"users","title":"Women’s & elderly health","text":"Special attention to the health needs of women, adolescent girls and senior citizens, who often put their own care last."},{"icon":"shield","title":"Health scheme awareness","text":"Information about public health services and health-related welfare schemes, and guidance with the application process.","link":"/schemes/","link_label":"Welfare schemes"}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"search","title":"Identify the need","text":"We speak with community members and local volunteers to understand the most pressing health concerns."},{"icon":"calendar","title":"Plan the camp or session","text":"Each activity is planned with qualified doctors or health workers and announced in the community in advance."},{"icon":"activity","title":"Check-up & advice","text":"People receive check-ups, advice and information in a respectful and private setting."},{"icon":"refresh","title":"Follow up","text":"Where further care is needed, we guide families to the right service and stay in touch until they are connected."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Families who find it hard to reach care', 'Our health activities are open to everyone in the communities we serve, with special care for those most at risk.', NULL, NULL, '["Families in urban settlements and villages with limited access to doctors","Women and adolescent girls","Senior citizens","Daily-wage workers who cannot afford to miss work","Children who need regular check-ups","People who need guidance on further treatment"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Help us organise health camps, screenings and awareness sessions. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Help with registration, guidance and follow-up at health camps and awareness sessions.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"activity","title":"Medical professionals","text":"Doctors, nurses and health workers can volunteer their time for camps, screenings and awareness sessions.","link":"/become-a-volunteer/","link_label":"Volunteer your skills"},{"icon":"megaphone","title":"Spread awareness","text":"Share information about upcoming health camps so that the people who need them can attend.","link":"/events-page/","link_label":"Upcoming events"},{"icon":"briefcase","title":"Partner with us","text":"Hospitals, clinics and organisations can write to us about organising health camps together.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"healthcare","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"health","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Is there a fee for the health camps?","a":"No. Our health camps and awareness sessions are free of charge for the people who attend them."},{"q":"Who carries out the check-ups?","a":"Check-ups and medical advice are given only by qualified doctors and health workers. Our volunteers help with registration, guidance and follow-up."},{"q":"Do you provide treatment or medicines?","a":"We focus on check-ups, awareness and referrals. Where further treatment is needed, we guide families to the right hospital, clinic or government health service."},{"q":"How can I find out about upcoming health camps?","a":"Upcoming camps are listed on our Events page once they are confirmed. You can also contact us to ask about activities near you."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help a family get the care it needs', 'Your support makes health camps, screenings and awareness sessions possible in communities that need them most.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/women-empowerment');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'When women grow, families and communities grow', 'Many women in underserved communities have skills and ambition but lack training, access to savings and credit, or the confidence to speak up at home and in the community.', '<p>Our Women Empowerment programme helps women take the next step. We offer skill training that can lead to an income, help women come together in Self-Help Groups to save and support each other, and run sessions on legal rights, financial literacy and safe digital banking.</p><p>Sessions are held at times and places that suit women, in a safe and respectful environment.</p>', 'assets/images/hero/community.svg', '["Limited access to skill training and income opportunities","Little control over savings and household finances","Low awareness of legal rights and entitlements","Few safe spaces to learn and share"]', '{"image_alt":"Illustration of a community group","buttons":[{"label":"Request support","url":"/request-help/","style":"primary","icon":"life-buoy"},{"label":"Support women","url":"/donate/","style":"outline","icon":"heart"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Women Empowerment', 'What we do', 'Activities that build skills, financial independence and a strong voice.', NULL, NULL, '[{"icon":"tool","title":"Skill training","text":"Training in practical skills such as tailoring, handicrafts, food processing and beauty services, chosen together with the women who take part."},{"icon":"users","title":"Self-Help Groups","text":"Help in forming and running Self-Help Groups, with guidance on regular savings, record keeping and group decision-making."},{"icon":"rupee","title":"Financial literacy","text":"Sessions on budgeting, saving, opening and using bank accounts, and staying safe from financial fraud."},{"icon":"shield","title":"Rights awareness","text":"Information on legal rights, protection against violence and harassment, and where to seek help."},{"icon":"briefcase","title":"Livelihood & enterprise","text":"Guidance for women who want to start or grow a small enterprise, including information on relevant welfare schemes.","link":"/schemes/","link_label":"Welfare schemes"},{"icon":"star","title":"Leadership & confidence","text":"Workshops that help women speak up, take part in community decisions and become role models for others."}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"message","title":"Listen first","text":"We meet women in their communities to understand their interests, time constraints and goals."},{"icon":"users","title":"Build the group","text":"Women come together in small groups for training, savings and peer support."},{"icon":"tool","title":"Train & practise","text":"Hands-on sessions build skills step by step, with plenty of practice."},{"icon":"trending-up","title":"Grow together","text":"We stay in touch to support groups and individual women as they put their skills to use."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Women who want to learn, earn and lead', 'The programme is open to women from vulnerable and underserved communities.', NULL, NULL, '["Homemakers who want to earn an income","Young women looking for skills and work","Women-headed households","Members of existing Self-Help Groups","Women who want to learn about banking and digital payments","Women seeking information on their rights"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Fund training material, workshops and awareness sessions for women. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Help organise sessions, support Self-Help Groups and reach out to women in the community.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"tool","title":"Share your skills","text":"Trainers, bankers, lawyers and entrepreneurs can volunteer to run sessions and mentor women.","link":"/become-a-volunteer/","link_label":"Volunteer your skills"},{"icon":"megaphone","title":"Spread awareness","text":"Tell women in your area about our training sessions, Self-Help Groups and how to request support.","link":"/request-help/","link_label":"How to request support"},{"icon":"briefcase","title":"Partner with us","text":"Organisations and companies can write to us about training or livelihood programmes for women.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"women-empowerment","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"women-empowerment","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Who can join the training sessions?","a":"Women from underserved communities who want to learn a skill, join a Self-Help Group or learn about their rights and finances can join. Contact us to find out about sessions near you."},{"q":"Is there a fee for the training?","a":"No. Our training and awareness sessions are free for participants."},{"q":"What is a Self-Help Group?","a":"A Self-Help Group is a small group of women who save regularly, support each other and take decisions together. We help groups get started and guide them on running the group well."},{"q":"Can men volunteer with this programme?","a":"Yes. Volunteers of any gender can help, for example with training, mentoring and awareness. All volunteers follow our Volunteer Policy."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help a woman build her own future', 'Your support provides training, guidance and safe spaces for women to learn, earn and lead.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/youth-empowerment');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'Young people are the change-makers of tomorrow', 'Many young people from underserved families have talent and energy, but no one to guide them on studies, careers or the skills that employers look for.', '<p>Our Youth Empowerment programme gives young people guidance, exposure and a chance to lead. Through career guidance, mentoring by volunteers, life-skills and digital-skills workshops and community service projects, young people build the confidence to plan their future and contribute to their community.</p>', 'assets/images/hero/volunteer.svg', '["Few role models or mentors to guide career choices","Limited exposure to communication and computer skills","Little reliable information about courses, scholarships and jobs","Few opportunities to lead and serve"]', '{"image_alt":"Illustration of young volunteers","buttons":[{"label":"Volunteer with us","url":"/become-a-volunteer/","style":"primary","icon":"users"},{"label":"Internships","url":"/internships/","style":"outline","icon":"award"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Youth Empowerment', 'What we do', 'Activities designed with young people, for young people.', NULL, NULL, '[{"icon":"target","title":"Career guidance","text":"Sessions on choosing courses and careers, preparing for interviews and finding reliable information about opportunities."},{"icon":"users","title":"Mentoring","text":"Young people are paired with volunteer mentors who share their experience and help them set and reach goals."},{"icon":"star","title":"Leadership & life skills","text":"Workshops on communication, teamwork, problem-solving, time management and self-confidence."},{"icon":"monitor","title":"Digital skills","text":"Basic computer and internet skills, online safety and responsible use of social media.","link":"/programs/digital-learning/","link_label":"Digital Learning programme"},{"icon":"heart","title":"Community service","text":"Young volunteers take part in plantation drives, awareness campaigns and relief activities in their own communities.","link":"/become-a-volunteer/","link_label":"Volunteer with us"},{"icon":"megaphone","title":"Awareness sessions","text":"Sessions on health, road safety, prevention of substance abuse and civic responsibility."}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"message","title":"Listen to young people","text":"We start by understanding their interests, worries and goals."},{"icon":"layers","title":"Learn by doing","text":"Workshops are practical and interactive, with activities, discussions and projects."},{"icon":"users","title":"Mentor & guide","text":"Volunteer mentors stay in touch and help young people take their next steps."},{"icon":"flag","title":"Lead & serve","text":"Young people plan and lead community projects of their own."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Young people ready to grow', 'The programme welcomes young people from vulnerable and underserved communities.', NULL, NULL, '["School and college students","Young people who have left school and want a new direction","First-generation learners planning their careers","Young people looking for their first job","Youth who want to volunteer in their community","Young women who want to build confidence and skills"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Support workshops, mentoring and youth-led community projects. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Help organise workshops and youth activities in your area.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"graduation","title":"Mentor a young person","text":"Professionals and senior students can volunteer as mentors or run a career or skills session.","link":"/become-a-volunteer/","link_label":"Become a mentor"},{"icon":"megaphone","title":"Spread awareness","text":"Encourage young people you know to join our workshops, volunteering and internships.","link":"/internships/","link_label":"Internships"},{"icon":"briefcase","title":"Partner with us","text":"Schools, colleges and companies can partner with us for career sessions and workshops.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"youth-empowerment","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"youth-empowerment","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Which age group is the programme for?","a":"Our youth activities are mainly for young people of school and college age and those starting their careers. Some sessions are designed for specific age groups – contact us to find one that suits you."},{"q":"Is there a fee to take part?","a":"No. Our youth workshops and mentoring are free for participants."},{"q":"Can I volunteer as a mentor?","a":"Yes. Apply as a volunteer and mention mentoring in your interests. All volunteers who work with children and young people follow our Child Protection Policy."},{"q":"Do you offer internships?","a":"Students and young professionals can apply for internships with the foundation through our Internships page."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Invest in a young person’s future', 'Your support helps young people find guidance, skills and opportunities to lead.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/rural-development');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'Strong villages build a strong nation', 'Families in rural areas often have limited access to information, training and services – including the government schemes that are meant for them.', '<p>Our Rural Development programme works with village communities to change this. We hold awareness drives on health, hygiene, education and rights, support livelihood and skill-building activities, and help families learn about the welfare schemes they may be eligible for and how to apply for them.</p><p>Every activity is planned with community members so that it fits local needs.</p>', 'assets/images/hero/about.svg', '["Limited information about schemes and services","Few local opportunities for skills and income","Long distances to training centres and offices","Need for awareness on health, hygiene and sanitation"]', '{"image_alt":"Illustration of a village community","buttons":[{"label":"Welfare schemes","url":"/schemes/","style":"primary","icon":"list"},{"label":"Volunteer with us","url":"/become-a-volunteer/","style":"outline","icon":"users"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Rural Development', 'What we do', 'Activities planned with village communities and local volunteers.', NULL, NULL, '[{"icon":"megaphone","title":"Community awareness drives","text":"Meetings and campaigns on health, hygiene, sanitation, education, girls’ schooling and rights."},{"icon":"tool","title":"Livelihood & skill-building","text":"Training and guidance that help rural families build skills and add to their income."},{"icon":"file-text","title":"Welfare scheme awareness","text":"Information about government welfare schemes, and help with the documents and steps needed to apply.","link":"/schemes/","link_label":"Welfare schemes"},{"icon":"users","title":"Community groups","text":"Support for Self-Help Groups, youth groups and village volunteers who lead change in their own communities."},{"icon":"leaf","title":"Clean & green villages","text":"Plantation and cleanliness drives, and awareness on water conservation and waste management.","link":"/programs/environment-protection/","link_label":"Environment programme"},{"icon":"book","title":"Learning support","text":"Bringing our education and digital learning activities to rural children and adults.","link":"/programs/education-literacy/","link_label":"Education programme"}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"map-pin","title":"Visit & listen","text":"Volunteers visit villages and meet community members, elders and local groups."},{"icon":"users","title":"Plan together","text":"Priorities and activities are agreed with the community."},{"icon":"zap","title":"Act with local volunteers","text":"Awareness drives and training are run with local volunteers, so that the work continues."},{"icon":"check-circle","title":"Review & follow up","text":"We review what worked and follow up on scheme applications and training."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Rural families and communities', 'The programme works with villages and rural communities where support is most needed.', NULL, NULL, '["Small and marginal farming families","Landless and daily-wage workers","Rural women and Self-Help Groups","Rural youth looking for skills and work","Elderly people and persons with disabilities who need help with schemes","Village volunteers and community leaders"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Support awareness drives, training and scheme guidance in villages. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Join awareness drives and village visits.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"tool","title":"Share your expertise","text":"Professionals in agriculture, health, banking or law can volunteer to run sessions in villages.","link":"/become-a-volunteer/","link_label":"Volunteer your skills"},{"icon":"megaphone","title":"Spread awareness","text":"Help rural families you know learn about welfare schemes and how we can guide them.","link":"/schemes/","link_label":"Welfare schemes"},{"icon":"briefcase","title":"Partner with us","text":"Organisations and institutions working in rural areas can write to us about working together.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"rural-development","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"rural-development","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Which areas do you work in?","a":"We work in communities where our volunteers are active and where support is needed. Please contact us to find out whether we are active near your village."},{"q":"Can you help us apply for a government scheme?","a":"Our volunteers can explain schemes, help check eligibility and documents, and guide you through the application. Decisions on applications are taken only by the government department concerned."},{"q":"Do you charge for scheme guidance?","a":"No. Our guidance is free. Never pay anyone who promises to get a scheme approved in exchange for money."},{"q":"How can our village invite you for an awareness drive?","a":"Write to us through the Contact page with the name of your village and district and the topics you would like covered."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help a village grow stronger', 'Your support helps rural families access information, skills and the services meant for them.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/environment-protection');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'A healthy planet for healthy communities', 'Polluted air, unmanaged waste and the loss of green spaces affect everyone – but the poorest communities often feel the impact first.', '<p>Our Environment Protection programme brings people together to care for their surroundings. We organise tree plantation and cleanliness drives, promote waste segregation and reduction, and encourage simple sustainable habits at home, in schools and in neighbourhoods.</p><p>Plantation drives are followed by care for the saplings, because planting a tree is only the beginning.</p>', 'assets/images/hero/events.svg', '["Waste that is not segregated or managed","Shrinking green cover in cities and villages","Low awareness of simple sustainable habits","Health problems linked to unclean surroundings"]', '{"image_alt":"Illustration of a community drive","buttons":[{"label":"Join a drive","url":"/become-a-volunteer/","style":"primary","icon":"leaf"},{"label":"Upcoming events","url":"/events-page/","style":"outline","icon":"calendar"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Environment Protection', 'What we do', 'Hands-on activities that anyone can join.', NULL, NULL, '[{"icon":"leaf","title":"Tree plantation drives","text":"Planting native saplings with communities, schools and volunteers – and arranging for their care after planting."},{"icon":"refresh","title":"Waste management","text":"Awareness on segregating wet and dry waste, composting and reducing single-use plastic."},{"icon":"sun","title":"Sustainable living","text":"Simple practices for saving water and electricity, reusing materials and choosing eco-friendly options."},{"icon":"users","title":"Cleanliness drives","text":"Neighbourhood clean-up drives that bring residents, students and volunteers together."},{"icon":"book","title":"School eco-clubs & sessions","text":"Activities and sessions that help children understand and protect their environment."},{"icon":"calendar","title":"Environment days","text":"Awareness activities around important days such as World Environment Day.","link":"/awareness-calendar/","link_label":"Awareness calendar"}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"search","title":"Choose the site & need","text":"We identify locations and issues together with residents, schools and local bodies."},{"icon":"users","title":"Bring volunteers together","text":"Drives are announced in advance and volunteers are briefed on the activity and on safety."},{"icon":"leaf","title":"Act together","text":"Plantation, clean-up or awareness activities are carried out with the community."},{"icon":"refresh","title":"Care & follow up","text":"Saplings are looked after and good habits are encouraged long after the drive."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Everyone who shares our planet', 'Our environment activities involve whole communities.', NULL, NULL, '["Residents of urban settlements and villages","School and college students","Resident welfare associations","Youth and volunteer groups","Local shops and small businesses","Families who want to adopt sustainable habits"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Help fund saplings, tree guards and awareness material. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Join a plantation or clean-up drive near you.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"flag","title":"Lead a drive","text":"Students, residents’ groups and teams at work can organise a drive with our guidance.","link":"/contact/","link_label":"Plan a drive"},{"icon":"megaphone","title":"Spread awareness","text":"Share simple sustainable habits with your family and neighbours, and invite them to our drives.","link":"/events-page/","link_label":"Upcoming drives"},{"icon":"briefcase","title":"Partner with us","text":"Schools, companies and resident welfare associations can write to us about working together.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"environment-protection","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"waste-management","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Can I join a plantation drive?","a":"Yes. Upcoming drives are listed on our Events page once they are confirmed. You can also register as a volunteer to hear about future drives."},{"q":"Can our school or housing society host a drive?","a":"Yes. Write to us with the location and the kind of activity you have in mind, and we will plan it with you."},{"q":"What happens to the saplings after planting?","a":"We plant at locations where someone takes responsibility for watering and care, and we encourage residents and volunteers to look after the saplings."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Plant a tree, protect our future', 'Join or support our drives for a cleaner, greener tomorrow.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Join a Drive","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'skill-development');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'Skills that open doors to work and enterprise', 'Many young people and adults want to work but lack the practical skills, guidance or confidence that employers look for.', '<p>Our Skill Development &amp; Training programme helps them get there. We offer vocational and digital training, career guidance, interview preparation and information about job opportunities, along with guidance for those who want to start their own small enterprise.</p><p>Training is practical and focused on skills that are in demand locally.</p>', 'assets/images/hero/career.svg', '["Lack of job-ready skills","Little guidance on careers and job searching","Low confidence in interviews and at the workplace","Limited knowledge of how to start a small business"]', '{"image_alt":"Illustration of a person starting work","buttons":[{"label":"Ask about training","url":"/contact/","style":"primary","icon":"mail"},{"label":"Volunteer as a trainer","url":"/become-a-volunteer/","style":"outline","icon":"tool"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Skill Development & Training', 'What we do', 'Training and guidance focused on real opportunities.', NULL, NULL, '[{"icon":"tool","title":"Vocational training","text":"Practical training in trades and services, chosen according to local demand and the interests of participants."},{"icon":"monitor","title":"Digital training","text":"Computer basics, typing, email, online forms and other digital skills needed at work.","link":"/programs/digital-learning/","link_label":"Digital Learning programme"},{"icon":"target","title":"Career guidance","text":"One-to-one and group guidance on choosing a path, writing a CV and searching for jobs safely."},{"icon":"briefcase","title":"Placement support","text":"Information about suitable job openings and help with applications. We never charge for placement support."},{"icon":"message","title":"Soft skills & interviews","text":"Communication, workplace etiquette, teamwork and mock interviews to build confidence."},{"icon":"trending-up","title":"Entrepreneurship","text":"Guidance for people who want to start a small business, including basic planning, record keeping and relevant government schemes.","link":"/schemes/","link_label":"Welfare schemes"}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"search","title":"Understand interests","text":"We understand each participant’s interests, education and goals."},{"icon":"tool","title":"Train","text":"Practical sessions build skills step by step."},{"icon":"message","title":"Prepare","text":"Participants practise interviews and workplace skills."},{"icon":"briefcase","title":"Connect","text":"We share suitable opportunities and stay in touch as participants take their next steps."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'People ready to build their skills', 'Training is open to people from underserved communities who want to work or start an enterprise.', NULL, NULL, '["Young people who have finished or left school","Graduates looking for their first job","Women returning to work","Adults who want to learn new skills","Aspiring entrepreneurs","People who want to improve their digital skills"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Help fund training material, equipment and sessions. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Help organise training sessions and support participants.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"tool","title":"Share your skills","text":"Trainers and professionals can run sessions, take mock interviews or mentor participants.","link":"/become-a-volunteer/","link_label":"Volunteer your skills"},{"icon":"megaphone","title":"Spread awareness","text":"Tell people who are looking for work about our free training and guidance.","link":"/events-page/","link_label":"Upcoming sessions"},{"icon":"briefcase","title":"Partner with us","text":"Employers and training institutes can write to us about offering training or genuine job opportunities.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"skill-development","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"job-placement","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Is the training free?","a":"Yes. Our skill development activities are free for participants. We never charge a fee for training or placement support."},{"q":"Do you guarantee a job after training?","a":"No one can honestly guarantee a job. We help participants build skills, prepare for interviews and learn about suitable openings – the hiring decision is always the employer’s."},{"q":"Can employers recruit through you?","a":"Employers with genuine openings can write to us. We share opportunities with participants who match the requirements."},{"q":"Someone offered me a job in your name for a fee. Is it genuine?","a":"No. We never ask for money for training or jobs. Please report such requests through our Report Fraud page."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help someone build a livelihood', 'Your support helps people gain skills, confidence and opportunities for work and enterprise.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/digital-learning');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'Digital skills are everyday life skills', 'From paying a bill to applying for a scheme or attending an online class, more and more of daily life happens on a phone or computer. People without digital skills – or without awareness of online fraud – risk being left behind or cheated.', '<p>Our Digital Learning programme teaches practical digital skills in simple language. Learners start with the basics of using a smartphone or computer, then learn to use online services, stay safe online and make digital payments safely.</p><p>Sessions are hands-on and patient, so that first-time users, women and senior citizens feel comfortable learning.</p>', 'assets/images/hero/account.svg', '["First-time users who are unsure where to start","Online and payment fraud that targets the unaware","Online services and forms that are hard to navigate","Children who need guidance on safe internet use"]', '{"image_alt":"Illustration of a person using a phone","buttons":[{"label":"Volunteer to teach","url":"/volunteer-teacher/","style":"primary","icon":"monitor"},{"label":"Ask about sessions","url":"/contact/","style":"outline","icon":"mail"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Digital Learning', 'What we do', 'Practical, step-by-step sessions in simple language.', NULL, NULL, '[{"icon":"monitor","title":"Computer basics","text":"Using a computer, typing, files and folders, email and the internet – step by step."},{"icon":"smartphone","title":"Smartphone skills","text":"Using a smartphone with confidence: calls and messages, camera, maps, useful apps and settings."},{"icon":"shield","title":"Online safety","text":"Recognising fake calls, messages and links, creating strong passwords and protecting personal information."},{"icon":"rupee","title":"Safe digital payments","text":"Using UPI and net banking safely: never sharing an OTP or PIN, checking before paying, and what to do if something goes wrong."},{"icon":"globe","title":"Online services","text":"Filling in online forms, booking appointments and finding reliable information on official websites.","link":"/schemes/","link_label":"Welfare schemes"},{"icon":"users","title":"Safe internet for children","text":"Sessions for children and parents on safe and responsible use of the internet and social media."}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"search","title":"Find the starting point","text":"We find out what each learner already knows and what they want to be able to do."},{"icon":"smartphone","title":"Learn on real devices","text":"Learners practise on phones and computers, at their own pace."},{"icon":"shield","title":"Build safe habits","text":"Every session includes simple rules for staying safe online."},{"icon":"check-circle","title":"Practise & revise","text":"Learners repeat tasks until they can do them on their own."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Anyone who wants to be confident online', 'Sessions are designed for different groups, especially those most at risk of being left behind.', NULL, NULL, '["Children and students","Women and homemakers","Adults using a smartphone for the first time","Senior citizens","Small shopkeepers and self-employed people who accept digital payments","Job seekers who need digital skills"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Help fund devices, internet access and learning material for sessions. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Sit with learners during sessions and help them practise.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"monitor","title":"Teach digital skills","text":"Students and IT professionals can volunteer to teach computer, smartphone or online safety sessions.","link":"/volunteer-teacher/","link_label":"Volunteer to teach"},{"icon":"megaphone","title":"Spread awareness","text":"Share simple online safety tips with your family – above all, never share an OTP or PIN with anyone.","link":"/report-fraud/","link_label":"Report fraud"},{"icon":"briefcase","title":"Partner with us","text":"Schools, libraries and companies can host sessions or offer working devices – please write to us first.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"digital-learning","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"education","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Do I need my own phone or computer to join?","a":"No. Wherever possible, learners can practise on devices available at our sessions, and on their own phones if they have one."},{"q":"I have never used a smartphone. Can I still join?","a":"Yes. Sessions start from the very basics and go at the learner’s pace. Senior citizens and first-time users are welcome."},{"q":"What should I do if I have been cheated online?","a":"Call the national cyber crime helpline 1930 as soon as possible or report at cybercrime.gov.in, and inform your bank immediately. Never share an OTP, PIN or password with anyone."},{"q":"Can I donate an old laptop or phone?","a":"Please contact us before donating a device so that we can confirm it is needed and can be used safely, with your personal data removed."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help someone get confident online', 'Your support helps learners gain digital skills and stay safe online.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Volunteer to Teach","url":"/volunteer-teacher/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/scholarship-educational-support');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'No deserving student should stop studying because of money', 'Every year, capable students leave school or college because their families cannot pay the fees, buy books or manage the cost of travel.', '<p>Our Scholarship &amp; Educational Support programme helps such students continue. Students can apply online for scholarship support; every application is reviewed carefully, and support is decided on the basis of need, merit and the funds available.</p><p>We also guide students on courses and on government scholarships they may be eligible for, and connect them with volunteer mentors.</p>', 'assets/images/hero/scholarship.svg', '["Fees, books and travel costs that families cannot meet","Little information about available scholarships","First-generation students without guidance","Students at risk of dropping out"]', '{"image_alt":"Illustration of a scholarship","buttons":[{"label":"Apply for a scholarship","url":"/scholarship/","style":"primary","icon":"award"},{"label":"Support a student","url":"/donate/","style":"outline","icon":"heart"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Scholarship & Educational Support', 'What we do', 'Support that helps students stay in education.', NULL, NULL, '[{"icon":"award","title":"Scholarship support","text":"Students from economically weaker families can apply online. Support is decided after reviewing the application and documents.","link":"/scholarship/","link_label":"Apply for a scholarship"},{"icon":"package","title":"Study material","text":"Books, notebooks, stationery and other learning material for students who need them."},{"icon":"file-text","title":"Government scholarship guidance","text":"Information about government scholarships and help with applying on the official portals.","link":"/schemes/","link_label":"Welfare schemes"},{"icon":"target","title":"Course & career guidance","text":"Guidance on choosing courses, entrance examinations and career paths."},{"icon":"users","title":"Mentoring","text":"Volunteer mentors encourage students, help them plan their studies and stay in touch through the year."},{"icon":"check-circle","title":"Fair & transparent process","text":"Applications are reviewed fairly, and applicants can track the status online with their reference number.","link":"/application-status/","link_label":"Check application status"}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"edit","title":"Apply online","text":"Students fill in the scholarship application form and upload the documents requested."},{"icon":"search","title":"Review","text":"Our team reviews each application and may contact the student or family for more information."},{"icon":"check-circle","title":"Decision","text":"Support is decided based on need, merit and the funds available, and the applicant is informed."},{"icon":"users","title":"Stay connected","text":"Supported students are encouraged to stay in touch and share their progress with their mentor."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Students who need a helping hand', 'Scholarship and educational support is meant for students who are determined to study but face financial difficulty.', NULL, NULL, '["School students from economically weaker families","College and university students","Students in vocational or professional courses","First-generation learners","Students who have lost a parent or the earning member of the family","Girls at risk of leaving education"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Your donation supports scholarships and study material for deserving students. You receive a receipt for every verified donation.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Help with outreach, document checks and follow-up with students.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"graduation","title":"Mentor a student","text":"Teachers, professionals and senior students can mentor scholarship students.","link":"/volunteer-teacher/","link_label":"Volunteer to mentor"},{"icon":"megaphone","title":"Spread awareness","text":"Tell students who need support about the online scholarship application.","link":"/scholarship/","link_label":"Scholarship application"},{"icon":"briefcase","title":"Partner with us","text":"Institutions and companies can write to us about supporting students together.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"scholarship-educational-support","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"education","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Who can apply for a scholarship?","a":"Students from economically weaker families who are studying in a school, college or recognised course can apply. The application form explains the details and the documents required."},{"q":"How do I apply?","a":"Apply online through the Scholarship Application page. You will receive a reference number that you can use on the Application Status page."},{"q":"Is there an application fee?","a":"No. Applying is free. We never ask for money to process or approve a scholarship – please report anyone who does."},{"q":"Does every applicant receive a scholarship?","a":"Support depends on need, merit and the funds available at the time. Every application is reviewed and every applicant is informed of the decision."},{"q":"Can you help with government scholarships?","a":"Yes. Our volunteers can guide you on government scholarships you may be eligible for and on how to apply through the official portals."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help a student stay in education', 'Apply for scholarship support, or support a deserving student today.', NULL, NULL, '[]', '{"buttons":[{"label":"Apply for a Scholarship","url":"/scholarship/","style":"light","icon":"award","size":"lg"},{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/food-distribution');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'No family should go to bed hungry', 'Illness, loss of work, rising prices or a disaster can leave families without enough to eat. In such moments, timely help with food and essentials can make all the difference.', '<p>Our Food Distribution programme provides ration kits, cooked food and essential supplies to families facing hardship. Families are identified with the help of local volunteers and community members, and distributions are organised in an orderly and respectful way.</p><p>Food distribution campaigns are opened only when there is a real need, and campaign progress shows verified donations only.</p>', 'assets/images/hero/donate.svg', '["Families who have lost their income","Elderly people living alone","Families affected by illness or disaster","Children who need nutritious food"]', '{"image_alt":"Illustration of giving","buttons":[{"label":"Request food support","url":"/request-help/","style":"primary","icon":"life-buoy"},{"label":"Volunteer at a distribution","url":"/become-a-volunteer/","style":"outline","icon":"users"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Food Distribution', 'What we do', 'Help that reaches the right families, in the right way.', NULL, NULL, '[{"icon":"package","title":"Ration kits","text":"Kits of essential food items such as grains, pulses and cooking oil for families in need."},{"icon":"heart","title":"Cooked food in emergencies","text":"Cooked meals during emergencies and disasters, when families cannot cook for themselves."},{"icon":"check-circle","title":"Verified need","text":"Families are identified with the help of local volunteers and community members, so that help reaches those who need it most."},{"icon":"users","title":"Dignified distribution","text":"Orderly distribution that respects the dignity and privacy of every family – no photographs without consent."},{"icon":"activity","title":"Nutrition awareness","text":"Simple guidance on balanced, affordable nutrition for children, pregnant women and elderly people."},{"icon":"shield","title":"Hygiene essentials","text":"Where needed, hygiene items such as soap and sanitary products are included with food support."}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"search","title":"Identify families","text":"Local volunteers and community members help us identify families in genuine need."},{"icon":"package","title":"Prepare supplies","text":"Kits are prepared with care, and items are checked for quality."},{"icon":"users","title":"Distribute with dignity","text":"Volunteers distribute supplies in an orderly and respectful way."},{"icon":"file-text","title":"Account for every rupee","text":"Purchases and distributions are recorded, and campaign progress shows verified donations only."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Families facing hunger or hardship', 'Food support is provided on the basis of need, never on the basis of caste, religion or any other identity.', NULL, NULL, '["Daily-wage and migrant workers who have lost income","Elderly people living alone","Widows and women-headed households","Families affected by illness, floods or other disasters","Persons with disabilities","Children in families facing food shortage"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"Fund ration kits and meals for families in need. Every verified donation receives a receipt.","link":"/donate/","link_label":"Donate now"},{"icon":"users","title":"Volunteer","text":"Help pack and distribute supplies at our distributions.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"flag","title":"Start a fundraiser","text":"Propose a fundraiser for a food drive in your community – we review every proposal before it goes live.","link":"/start-a-fundraiser/","link_label":"Start a fundraiser"},{"icon":"megaphone","title":"Spread awareness","text":"Tell us about families who need help – with their consent – through the Request Help form.","link":"/request-help/","link_label":"Request help"},{"icon":"briefcase","title":"Partner with us","text":"Businesses and community groups can write to us about supporting food drives. Please contact us before donating food items.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"food-distribution","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"food-donation","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"How do you decide who receives food support?","a":"Families are identified with the help of local volunteers and community members, based on need. Support is free and is never linked to religion, caste or any other identity."},{"q":"When are food distribution campaigns opened?","a":"Relief campaigns are opened only when there is a real need – for example after a disaster or during a crisis. When a campaign is open, its progress shows verified donations only."},{"q":"Can I donate food items instead of money?","a":"Please contact us before donating food items so that we can make sure they are needed, safe and can be distributed in time."},{"q":"How can I request food support for a family?","a":"Use the Request Help form. Please share someone else’s details only with their consent."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Help a family through a difficult time', 'Your support helps us provide food and essentials with dignity when families need them most.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

SET @pid := (SELECT id FROM pages WHERE slug = 'programs/disaster-relief');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'subnav', NULL, NULL, NULL, NULL, NULL, NULL, '[{"anchor":"overview","label":"Overview"},{"anchor":"what-we-do","label":"What we do"},{"anchor":"approach","label":"Our approach"},{"anchor":"who-we-work-with","label":"Who we work with"},{"anchor":"at-a-glance","label":"At a glance"},{"anchor":"get-involved","label":"How you can help"},{"anchor":"faqs","label":"FAQs"}]', '{"bare":true}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'overview', 'Overview', 'Standing with communities when disaster strikes', 'Floods, storms, fires and other disasters can take away homes, livelihoods and a sense of safety overnight. The first days and weeks are the hardest.', '<p>Our Disaster Relief programme provides food, drinking water, essential supplies, and hygiene and health support to affected families, working with volunteers and local groups to reach people in a coordinated way. Earlier relief campaigns of the foundation include Bihar Flood Relief and Assam Flood Relief.</p><p>Relief campaigns are opened only when there is a real need, and campaign progress shows verified donations only. After the emergency, we stay to help families recover.</p>', 'assets/images/hero/campaigns.svg', '["Families who lose food, shelter and belongings","Need for clean water, hygiene and health care","Disruption of children’s education","Loss of livelihoods after the emergency"]', '{"image_alt":"Illustration of relief campaigns","buttons":[{"label":"View campaigns","url":"/campaigns/","style":"primary","icon":"heart"},{"label":"Register as a volunteer","url":"/become-a-volunteer/","style":"outline","icon":"users"}]}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'what-we-do', 'Disaster Relief', 'What we do', 'Coordinated relief, based on need.', NULL, NULL, '[{"icon":"package","title":"Food & essential supplies","text":"Dry ration, cooked food where needed, drinking water and essential household items for affected families."},{"icon":"shield","title":"Hygiene & health support","text":"Hygiene kits, sanitary products and help in reaching medical care, to prevent illness after a disaster."},{"icon":"users","title":"Relief coordination","text":"Volunteers assess needs and coordinate distribution with local groups, so that help reaches the right places."},{"icon":"home","title":"Shelter essentials","text":"Tarpaulins, blankets, mats and other essentials, depending on the situation and the season."},{"icon":"book","title":"Back to learning","text":"Study material and support to help children return to learning after a disaster."},{"icon":"refresh","title":"Recovery support","text":"Guidance on rebuilding livelihoods and on applying for government relief and welfare schemes.","link":"/schemes/","link_label":"Welfare schemes"}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'approach', 'How we work', 'Our approach', 'Every activity follows a simple, accountable process.', NULL, NULL, '[{"icon":"alert-triangle","title":"Assess","text":"We gather information from local volunteers and community members about who is affected and what is needed."},{"icon":"flag","title":"Open a campaign only if needed","text":"A relief campaign is opened only when there is a real need, with clear information on how the funds will be used."},{"icon":"package","title":"Distribute","text":"Supplies are distributed in a coordinated, orderly way with volunteers."},{"icon":"file-text","title":"Report","text":"We share updates on the relief work, and campaign progress shows verified donations only."}]', '{"align":"center"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'checklist', 'who-we-work-with', 'Who we work with', 'Communities affected by disasters', 'Relief is provided on the basis of need, without discrimination.', NULL, NULL, '["Families affected by floods, storms, fires and other disasters","Elderly people, pregnant women and young children","Persons with disabilities","Daily-wage workers who lose their livelihood","Children whose education is disrupted","Communities in remote or hard-to-reach areas"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'at-a-glance', NULL, 'At a glance', NULL, NULL, NULL, '[]', '{}', 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'get-involved', 'Get involved', 'How you can help', 'There are many ways to support this programme – choose the one that suits you.', NULL, NULL, '[{"icon":"heart","title":"Donate","text":"When a relief campaign is open, your donation helps provide food and essentials. Progress shows verified donations only.","link":"/campaigns/","link_label":"View campaigns"},{"icon":"users","title":"Volunteer","text":"Register as a volunteer to be contacted when relief work needs extra hands.","link":"/become-a-volunteer/","link_label":"Apply to volunteer"},{"icon":"activity","title":"Share your skills","text":"Doctors, nurses, and logistics and communication volunteers are especially valuable during relief work.","link":"/become-a-volunteer/","link_label":"Volunteer your skills"},{"icon":"megaphone","title":"Spread verified information","text":"Share only information from our official website and pages – and help others avoid fake appeals.","link":"/report-fraud/","link_label":"Report a fake appeal"},{"icon":"briefcase","title":"Partner with us","text":"Organisations can write to us about coordinating relief and recovery work together.","link":"/contact/","link_label":"Contact us"}]', '{"cols":"3","style":"soft","align":"center"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","category":"disaster-relief","limit":3}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'gallery_strip', NULL, 'Gallery', 'Moments from the field', NULL, NULL, NULL, '[]', '{"category":"disaster-relief","limit":6,"media":"image"}', 'white', 'visible', 90);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Frequently asked questions', NULL, NULL, NULL, '[{"q":"Why is there no disaster relief campaign open right now?","a":"We open relief campaigns only when there is a real need. When a campaign is open, it is listed on our Campaigns page with details of how the funds will be used."},{"q":"How do I know my donation reached the relief work?","a":"Every verified donation receives a receipt, campaign progress shows verified donations only, and utilisation reports are published on our website once they are ready."},{"q":"Can I volunteer during a disaster?","a":"Yes. Register as a volunteer and mention disaster relief in your interests. Relief work can be demanding, so volunteers are briefed and given tasks that match their skills and the situation."},{"q":"Someone is collecting money for flood relief in your name. Is it genuine?","a":"Donate only through the Donate page on this website. If you receive a suspicious appeal, please report it through our Report Fraud page."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 100);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Be ready to help when it matters', 'Register as a volunteer, or support a relief campaign whenever one is open.', NULL, NULL, '[]', '{"buttons":[{"label":"View Campaigns","url":"/campaigns/","style":"donate","icon":"heart","size":"lg"},{"label":"Register as a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 110);

-- 5. Programs overview page (/programs/)
SET @pid := (SELECT id FROM pages WHERE slug = 'programs');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'our-work', 'Our work', 'Empowering vulnerable and underserved communities', 'Prerit Welfare Foundation is a nonprofit organization dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care, and skill development.', '<p>Our programmes are run by volunteers, educators and community members who know the people they serve. Each programme focuses on practical help – learning, health, skills, livelihoods, a cleaner environment and support in times of crisis – delivered with dignity and accountability.</p><p>We strive to build a fair and inclusive society where everyone can live with dignity and equal opportunity.</p>', 'assets/images/hero/team.svg', '["Free for the people we serve","Planned with communities, not just for them","Carried out by volunteers who are screened and briefed","Accountable use of every donation"]', '{"image_alt":"Illustration of the communities we serve","buttons":[{"label":"Support our work","url":"/donate/","style":"donate","icon":"heart"},{"label":"Volunteer with us","url":"/become-a-volunteer/","style":"outline","icon":"users"}]}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'module', 'all-programmes', NULL, 'Explore our programmes', 'Each programme is run with volunteers and community members. Choose an area to see what we do and how you can help.', NULL, NULL, '[]', '{}', 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'how-we-work', 'How we work', 'How every programme works', 'Whatever the area of work, we follow the same simple and accountable process.', NULL, NULL, '[{"icon":"message","title":"Listen","text":"We begin by listening to community members, families and local volunteers to understand real needs."},{"icon":"layers","title":"Plan","text":"Activities are planned with the community, with clear responsibilities and safeguards."},{"icon":"users","title":"Act","text":"Volunteers and professionals carry out the work with care, respect and attention to safety."},{"icon":"file-text","title":"Report","text":"We review what worked and share verified updates – never invented figures."}]', '{"align":"center"}', 'white', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'our-commitments', 'Our commitments', 'What you can expect from us', 'These principles apply to every programme.', NULL, NULL, '[{"icon":"shield","title":"Safeguarding","text":"Everyone who works with children and vulnerable people follows our Child Protection Policy.","link":"/child-protection-policy/","link_label":"Child Protection Policy"},{"icon":"check-circle","title":"Verified figures only","text":"We publish figures only from verified records – campaign progress shows verified donations only."},{"icon":"file-text","title":"Receipts & transparency","text":"Every verified donation receives a receipt, and utilisation reports are published once they are ready.","link":"/financial-transparency/","link_label":"Financial transparency"},{"icon":"heart","title":"Dignity & consent","text":"Photos and stories of the people we serve are shared only with their consent."},{"icon":"users","title":"Open to all","text":"Our support is open to everyone in need, regardless of caste, religion or gender."},{"icon":"alert-triangle","title":"Free of charge","text":"We never charge people for the help they receive, or ask for money to approve an application.","link":"/report-fraud/","link_label":"Report misuse of our name"}]', '{"cols":"3","style":"soft"}', 'tint', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'campaigns_grid', NULL, 'Campaigns', 'Support an active campaign', 'Amounts shown include verified donations only.', NULL, NULL, '[]', '{"status":"active","limit":3}', 'white', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'faqs', 'faqs', 'FAQs', 'Questions about our programmes', NULL, NULL, NULL, '[{"q":"How can I get support from one of your programmes?","a":"Fill in the Request Help form or contact us. A member of our team will get in touch to understand your situation and explain how we can help."},{"q":"Can I choose what my donation supports?","a":"You can donate to a specific campaign from our Campaigns page, or make a general donation that we use where the need is greatest. Every verified donation receives a receipt."},{"q":"Where do you work?","a":"Our office is in New Delhi (110051). We work in communities where our volunteers are active – please contact us to find out about activities near you."},{"q":"Can my organisation partner with you on a programme?","a":"Yes. Schools, colleges, companies and community groups can write to us through the Contact page to discuss working together."}]', '{"width":"narrow","align":"center","open_first":true}', 'cream', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Find a way to support a programme', 'Support a programme, give your time as a volunteer, or tell someone who needs help about our work.', NULL, NULL, '[]', '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 70);

-- 6. Welfare Schemes page (/schemes/) - the schemes_list section shows the searchable directory
SET @pid := (SELECT id FROM pages WHERE slug = 'schemes');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'split', 'about-schemes', 'Welfare schemes', 'Know the schemes meant for you', 'Central and state governments run many welfare schemes for education, health, women, livelihoods, housing, social security and more. Yet many eligible families never benefit, because they do not know about a scheme or find the application process difficult.', '<p>This directory explains selected schemes in simple language – who can apply, the benefits, the documents usually needed and how to apply – with a link to the official source for each one. A scheme is published here only after our team has checked its details against the official source, and the date of that check is shown on every scheme.</p><p>Our volunteers can also help you understand a scheme and guide you through the application, free of charge.</p>', 'assets/images/hero/faq.svg', '["Simple explanations with a link to the official source","The date of the last check shown on every scheme","Free guidance from our volunteers"]', '{"image_alt":"Illustration of a volunteer helping a family","buttons":[{"label":"Request help","url":"/request-help/","style":"primary","icon":"life-buoy"}]}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'features', 'how-we-help', 'How we help', 'How we help people access schemes', 'Practical, free guidance – from finding the right scheme to submitting the application.', NULL, NULL, '[{"icon":"search","title":"Find the right scheme","text":"We help you understand which schemes you may be eligible for, based on your situation."},{"icon":"file-text","title":"Check your documents","text":"We explain which documents are usually needed and help you check them before you apply."},{"icon":"edit","title":"Guide the application","text":"Volunteers guide you through the application on the official portal or at the government office."},{"icon":"shield","title":"Stay safe from fraud","text":"Applying on official portals is usually free. Beware of anyone who asks for money to get a scheme approved.","link":"/report-fraud/","link_label":"Report fraud"}]', '{"cols":"4","style":"card"}', 'tint', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'steps', 'get-help', 'Step by step', 'How to get help with a scheme', 'Our help is free of charge.', NULL, NULL, '[{"icon":"send","title":"Tell us what you need","text":"Fill in the Request Help form or contact us, and mention the scheme or the kind of support you are looking for."},{"icon":"phone","title":"We get in touch","text":"A volunteer contacts you to understand your situation."},{"icon":"file-text","title":"Prepare together","text":"We help you check your eligibility and the documents required."},{"icon":"check-circle","title":"Apply at the official source","text":"You apply through the official portal or office. Decisions are taken only by the government department concerned."}]', '{"align":"center"}', 'white', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'schemes_list', 'find-a-scheme', 'Directory', 'Find a scheme', 'Every scheme listed here has been checked against its official source.', NULL, NULL, '[]', '{"limit":9}', 'cream', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'callout', NULL, NULL, 'Please note', 'The information on this page is a simplified guide prepared by Prerit Welfare Foundation. Scheme rules, benefits and deadlines are decided by the government and can change at any time – the official source always prevails. We are not a government body, we do not approve applications and we never charge a fee for scheme information or guidance.', NULL, NULL, '[]', '{"tone":"gold","icon":"alert-triangle","width":"narrow","size":"sm"}', 'white', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@pid, 'cta', NULL, NULL, 'Need help with a scheme?', 'Our volunteers can help you understand a scheme and guide you through the application – free of charge.', NULL, NULL, '[]', '{"buttons":[{"label":"Request Help","url":"/request-help/","style":"donate","icon":"life-buoy","size":"lg"},{"label":"Contact Us","url":"/contact/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 60);

-- ---------------------------------------------------------------------
-- Module M3
-- ---------------------------------------------------------------------
-- =====================================================================
-- M3 Fundraising: draft campaigns (no amounts, dates or images) and page sections for
-- /campaigns/, /donate/, /start-a-fundraiser/, /financial-transparency/, /utilization-reports/.
-- Content describes purposes and intentions only - no results, figures or beneficiary numbers.
-- Re-runnable: deletes exactly these rows first.
-- =====================================================================
SET NAMES utf8mb4;

-- Draft campaigns (staff complete them - target, dates, image - before publishing)
DELETE FROM campaigns WHERE slug IN ('bihar-flood-relief', 'assam-flood-relief', 'emergency-relief', 'education-support', 'food-distribution');
INSERT INTO campaigns (title, slug, program_id, category, short_description, description, goal_amount, image, location, start_date, end_date, status, is_featured, is_urgent, meta_title, meta_description) VALUES ('Bihar Flood Relief', 'bihar-flood-relief', (SELECT p.id FROM programs p WHERE p.slug = 'disaster-relief' LIMIT 1), 'disaster-relief', 'Help families affected by floods in Bihar with dry rations, hygiene kits, safe drinking water and temporary shelter material, distributed with local volunteers.', '<p>Floods in Bihar repeatedly force families to leave their homes, often with very little time to save food, documents or belongings. This campaign is being prepared so that Prerit Welfare Foundation can respond quickly with essential relief, working with local volunteers and community members.</p><h2>What your donation would support</h2><ul><li>Dry ration kits (rice, pulses, flour, oil, salt) for affected households</li><li>Hygiene kits with soap, sanitary pads, toothpaste and other daily essentials</li><li>Safe drinking water - water purification tablets and containers</li><li>Temporary shelter material such as tarpaulins and sheets</li><li>Basic medical support and first-aid supplies, with referrals to health services where needed</li></ul><h2>How we plan to work</h2><p>Relief is planned with local volunteers and community representatives so that support reaches the families who need it most. Distribution details, campaign updates and approved expenses will be published on this page, and every donation is counted only after it is verified.</p>', 0.00, NULL, 'Bihar', NULL, NULL, 'draft', 0, 0, 'Bihar Flood Relief - Donate', 'Help families affected by floods in Bihar with dry rations, hygiene kits, safe drinking water and temporary shelter material, distributed with local volunteers.');
INSERT INTO campaigns (title, slug, program_id, category, short_description, description, goal_amount, image, location, start_date, end_date, status, is_featured, is_urgent, meta_title, meta_description) VALUES ('Assam Flood Relief', 'assam-flood-relief', (SELECT p.id FROM programs p WHERE p.slug = 'disaster-relief' LIMIT 1), 'disaster-relief', 'Support flood-affected communities in Assam with food, hygiene essentials, clean drinking water and shelter material, coordinated with volunteers on the ground.', '<p>Seasonal floods in Assam affect villages along the Brahmaputra and its tributaries, damaging homes, crops and access to clean water. Through this campaign Prerit Welfare Foundation intends to provide timely relief to affected families together with local volunteers.</p><h2>What your donation would support</h2><ul><li>Dry ration kits for families who have lost food stocks</li><li>Hygiene and sanitation kits, including menstrual hygiene products</li><li>Drinking water support - purification tablets, filters and storage containers</li><li>Tarpaulins and other temporary shelter material</li><li>First-aid supplies and support to reach health camps and services</li></ul><h2>Accountability</h2><p>Updates from the field and approved expenses will be shared on this page. The amount shown as raised includes only donations verified with our payment gateway or reconciled by our finance team.</p>', 0.00, NULL, 'Assam', NULL, NULL, 'draft', 0, 0, 'Assam Flood Relief - Donate', 'Support flood-affected communities in Assam with food, hygiene essentials, clean drinking water and shelter material, coordinated with volunteers on the ground.');
INSERT INTO campaigns (title, slug, program_id, category, short_description, description, goal_amount, image, location, start_date, end_date, status, is_featured, is_urgent, meta_title, meta_description) VALUES ('Emergency Relief Fund', 'emergency-relief', (SELECT p.id FROM programs p WHERE p.slug = 'disaster-relief' LIMIT 1), 'disaster-relief', 'A ready fund that lets us respond quickly when floods, fires, cold waves or other emergencies hit vulnerable families - with food, essentials and basic medical support.', '<p>Emergencies rarely give advance notice. An emergency relief fund allows Prerit Welfare Foundation to act quickly - for example after floods, fires, extreme cold or other disasters - instead of starting a new appeal each time.</p><h2>What your donation would support</h2><ul><li>Food and dry rations for affected families</li><li>Blankets, warm clothing and essential household items during cold waves</li><li>Hygiene kits and safe drinking water</li><li>Temporary shelter material</li><li>Basic medical support, first aid and transport to health facilities</li><li>Coordination costs of relief work carried out with volunteers</li></ul><h2>Transparency</h2><p>Whenever the fund is used, we will publish an update describing where and how it was used, along with the approved expenses in our utilisation reports.</p>', 0.00, NULL, 'India', NULL, NULL, 'draft', 0, 0, 'Emergency Relief Fund - Donate', 'A ready fund that lets us respond quickly when floods, fires, cold waves or other emergencies hit vulnerable families - with food, essentials and basic medical support.');
INSERT INTO campaigns (title, slug, program_id, category, short_description, description, goal_amount, image, location, start_date, end_date, status, is_featured, is_urgent, meta_title, meta_description) VALUES ('Education Support for Children', 'education-support', (SELECT p.id FROM programs p WHERE p.slug = 'education-literacy' LIMIT 1), 'education-literacy', 'Help children from underprivileged families stay in school with school kits, study material and learning support through community learning circles.', '<p>Many children from low-income families drop out or fall behind because they cannot afford basic school supplies or do not get support with their studies at home. This campaign will support our education and literacy work in the community.</p><h2>What your donation would support</h2><ul><li>School kits - bags, notebooks, stationery and geometry boxes</li><li>Study material and books for community learning circles</li><li>Learning support sessions run by volunteer teachers</li><li>Literacy drives and activities that encourage regular school attendance</li><li>Support for school admission and access to scholarships and government schemes</li></ul><h2>Our approach</h2><p>We work with parents, volunteer teachers and local schools so that support is regular and practical. Updates about the activities supported by this campaign will be posted here.</p>', 0.00, NULL, 'Delhi NCR', NULL, NULL, 'draft', 0, 0, 'Education Support for Children - Donate', 'Help children from underprivileged families stay in school with school kits, study material and learning support through community learning circles.');
INSERT INTO campaigns (title, slug, program_id, category, short_description, description, goal_amount, image, location, start_date, end_date, status, is_featured, is_urgent, meta_title, meta_description) VALUES ('Food Distribution Drive', 'food-distribution', (SELECT p.id FROM programs p WHERE p.slug = 'food-distribution' LIMIT 1), 'food-distribution', 'Provide dry rations and essential supplies to families facing hunger - daily-wage workers, elderly people living alone and families in crisis.', '<p>For families who depend on daily wages, a lost job, illness or a sudden emergency can quickly mean going without enough food. Through food distribution drives, Prerit Welfare Foundation and its volunteers aim to support families in need with dignity.</p><h2>What your donation would support</h2><ul><li>Dry ration kits - rice, wheat flour, pulses, cooking oil, salt and spices</li><li>Essential supplies for elderly people living alone</li><li>Nutrition support for families with young children</li><li>Hygiene essentials distributed along with rations</li><li>Information about government food security and welfare schemes</li></ul><h2>How distribution works</h2><p>Families are identified with the help of volunteers and community members. Distribution updates and approved expenses will be published on this page.</p>', 0.00, NULL, 'Delhi NCR', NULL, NULL, 'draft', 0, 0, 'Food Distribution Drive - Donate', 'Provide dry rations and essential supplies to families facing hunger - daily-wage workers, elderly people living alone and families in crisis.');

-- Page sections of the fundraising pages
DELETE FROM page_sections WHERE page_id IN (SELECT id FROM pages WHERE slug IN ('campaigns', 'donate', 'start-a-fundraiser', 'financial-transparency', 'utilization-reports'));

SET @p := (SELECT id FROM pages WHERE slug = 'campaigns');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'highlights', NULL, NULL, NULL, NULL, NULL, NULL, '[{"icon":"shield","title":"Verified progress","text":"Only confirmed payments are counted"},{"icon":"file-text","title":"Receipt for every gift","text":"Issued as soon as a donation is verified"},{"icon":"pie-chart","title":"Utilisation published","text":"Approved expenses shared campaign-wise"},{"icon":"users","title":"Volunteer-led work","text":"Relief and support delivered with local volunteers"}]', '{"size":"sm"}', 'tint', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'features', 'how-funds-are-used', 'Where your money goes', 'What campaign donations pay for', 'Every campaign page lists the specific kinds of support it raises money for. Typical uses include:', NULL, NULL, '[{"icon":"package","title":"Dry rations & food","text":"Ration kits with rice, flour, pulses and oil for families facing hunger or displacement."},{"icon":"heart","title":"Hygiene kits","text":"Soap, sanitary pads, toothpaste and daily essentials, especially during floods and emergencies."},{"icon":"umbrella","title":"Water & shelter","text":"Drinking water support, purification tablets, tarpaulins and temporary shelter material."},{"icon":"activity","title":"Medical support","text":"First-aid supplies, health camps and help reaching hospitals and health services."},{"icon":"book","title":"School kits & study material","text":"Bags, notebooks, stationery and books that help children stay in school."},{"icon":"users","title":"Volunteer coordination","text":"Transport and logistics needed to reach families safely and fairly."}]', '{"cols":"3","style":"soft"}', 'white', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'transparency_summary', NULL, 'Live verified totals', 'Every rupee, accounted for', 'These figures are calculated automatically from donations verified with our payment gateway or reconciled by our finance team.', NULL, NULL, NULL, '{"more_link":true,"align":"center"}', 'tint', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'steps', 'process', 'Our process', 'How a campaign works', NULL, NULL, NULL, '[{"icon":"search","title":"Need identified","text":"Our team and volunteers assess the need with the community."},{"icon":"edit","title":"Plan & budget","text":"We decide what support is required and what it will cost."},{"icon":"globe","title":"Campaign published","text":"The campaign page explains the purpose and how funds will be used."},{"icon":"shield","title":"Donations verified","text":"Each payment is confirmed on our server before it is counted."},{"icon":"pie-chart","title":"Updates & utilisation","text":"We post updates and publish approved expenses."}]', NULL, 'white', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'split', NULL, 'Start a fundraiser', 'Raise funds for a cause you care about', 'Members can propose a fundraiser - for a relief need in their area, school kits for a community learning centre or another cause linked to our work.', NULL, 'assets/images/hero/campaigns.svg', '["Our team reviews every proposal before anything is published","Donations go directly to the foundation - never to a personal account","You can follow the review status from your account"]', '{"reverse":true,"image_alt":"Illustration of people supporting a fundraising campaign","buttons":[{"label":"Propose a fundraiser","url":"/start-a-fundraiser/","style":"primary","icon":"flag"}]}', 'cream', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'donation_appeal', 'general-donation', 'Give where it is needed most', 'Not sure which campaign to choose?', 'A general donation lets us respond where the need is greatest - across education, health, women empowerment, environment, skills and relief work.', NULL, NULL, NULL, '{"button_label":"Make a general donation"}', 'tint', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'faqs', 'faqs', 'FAQs', 'Questions about campaigns', NULL, NULL, NULL, '[{"q":"Why does the amount raised only include verified donations?","a":"A donation is counted only after our server has confirmed the payment with Razorpay, or after our finance team has matched an offline donation with the bank statement. Pending or failed payments are never included, so the figures you see are real."},{"q":"What happens if a campaign receives more than it needs?","a":"We will explain in a campaign update how any extra amount is used for closely related work. If you prefer, you can give to the general fund, which is used where it is needed most."},{"q":"Will I get a receipt?","a":"Yes. A receipt is issued for every verified donation and the link is emailed to you. Signed-in donors can also download receipts from their dashboard at any time."},{"q":"Can I donate to a completed campaign?","a":"Completed campaigns no longer accept donations. You can support an active campaign or make a general donation."},{"q":"How do I know how the money was used?","a":"Each campaign page shows its updates and the expenses approved by our finance team. You can also see all campaigns on the Utilisation Reports page."}]', '{"open_first":true}', 'white', 'visible', 80);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'cta', NULL, NULL, 'Every contribution counts', 'Choose a campaign above, or give where it is needed most.', NULL, NULL, NULL, '{"buttons":[{"label":"Donate now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Financial transparency","url":"/financial-transparency/","style":"light","icon":"shield","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 90);

SET @p := (SELECT id FROM pages WHERE slug = 'donate');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'features', 'why-give', 'Why give', 'Why give to Prerit Welfare Foundation', 'Your donation supports practical, community-based work with underprivileged families.', NULL, NULL, '[{"icon":"book","title":"Education & literacy","text":"Learning circles, study material and school support for children who need it most."},{"icon":"activity","title":"Health","text":"Health camps, screenings, awareness and help to reach health services."},{"icon":"users","title":"Women empowerment","text":"Skill training, Self-Help Groups, rights and financial literacy for women."},{"icon":"leaf","title":"Environment","text":"Tree plantation, waste management and sustainable living in communities."},{"icon":"tool","title":"Skills & livelihoods","text":"Vocational and digital training, career guidance and placement support."},{"icon":"life-buoy","title":"Relief in emergencies","text":"Food, hygiene and essential supplies when disaster strikes."}]', '{"cols":"3","style":"card"}', 'tint', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'checklist', 'how-it-is-used', 'Responsible use', 'How your donation is used', 'Donations to a campaign are used for that campaign. General donations support our programmes where the need is greatest.', NULL, NULL, '["Direct support such as rations, hygiene kits, school kits and study material","Health camps, first aid and help reaching medical services","Training material and facilitation for skill development and Self-Help Groups","Transport and logistics to reach families and communities","Approved expenses are published campaign-wise in our utilisation reports","We never sell or share donor information"]', '{"cols":"2"}', 'white', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'steps', 'secure-payments', 'Safe giving', 'How online donations are verified', NULL, NULL, NULL, '[{"icon":"lock","title":"Pay on Razorpay","text":"When online donation is open, you pay on Razorpay''s secure checkout - card, UPI or bank details are never entered on our website."},{"icon":"shield","title":"We verify the payment","text":"Our server checks the payment signature and confirms the amount with Razorpay."},{"icon":"file-text","title":"Receipt issued","text":"A receipt is generated once the payment is verified, and the link is emailed to you."},{"icon":"pie-chart","title":"Track the impact","text":"Follow campaign updates and the expenses we publish in utilisation reports."}]', '{"requires":"online_donations"}', 'cream', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'callout', NULL, NULL, 'Beware of fraud', 'Donate only through this website or to the foundation''s own bank account - never to a personal account or to details shared by an unknown caller. If no bank details are shown on the Donate page, write to us before transferring any money. Our team will never ask for your OTP, PIN or card details over a call or message.', NULL, NULL, NULL, '{"tone":"gold","icon":"alert-triangle","width":"narrow"}', 'white', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'link_cards', 'other-ways', 'Get involved', 'Other ways to give', NULL, NULL, NULL, '[{"icon":"flag","title":"Start a fundraiser","text":"Raise funds for a cause with your friends and family","url":"/start-a-fundraiser/"},{"icon":"users","title":"Volunteer with us","text":"Give your time and skills","url":"/become-a-volunteer/"},{"icon":"id-card","title":"Become a member","text":"Join the foundation and support our mission","url":"/membership/"},{"icon":"briefcase","title":"CSR & partnerships","text":"Write to us to discuss a partnership","url":"/contact/"}]', NULL, 'tint', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'transparency_summary', NULL, 'Transparency', 'Donations verified so far', 'Live totals from verified donations only.', NULL, NULL, NULL, '{"more_link":true,"align":"center"}', 'white', 'visible', 70);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'faqs', 'faqs', 'FAQs', 'Donation questions', NULL, NULL, NULL, '[{"q":"Is it safe to donate online?","a":"Yes. When online donation is open on this page, payments are processed by Razorpay. Your card, UPI or bank details are entered on Razorpay secure checkout and never reach our website. We confirm every payment with Razorpay on our server before counting it."},{"q":"When will I get my receipt?","a":"For online donations, as soon as the payment is verified - usually within a minute. Bank transfers and cheques are receipted after our finance team has matched them with the bank statement. The receipt link is shown on the thank-you page and emailed to you. If you donated while signed in, it is also available in your dashboard."},{"q":"Can I claim a tax deduction under Section 80G?","a":"A deduction under Section 80G is available only if the foundation holds a valid 80G registration. When a registration number is available it is shown on the donation page and printed on the receipt. The deduction is claimed on the basis of the Form 10BE certificate that the foundation issues after filing its statement of donations (Form 10BD), so please give your PAN when donating. Cash donations above ₹2,000 do not qualify for deduction under Section 80G."},{"q":"Can I donate if I am not an Indian citizen or live outside India?","a":"Indian citizens, including those living abroad, can donate from their own funds through Indian bank accounts, cards or UPI. Unless an FCRA registration is listed on our NGO Details page, the foundation cannot accept foreign contributions - donations from foreign nationals (including OCI card holders), foreign companies or any other foreign source - under the Foreign Contribution (Regulation) Act, 2010. If you are unsure, please contact us before donating."},{"q":"Money was deducted but I did not see a thank-you page. What should I do?","a":"Do not worry. If the payment was captured, it will be confirmed automatically when Razorpay notifies our server. If it was not captured, your bank will reverse it as per its usual timelines. You can contact us with your donation reference at any time."},{"q":"Can I donate anonymously?","a":"Yes. Tick the anonymous option on the form and your name will not appear in the public list of supporters. We still need your details to issue a receipt."},{"q":"What is your refund policy?","a":"Please read our Donation & Refund Policy. If you made a mistake, for example a duplicate payment or a wrong amount, contact us quickly with your donation reference."}]', NULL, 'white', 'visible', 80);

SET @p := (SELECT id FROM pages WHERE slug = 'start-a-fundraiser');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'split', NULL, 'Fundraise with us', 'Turn your concern into action', 'Know a community that needs support after a flood, children who need school kits, or families who need rations? Propose a fundraiser and we will help you raise funds responsibly.', NULL, 'assets/images/hero/community.svg', '["Your fundraiser gets its own campaign page on our website","Donations are received directly by the foundation with a receipt for every donor","We publish updates and approved expenses so supporters see how funds are used"]', '{"image_alt":"Illustration of a community coming together","buttons":[{"label":"Propose a fundraiser","url":"#proposal-form","style":"primary","icon":"flag"}]}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'features', 'who-can-propose', 'Eligibility', 'Who can propose a fundraiser', NULL, NULL, NULL, '[{"icon":"user","title":"Members & supporters","text":"Registered users of this website who want to raise funds for a cause linked to our programmes."},{"icon":"users","title":"Volunteers","text":"Volunteers who see a need in the communities they work with."},{"icon":"home","title":"Community representatives","text":"People who can connect us with families or groups that need support."}]', '{"cols":"3","style":"soft"}', 'tint', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'steps', 'process', 'Process', 'How it works', NULL, NULL, NULL, '[{"icon":"edit","title":"Submit a proposal","text":"Describe the cause, the location and an estimated budget using the form below."},{"icon":"search","title":"Review","text":"Our team checks the need, the budget and the people involved, and may call you."},{"icon":"globe","title":"Campaign goes live","text":"Once approved, we prepare and publish the campaign page with you."},{"icon":"share","title":"Share & raise funds","text":"Share the page with your network; donations come directly to the foundation."},{"icon":"pie-chart","title":"Updates & reporting","text":"We post updates and publish the approved expenses."}]', NULL, 'white', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'checklist', 'rules', 'Please read', 'Fundraiser rules', NULL, NULL, NULL, '["All donations must be made through this website or the foundation''s official bank account","Never collect cash or transfers in a personal account","Describe the need honestly - no exaggerated claims, invented numbers or fake urgency","Do not share identifiable photos or details of children or vulnerable people","Funds are used for the purpose described, as decided and recorded by the foundation","The foundation may pause or close a fundraiser that breaks these rules"]', '{"cols":"2"}', 'cream', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'faqs', 'faqs', 'FAQs', 'Fundraiser questions', NULL, NULL, NULL, '[{"q":"Do I need an account to propose a fundraiser?","a":"Yes. Proposals are linked to a registered account so that our team can contact you and you can follow the review status."},{"q":"How long does the review take?","a":"We aim to review proposals within a few working days. Complex proposals may need a call or a visit before a decision."},{"q":"Will the money come to me?","a":"No. Donations are always received by Prerit Welfare Foundation, which uses them for the purpose described and publishes the approved expenses."},{"q":"Can I set my own target amount?","a":"You can suggest a target based on your estimate. Our team reviews it with you before the campaign is published."},{"q":"What if my proposal is not approved?","a":"We will let you know with a short explanation. You are welcome to talk to us about other ways to help."}]', NULL, 'white', 'visible', 60);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'cta', NULL, NULL, 'Prefer to give directly?', 'You can support an active campaign or make a general donation in a few clicks.', NULL, NULL, NULL, '{"buttons":[{"label":"Donate now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"See campaigns","url":"/campaigns/","style":"light","icon":"heart","size":"lg"}]}', 'white', 'visible', 70);

SET @p := (SELECT id FROM pages WHERE slug = 'financial-transparency');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'split', NULL, 'Our commitment', 'Accountability for every rupee', 'Supporters trust us with their money. We earn that trust by publishing verified figures, issuing receipts and showing how campaign funds are used.', NULL, 'assets/images/hero/transparency.svg', '["Totals are calculated live from verified donations only","Refunded donations are removed from every total","Approved campaign expenses are published in utilisation reports","Registration details are shown only after they are verified"]', '{"image_alt":"Illustration representing financial transparency"}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'steps', 'verification', 'Verification', 'How a donation is verified', NULL, NULL, NULL, '[{"icon":"lock","title":"Secure payment","text":"The donor pays on Razorpay secure checkout."},{"icon":"key","title":"Signature check","text":"Our server checks the payment signature with our secret key."},{"icon":"refresh","title":"Gateway confirmation","text":"We fetch the payment from Razorpay and match order, amount and currency."},{"icon":"file-text","title":"Counted & receipted","text":"Only then is the donation counted and its receipt issued."}]', NULL, 'cream', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'checklist', 'practices', 'Good governance', 'Our financial practices', NULL, NULL, NULL, '["Every online payment is verified with the payment gateway before it is counted","Offline donations are counted only after they are matched with the bank statement","Campaign expenses are recorded with supporting bills and approved before publication","The person who records an expense does not approve it","Every change to donations, receipts and expenses is recorded in an audit log","Donor information is kept confidential and never sold or shared"]', '{"cols":"2"}', 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'faqs', 'faqs', 'FAQs', 'Transparency questions', NULL, NULL, NULL, '[{"q":"Why are some figures shown as a dash?","a":"We show only verified data. A dash means nothing has been verified for that item yet - we never show estimates."},{"q":"When do offline donations appear?","a":"Bank transfers, cheques and cash donations appear after our finance team has matched them with the bank statement and recorded them."},{"q":"Where can I see how a campaign used its funds?","a":"Each campaign page lists its approved expenses, and the Utilisation Reports page shows all campaigns together."},{"q":"Can I ask for more information?","a":"Yes. Please write to us through the contact page and we will respond."}]', NULL, 'white', 'visible', 50);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'cta', NULL, NULL, 'Give with confidence', 'Every donation is verified, receipted and accounted for.', NULL, NULL, NULL, '{"buttons":[{"label":"Donate now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Utilisation reports","url":"/utilization-reports/","style":"light","icon":"pie-chart","size":"lg"}]}', 'white', 'visible', 60);

SET @p := (SELECT id FROM pages WHERE slug = 'utilization-reports');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'split', NULL, 'Utilisation', 'How campaign funds are used', 'For every campaign we compare the verified donations received with the expenses approved by our finance team, and publish the details here.', NULL, 'assets/images/hero/reports.svg', '["Expenses are recorded with bills and approved before publication","Each expense shows the date, category, details and amount","Detailed reports are added for completed campaigns"]', '{"reverse":true,"image_alt":"Illustration of reports and charts"}', 'white', 'visible', 10);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 20);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'checklist', 'contents', 'Contents', 'What a utilisation report includes', NULL, NULL, NULL, '["Verified donations received by the campaign","Approved expenses with date, category and details","Updates from the field about the work carried out","Any amount carried forward to related work, with an explanation"]', '{"cols":"2"}', 'tint', 'visible', 30);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'faqs', 'faqs', 'FAQs', 'Utilisation questions', NULL, NULL, NULL, '[{"q":"Why can spending be lower than the amount received?","a":"Expenses are published only after bills are checked and approved, so recent spending may take some time to appear. Work may also still be in progress."},{"q":"Are bills published?","a":"Bills and invoices are kept on record for audit but are not published, to protect the privacy of suppliers and beneficiaries."},{"q":"Who approves expenses?","a":"Expenses are approved by a different staff member from the one who recorded or last edited them, and every approval is logged with the approver''s name."}]', NULL, 'white', 'visible', 40);
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES (@p, 'cta', NULL, NULL, 'Support an active campaign', 'See where help is needed now.', NULL, NULL, NULL, '{"buttons":[{"label":"See campaigns","url":"/campaigns/","style":"donate","icon":"heart","size":"lg"},{"label":"Financial transparency","url":"/financial-transparency/","style":"light","icon":"shield","size":"lg"}]}', 'white', 'visible', 50);

-- ---------------------------------------------------------------------
-- Module M4
-- ---------------------------------------------------------------------
-- =====================================================================
-- M4 Recruitment & support: page sections for the application pages.
-- Re-runnable: removes and re-inserts only the sections of these pages.
-- Content describes the foundation's work and process only (no figures).
-- =====================================================================
SET NAMES utf8mb4;
DELETE FROM page_sections WHERE page_id IN (SELECT id FROM pages WHERE slug IN ('become-a-volunteer', 'volunteer-teacher', 'volunteer-staff', 'internships', 'membership', 'scholarship', 'request-help', 'application-status'));

UPDATE pages SET banner_buttons = '[{"label":"Apply now","url":"#apply","style":"donate","icon":"heart"},{"label":"Check status","url":"/application-status/","style":"light","icon":"search"}]' WHERE slug = 'become-a-volunteer';
UPDATE pages SET banner_buttons = '[{"label":"Apply to teach","url":"#apply","style":"donate","icon":"book"},{"label":"Safeguarding","url":"#safeguarding","style":"light","icon":"shield"}]' WHERE slug = 'volunteer-teacher';
UPDATE pages SET banner_buttons = '[{"label":"Open positions","url":"#open-positions","style":"donate","icon":"briefcase"},{"label":"Apply now","url":"#apply","style":"light","icon":"send"}]' WHERE slug = 'volunteer-staff';
UPDATE pages SET banner_buttons = '[{"label":"Apply for an internship","url":"#apply","style":"donate","icon":"award"}]' WHERE slug = 'internships';
UPDATE pages SET banner_buttons = '[{"label":"Apply for membership","url":"#apply","style":"donate","icon":"id-card"}]' WHERE slug = 'membership';
UPDATE pages SET banner_buttons = '[{"label":"Apply for a scholarship","url":"#apply","style":"donate","icon":"graduation"}]' WHERE slug = 'scholarship';
UPDATE pages SET banner_buttons = '[{"label":"Request help","url":"#apply","style":"donate","icon":"life-buoy"}]' WHERE slug = 'request-help';

-- Certificate signatory (Admin > Settings). Empty name = the title alone is printed.
INSERT INTO website_settings (setting_key, setting_value) VALUES ('certificate_signatory_name', ''), ('certificate_signatory_title', 'Authorised Signatory')
    ON DUPLICATE KEY UPDATE setting_key = setting_key;

-- become-a-volunteer
SET @p := (SELECT id FROM pages WHERE slug = 'become-a-volunteer');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'why-volunteer', 'Why volunteer', 'Give a few hours, help build a better tomorrow', 'Volunteers are at the heart of Prerit Welfare Foundation. Whether you are a student, a working professional, a homemaker or retired, your time and skills can make a real difference to children, women and families in underserved communities.', '<p>We organise literacy drives and community learning circles, health awareness camps, tree plantation and cleanliness drives, food and essential supply distribution, and relief work during emergencies. Volunteers help plan, run and document these activities alongside our team.</p>', 'assets/images/hero/volunteer.svg', '["Work on real community programmes","Choose activities that match your skills and time","Learn with the support of a coordinator","Meet people who care about change"]', '{"image_alt":"Illustration of volunteers working together in the community","buttons":[{"label":"Apply now","url":"#apply","style":"primary","icon":"send"},{"label":"Read the Volunteer Policy","url":"/volunteer-policy/","style":"outline","icon":"file-text"}]}', 'white', 'visible', 10),
(@p, 'features', 'roles', 'Where you can help', 'Volunteer roles and areas', 'Pick one or more areas when you apply. Our coordinator will match you with activities near you or online.', NULL, NULL, '[{"icon":"book","title":"Education & literacy","text":"Support community learning circles, help children with reading and homework, and assist at study material distribution drives."},{"icon":"heart","title":"Health awareness","text":"Help organise health camps and screenings, run awareness sessions on hygiene and prevention, and guide families to referral support."},{"icon":"users","title":"Women empowerment","text":"Assist skill development sessions, Self-Help Group meetings and workshops on rights and financial literacy."},{"icon":"leaf","title":"Environment","text":"Join tree plantation, waste management and cleanliness drives, and spread awareness about sustainable living."},{"icon":"package","title":"Food & relief","text":"Pack and distribute food and essential supplies, and support relief coordination during floods and other emergencies."},{"icon":"megaphone","title":"Campaigns, media & events","text":"Design posters, write stories, take photographs, manage social media and help run awareness events and campaigns."}]', '{"cols":"3"}', 'tint', 'visible', 20),
(@p, 'checklist', 'what-we-expect', 'What we expect', 'What we expect from you', 'Volunteering is a commitment to the people we serve. We ask every volunteer to:', NULL, NULL, '["Keep to the activities and times you sign up for","Tell your coordinator early if you cannot attend","Attend the orientation before your first activity","Work as part of the team and follow instructions on site","Share photos or stories only through the foundation, and only with consent","Give honest feedback so that we can keep improving"]', '{"cols":"2"}', 'white', 'visible', 30),
(@p, 'highlights', 'what-you-get', 'What you get', 'What volunteering with us offers you', 'We value every hour our volunteers give. In return we offer:', NULL, NULL, '[{"icon":"award","title":"Certificate","text":"A volunteering certificate after verified service, issued by the foundation."},{"icon":"users","title":"Orientation & guidance","text":"An orientation session and a coordinator you can reach for support."},{"icon":"trending-up","title":"Real experience","text":"Hands-on experience of community work, planning and teamwork."},{"icon":"heart","title":"A community","text":"People who care about a fair and inclusive society."}]', NULL, 'cream', 'visible', 40),
(@p, 'steps', 'process', 'How it works', 'Your journey as a volunteer', NULL, NULL, NULL, '[{"icon":"send","title":"Apply online","text":"Fill in the form with your interests, skills and availability. You receive a reference number by email."},{"icon":"phone","title":"Talk to our coordinator","text":"We call or email you to understand your interests and find the right activity."},{"icon":"book","title":"Orientation","text":"A short orientation on our programmes, the code of conduct and child safeguarding."},{"icon":"heart","title":"Start volunteering","text":"Join activities in the field or online, with a coordinator to guide you."},{"icon":"award","title":"Certificate","text":"After verified service, your certificate appears in your dashboard."}]', '{"align":"center"}', 'white', 'visible', 50),
(@p, 'features', 'code-of-conduct', 'Code of conduct', 'Code of conduct highlights', 'The full Volunteer Policy and Child Protection Policy apply to every volunteer. These are the points we ask everyone to remember.', NULL, NULL, '[{"title":"Children''s safety first","text":"Never be alone with a child out of sight of others, and report any concern to your coordinator immediately."},{"title":"Respect and dignity","text":"Treat every person with respect, regardless of caste, religion, gender, disability or economic status."},{"title":"Confidentiality","text":"Do not share the personal details, photos or stories of the people we work with without their consent."},{"title":"No money in our name","text":"Never ask for or accept money, gifts or donations on behalf of the foundation unless formally authorised."},{"title":"Honest representation","text":"Describe the foundation''s work accurately and do not make promises on its behalf."},{"title":"Safe conduct","text":"No alcohol, tobacco or drugs during activities. Follow safety instructions and the law at all times."}]', '{"cols":"3","style":"numbered"}', 'tint', 'visible', 60),
(@p, 'link_cards', 'policies', 'Read before you apply', 'Policies for volunteers', NULL, NULL, NULL, '[{"icon":"file-text","title":"Volunteer Policy","text":"Roles, expectations and the code of conduct","url":"/volunteer-policy/"},{"icon":"shield","title":"Child Protection Policy","text":"How we keep children safe","url":"/child-protection-policy/"},{"icon":"lock","title":"Privacy Policy","text":"How we use your information","url":"/privacy-policy/"},{"icon":"search","title":"Application status","text":"Track your application","url":"/application-status/"}]', '{"size":"sm"}', 'white', 'visible', 70),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 80),
(@p, 'faqs', 'faqs', 'FAQ', 'Questions about volunteering', NULL, NULL, NULL, '[{"q":"Who can volunteer with the foundation?","a":"Anyone who shares our values and can give some time regularly - students, working professionals, homemakers and retired people. Our coordinator will discuss which activities suit your skills, age and availability."},{"q":"Do I need previous experience?","a":"No. Most activities need enthusiasm and reliability more than experience. You attend an orientation first and work alongside experienced team members."},{"q":"How much time do I need to give?","a":"As much as you can offer regularly - a few hours a month, on weekends or on weekdays. Choose your availability in the form. Some roles, such as design or social media, can also be done online."},{"q":"Is volunteering a paid role?","a":"Volunteering is not a paid role. If you are looking for a job with the foundation, please see the Volunteer Staff Application page (Job Apply in the top bar)."},{"q":"Will I receive a certificate?","a":"Yes. After verified service, volunteers can receive a certificate issued by the foundation. Approved certificates appear in your dashboard when you have a website account with the same email address."},{"q":"How do I check my application?","a":"Use the reference number from your acknowledgement email on the Application Status page, together with the email address or mobile number you used when applying."}]', '{"width":"narrow","open_first":true}', 'cream', 'visible', 90),
(@p, 'apply_cards', 'other-ways', 'Get involved', 'Other ways to get involved', 'Teach, intern, work with us or become a member.', NULL, NULL, NULL, '{"hide_current":true}', 'white', 'visible', 100),
(@p, 'cta', NULL, NULL, 'Cannot volunteer right now?', 'You can still support our programmes with a donation, or start a fundraiser for a cause you care about.', NULL, NULL, NULL, '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Start a fundraiser","url":"/start-a-fundraiser/","style":"light","size":"lg"}]}', 'white', 'visible', 110);

-- volunteer-teacher
SET @p := (SELECT id FROM pages WHERE slug = 'volunteer-teacher');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'who-can-teach', 'Volunteer Teacher Programme', 'Teach, mentor and inspire', 'Through our education work, children and adult learners in underserved communities get help with reading, school subjects and life skills. Volunteer teachers make this possible.', '<p>You do not need to be a professional teacher. If you can explain a subject clearly, are patient with learners and can commit regular time, we would love to hear from you.</p>', 'assets/images/hero/teacher.svg', '["College students and graduates","Working professionals and retired teachers","Homemakers with time to share","Anyone who enjoys teaching in Hindi, English or a local language"]', '{"image_alt":"Illustration of a volunteer teacher with learners","buttons":[{"label":"Apply to teach","url":"#apply","style":"primary","icon":"send"},{"label":"Child Protection Policy","url":"/child-protection-policy/","style":"outline","icon":"shield"}]}', 'white', 'visible', 10),
(@p, 'features', 'subjects', 'What you can teach', 'Subjects and learners', 'Choose the subjects and age groups you are comfortable with. Classes range from primary school children to adult learners.', NULL, NULL, '[{"icon":"book","title":"Literacy & languages","text":"Reading, writing and spoken Hindi and English for children and adult learners."},{"icon":"target","title":"Mathematics & science","text":"Foundational numeracy, school mathematics and science concepts explained simply."},{"icon":"globe","title":"Social studies","text":"History, geography and civics, connected to everyday life."},{"icon":"monitor","title":"Computers & digital skills","text":"Computer basics, safe internet use and digital literacy."},{"icon":"star","title":"Arts, crafts & life skills","text":"Creativity, communication, hygiene and confidence-building activities."},{"icon":"graduation","title":"Study & career guidance","text":"Doubt-clearing for older students and guidance about further studies and careers."}]', '{"cols":"3"}', 'tint', 'visible', 20),
(@p, 'split', 'in-person-online', 'In person & online', 'Teach where you are', 'There are two ways to teach with us, and you can choose both.', '<p><strong>In person:</strong> at community learning circles and study sessions organised by our team, always as part of a group session.</p><p><strong>Online:</strong> live sessions for groups of learners, arranged by our coordinators at agreed times.</p>', 'assets/images/programs/education.svg', '["Choose weekday or weekend slots","Group sessions - never private one-to-one tuition","Guidance and support from our coordinators","Regular feedback from learners and the team"]', '{"reverse":true,"image_alt":"Illustration of learning sessions"}', 'white', 'visible', 30),
(@p, 'checklist', 'safeguarding', 'Safeguarding', 'Safeguarding and background checks', 'Children''s safety is our first priority. Every volunteer teacher completes these steps before working with children - without exception.', NULL, NULL, '["Identity verification with a government photo ID","Reference checks before placement","A briefing on our Child Protection Policy and code of conduct","Signed acknowledgement of the Child Protection Policy","Teaching only in group settings or coordinator-arranged online sessions","No private contact with children outside sessions"]', '{"cols":"2"}', 'cream', 'visible', 40),
(@p, 'steps', 'process', 'How it works', 'From application to your first class', NULL, NULL, NULL, '[{"icon":"send","title":"Apply online","text":"Tell us your subjects, classes, languages and availability. A CV is optional."},{"icon":"message","title":"Conversation","text":"A short conversation, and sometimes a demo session, with our education coordinator."},{"icon":"shield","title":"Verification","text":"Identity and reference checks and the child protection briefing."},{"icon":"book","title":"Orientation","text":"Learn about our learners, teaching approach and session plans."},{"icon":"graduation","title":"Start teaching","text":"You are matched with a learning group, in person or online."}]', '{"align":"center"}', 'white', 'visible', 50),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'faqs', 'faqs', 'FAQ', 'Questions about teaching with us', NULL, NULL, NULL, '[{"q":"Do I need a teaching degree?","a":"No. A teaching qualification is welcome but not required. What matters most is subject knowledge, patience and reliability."},{"q":"Can I teach online?","a":"Yes. Choose \\"Online\\" or \\"Both\\" in the form. Online sessions are arranged by our coordinators for groups of learners at agreed times."},{"q":"How much time should I give?","a":"Regular, predictable time matters most for learners. Choose a slot you can keep - weekly, on weekends or on weekdays - and tell us in the form."},{"q":"Which language will I teach in?","a":"Tell us the languages you can teach in. We try to match you with learners who are comfortable in the same language."},{"q":"Why are background checks needed?","a":"Because volunteer teachers work with children. Verification and a child protection briefing are required for everyone and help keep both children and volunteers safe."},{"q":"Will I receive a certificate?","a":"Yes. After verified teaching service a certificate can be issued by the foundation. Approved certificates appear in your dashboard."}]', '{"width":"narrow","open_first":true}', 'cream', 'visible', 70),
(@p, 'apply_cards', 'other-ways', 'Get involved', 'Other ways to get involved', NULL, NULL, NULL, NULL, '{"hide_current":true}', 'white', 'visible', 80);

-- volunteer-staff
SET @p := (SELECT id FROM pages WHERE slug = 'volunteer-staff');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'work-with-us', 'Work with us', 'Build a career with purpose', 'From programme coordination and field work to communication and administration, our team turns plans into change on the ground.', '<p>We look for people who care about communities, work honestly and enjoy solving practical problems. Open positions are listed on this page when available - and you can always send a general application.</p>', 'assets/images/hero/career.svg', '["Meaningful work with communities","A committed, collaborative team","Learning across programmes","A respectful and inclusive workplace"]', '{"image_alt":"Illustration of a team at work","buttons":[{"label":"See open positions","url":"#open-positions","style":"primary","icon":"briefcase"},{"label":"Apply now","url":"#apply","style":"outline","icon":"send"}]}', 'white', 'visible', 10),
(@p, 'features', 'values', 'Our values', 'What we value in our team', NULL, NULL, NULL, '[{"icon":"shield","title":"Integrity","text":"Honest work, careful use of resources and accountability to the people we serve."},{"icon":"heart","title":"Dignity & respect","text":"Everyone is treated with respect - colleagues, volunteers and communities alike."},{"icon":"users","title":"Community first","text":"We listen to communities and design our work with them, not for them."},{"icon":"umbrella","title":"Safeguarding","text":"The safety of children and vulnerable people comes before everything else."},{"icon":"layers","title":"Teamwork","text":"Staff and volunteers work as one team, sharing knowledge and responsibility."},{"icon":"trending-up","title":"Learning","text":"We reflect on what works, learn from mistakes and keep improving."}]', '{"cols":"3","style":"soft"}', 'tint', 'visible', 20),
(@p, 'steps', 'hiring', 'How hiring works', 'A simple, transparent process', NULL, NULL, NULL, '[{"icon":"send","title":"Apply online","text":"Choose an open position or send a general application with your resume and cover letter."},{"icon":"search","title":"Screening","text":"Every application is reviewed against the role''s requirements."},{"icon":"message","title":"Interview","text":"Shortlisted applicants are invited for a conversation in person, by phone or by video call."},{"icon":"shield","title":"Reference checks","text":"We verify references and complete safeguarding checks for roles that involve children."},{"icon":"check-circle","title":"Offer & onboarding","text":"Selected candidates receive the offer in writing and an orientation."}]', '{"align":"center"}', 'white', 'visible', 30),
(@p, 'callout', 'no-fee', NULL, 'We do not charge any fee to apply', 'Applying for a job or internship with Prerit Welfare Foundation is always free. If anyone asks you for money for a job or internship in our name, please report it using the Report Fraud page or write to us.', NULL, NULL, NULL, '{"tone":"gold","icon":"alert-triangle","width":"narrow"}', 'white', 'visible', 40),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'faqs', 'faqs', 'FAQ', 'Questions about working with us', NULL, NULL, NULL, '[{"q":"Do I have to pay any fee to apply or to be selected?","a":"No. We never charge any fee at any stage of hiring. If anyone asks you for money for a job or internship in our name, please report it on our Report Fraud page."},{"q":"There is no suitable open position. Can I still apply?","a":"Yes. Choose \\"General application\\" in the form. We keep your profile and contact you if a suitable role opens."},{"q":"How will I know the outcome?","a":"We email you when the status of your application changes. You can also check it any time on the Application Status page with your reference number."},{"q":"Is my resume kept private?","a":"Yes. Resumes are stored privately, seen only by authorised staff and used only for recruitment."},{"q":"Can I apply for more than one role?","a":"Yes. Please submit a separate application for each role so that each one can be reviewed properly."},{"q":"Do you offer remote work?","a":"When a role can be done remotely or partly remotely, this is mentioned in the position description."}]', '{"width":"narrow"}', 'cream', 'visible', 60),
(@p, 'apply_cards', 'other-ways', 'Get involved', 'Other ways to get involved', NULL, NULL, NULL, NULL, '{"hide_current":true}', 'white', 'visible', 70);

-- internships
SET @p := (SELECT id FROM pages WHERE slug = 'internships');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'learn-by-doing', 'Internships', 'Learn by doing', 'Our internships give students and young graduates practical experience of how community programmes are planned, run and documented.', '<p>You work on a defined project with a mentor from our team - in the field, online or both - and finish with a piece of work you can be proud of.</p>', 'assets/images/hero/career.svg', '["A defined project and a mentor","In person, online or both","Durations that suit your course","Certificate on completion after evaluation"]', '{"image_alt":"Illustration of interns learning on a project","buttons":[{"label":"Apply now","url":"#apply","style":"primary","icon":"send"}]}', 'white', 'visible', 10),
(@p, 'features', 'areas', 'Internship areas', 'Areas you can choose', 'Choose one area in the application form. Each internship is built around a project in that area.', NULL, NULL, '[{"icon":"book","title":"Education","text":"Support learning circles, prepare learning material and help document learning outcomes."},{"icon":"heart","title":"Health","text":"Help plan health camps and awareness sessions, and prepare simple health information material."},{"icon":"users","title":"Women empowerment","text":"Support skill development sessions, Self-Help Group activities and financial literacy workshops."},{"icon":"leaf","title":"Environment","text":"Work on plantation, waste management and sustainable living awareness projects."},{"icon":"megaphone","title":"Communication & fundraising","text":"Create content for social media, write stories with consent and support fundraising campaigns."},{"icon":"file-text","title":"Research & documentation","text":"Conduct surveys, analyse information and write reports on our programmes."}]', '{"cols":"3"}', 'tint', 'visible', 20),
(@p, 'checklist', 'learning-outcomes', 'Learning outcomes', 'What you will learn', NULL, NULL, NULL, '["How community needs are identified and programmes are planned","Organising drives, camps and awareness sessions in the field","Writing reports, stories and case documentation - with consent","Communication and social media for a cause","Working in a team with deadlines and feedback","The ethics of working with communities and children"]', '{"cols":"2"}', 'white', 'visible', 30),
(@p, 'highlights', 'format', 'Duration & format', 'How internships are organised', NULL, NULL, NULL, '[{"icon":"clock","title":"Duration","text":"Choose from 4 weeks to 6 months, depending on your course."},{"icon":"globe","title":"Mode","text":"In person, online or a mix of both."},{"icon":"calendar","title":"Start date","text":"Tell us your preferred date; we confirm it after the interview."},{"icon":"award","title":"Certificate","text":"Issued on completion, after a final evaluation of your work."}]', NULL, 'cream', 'visible', 40),
(@p, 'steps', 'process', 'How it works', 'From application to certificate', NULL, NULL, NULL, '[{"icon":"send","title":"Apply online","text":"Choose an area, duration, mode and preferred start date."},{"icon":"message","title":"Interview","text":"A short conversation to agree on a project, a mentor and a schedule."},{"icon":"book","title":"Orientation","text":"Introduction to our work, the code of conduct and child safeguarding."},{"icon":"target","title":"Your project","text":"Work on your project with regular check-ins and feedback from your mentor."},{"icon":"award","title":"Evaluation & certificate","text":"A final review of your work, then your certificate in your dashboard."}]', '{"align":"center"}', 'white', 'visible', 50),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'faqs', 'faqs', 'FAQ', 'Questions about internships', NULL, NULL, NULL, '[{"q":"Who can apply?","a":"Students of any recognised college or institute, and recent graduates who want practical experience in the social sector."},{"q":"Can the internship count towards my college requirements?","a":"Tell us about your college''s requirements in the application. If we can meet them, we confirm the details - including any letters or evaluation formats your college needs - before you start."},{"q":"Is the internship paid?","a":"Our internships are learning opportunities. If an internship includes any stipend or reimbursement, this is stated clearly in writing before you start."},{"q":"Can I do the internship online?","a":"Yes, many projects can be done online or in a hybrid format. Choose your preferred mode in the form."},{"q":"How many hours a week are expected?","a":"It depends on the project and its duration. We agree on a schedule with you after the interview."},{"q":"When do I receive my certificate?","a":"After you complete the internship and a final evaluation of your work. Approved certificates appear in your dashboard."}]', '{"width":"narrow","open_first":true}', 'cream', 'visible', 70),
(@p, 'apply_cards', 'other-ways', 'Get involved', 'Other ways to get involved', NULL, NULL, NULL, NULL, '{"hide_current":true}', 'white', 'visible', 80);

-- membership
SET @p := (SELECT id FROM pages WHERE slug = 'membership');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'why-member', 'Why become a member', 'Be part of the foundation', 'Members are the foundation''s closest supporters - people who share our belief in a fair and inclusive society and want to stay involved in our work.', '<p>As a member you are kept informed about our programmes, invited to take part in activities and events, and can share ideas that help shape what we do.</p>', 'assets/images/hero/membership.svg', '["Stay closely connected with our work","Take part in activities, drives and events","Share ideas and feedback with our team","Your membership number and record in your account"]', '{"image_alt":"Illustration of a membership card","buttons":[{"label":"Apply for membership","url":"#apply","style":"primary","icon":"id-card"}]}', 'white', 'visible', 10),
(@p, 'features', 'types', 'Membership types', 'Choose the membership that suits you', 'Every application is reviewed and approved by the foundation. Choose the type when you apply.', NULL, NULL, '[{"icon":"user","title":"General Member","text":"For anyone who shares our goals and wants to stay closely involved in our work."},{"icon":"heart","title":"Volunteer Member","text":"For volunteers who give their time regularly to our programmes and drives."},{"icon":"graduation","title":"Student Member","text":"For school and college students who want to learn and contribute."},{"icon":"award","title":"Life Member","text":"For long-term supporters who wish to stay associated with the foundation."}]', '{"cols":"4"}', 'tint', 'visible', 20),
(@p, 'checklist', 'responsibilities', 'Responsibilities', 'What we ask of our members', NULL, NULL, NULL, '["Support the objectives and values of the foundation","Follow our policies, including the Child Protection Policy and code of conduct","Never collect money or make commitments in the foundation''s name without written authorisation","Respect the privacy and dignity of the communities we serve","Keep your contact details up to date in your account","Share feedback and concerns through the proper channels"]', '{"cols":"2"}', 'white', 'visible', 30),
(@p, 'steps', 'process', 'How it works', 'Becoming a member', NULL, NULL, NULL, '[{"icon":"user","title":"Create an account","text":"Register on the website, or log in if you already have an account."},{"icon":"send","title":"Apply","text":"Choose a membership type and tell us a little about yourself."},{"icon":"search","title":"Review","text":"Our team reviews your application and may contact you."},{"icon":"id-card","title":"Membership number","text":"On approval you receive a membership number in your account."},{"icon":"heart","title":"Stay involved","text":"Take part in our activities, events and volunteering."}]', '{"align":"center"}', 'cream', 'visible', 40),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'faqs', 'faqs', 'FAQ', 'Questions about membership', NULL, NULL, NULL, '[{"q":"Why do I need an account to apply?","a":"Your account keeps your membership status, membership number and any certificates together, and lets you update your details yourself."},{"q":"Is any payment collected with the application?","a":"No payment is collected through this application form. If you have questions about membership, please contact us."},{"q":"How long is membership valid?","a":"Once your membership is approved, its validity is shown on this page and in your dashboard."},{"q":"What is the difference between a volunteer and a member?","a":"Volunteers give time to specific activities. Members are associated with the foundation more broadly. Many members also volunteer - you are welcome to do both."},{"q":"Can I end my membership?","a":"Yes. Write to us from the email address of your account and we will update your membership."}]', '{"width":"narrow"}', 'white', 'visible', 60),
(@p, 'cta', NULL, NULL, 'More ways to stand with us', 'Membership is one way to support the foundation. You can also volunteer your time or support a campaign.', NULL, NULL, NULL, '{"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","icon":"users","size":"lg"},{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"}]}', 'white', 'visible', 70);

-- scholarship
SET @p := (SELECT id FROM pages WHERE slug = 'scholarship');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'about-scholarship', 'Scholarship support', 'Helping deserving students continue their education', 'Financial difficulty should not force a student to stop studying. Our scholarship support helps students from families with limited means to continue their education.', '<p>Support is considered on the basis of need and merit, and is always subject to available funds and verification. Every application is reviewed carefully and in confidence.</p>', 'assets/images/hero/scholarship.svg', '["For school and college students","Based on need, merit and verification","Applications reviewed in confidence","Free to apply - no fee at any stage"]', '{"image_alt":"Illustration of a student with books","buttons":[{"label":"Apply now","url":"#apply","style":"primary","icon":"send"}]}', 'white', 'visible', 10),
(@p, 'checklist', 'who-can-apply', 'Eligibility', 'Who can apply', 'Students who meet the following can apply. A parent or guardian should apply for students under 18 years.', NULL, NULL, '["Currently enrolled in a school, college or recognised course","From a family with limited annual income","Needs help to continue or complete their education","Able to share documents for verification when asked"]', '{"cols":"2"}', 'tint', 'visible', 20),
(@p, 'features', 'support', 'What support may include', 'How scholarship support can help', 'The support offered depends on the student''s needs, available funds and verification of the application.', NULL, NULL, '[{"icon":"graduation","title":"Fees","text":"Help with school, college or course fees."},{"icon":"book","title":"Books & study material","text":"Textbooks, guides and other study material."},{"icon":"package","title":"Uniforms & essentials","text":"Uniforms, school bags, shoes and stationery."},{"icon":"monitor","title":"Digital learning","text":"Help with access to digital learning, where possible."}]', '{"cols":"4"}', 'white', 'visible', 30),
(@p, 'checklist', 'documents', 'Documents', 'Documents to keep ready', 'You can upload one supporting document with the form. We may ask for others during verification. Please do not upload Aadhaar or bank card images.', NULL, NULL, '["Latest marksheet or report card","Fee receipt or admission letter for the current year","Income certificate or a declaration of family income","Student identity card of the school or college","Contact details of the parent or guardian"]', '{"cols":"2"}', 'cream', 'visible', 40),
(@p, 'steps', 'selection', 'Selection process', 'How applications are reviewed', NULL, NULL, NULL, '[{"icon":"send","title":"Apply online","text":"Fill in the student, family and education details. You receive a reference number."},{"icon":"search","title":"Initial review","text":"Our team checks that the application is complete and eligible."},{"icon":"phone","title":"Verification","text":"We may call the family, ask for documents or contact the school or college."},{"icon":"check-circle","title":"Decision","text":"Decisions depend on need, merit and available funds. We inform you of the outcome."},{"icon":"heart","title":"Support & follow-up","text":"If support is approved, we stay in touch about the student''s progress."}]', '{"align":"center"}', 'white', 'visible', 50),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'faqs', 'faqs', 'FAQ', 'Questions about scholarships', NULL, NULL, NULL, '[{"q":"Is there any fee to apply?","a":"No. Applying is free. Never pay anyone who offers to help you get a scholarship from us - please report such requests on our Report Fraud page."},{"q":"Who decides which students receive support?","a":"Our team reviews each application on the basis of need, merit and verification. Support also depends on the funds available at the time."},{"q":"How will I know the result?","a":"We email you when the status changes. You can also check it any time on the Application Status page with your reference number."},{"q":"Can a student apply again next year?","a":"Yes. A new application can be made for each academic year, and it is reviewed afresh."},{"q":"Is our information kept private?","a":"Yes. Applications and documents are stored privately and seen only by authorised staff working on scholarships."}]', '{"width":"narrow"}', 'cream', 'visible', 70),
(@p, 'cta', NULL, NULL, 'Looking for other support?', 'Our welfare schemes directory explains government scholarships and schemes, and you can request other kinds of help from us.', NULL, NULL, NULL, '{"buttons":[{"label":"Explore welfare schemes","url":"/schemes/","style":"light","icon":"list","size":"lg"},{"label":"Request help","url":"/request-help/","style":"donate","icon":"life-buoy","size":"lg"}]}', 'white', 'visible', 80);

-- request-help
SET @p := (SELECT id FROM pages WHERE slug = 'request-help');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'we-are-here', 'We are here to help', 'Asking for help takes courage', 'If you or someone you know is going through a difficult time, please tell us. We listen with respect, keep your information confidential and do our best to help - or to guide you to the right support.', '<p>We can consider requests for education support, health and medical help, women''s empowerment and Self-Help Group support, skill training and livelihood, food and ration support, and relief after disasters.</p>', 'assets/images/hero/help.svg', '["No fee - ever","Treated with dignity and in confidence","You can apply for someone else, with their consent","Guidance on government welfare schemes"]', '{"image_alt":"Illustration of a helping hand","buttons":[{"label":"Request help","url":"#apply","style":"primary","icon":"life-buoy"}]}', 'white', 'visible', 10),
(@p, 'features', 'types-of-support', 'Types of support', 'How we may be able to help', 'Choose the type of support that fits best. If you are not sure, choose "Other" and describe the situation.', NULL, NULL, '[{"icon":"book","title":"Education support","text":"Study material, school support and guidance for children and young people.","link":"/request-help/?category=education#apply","link_label":"Request education support"},{"icon":"heart","title":"Health & medical","text":"Health camps, screenings, awareness and help to reach referral support.","link":"/request-help/?category=health#apply","link_label":"Request health support"},{"icon":"users","title":"Women & SHG support","text":"Skill development, Self-Help Group formation and rights awareness for women.","link":"/request-help/?category=women#apply","link_label":"Request this support"},{"icon":"tool","title":"Skills & livelihood","text":"Vocational and digital training, career guidance and livelihood support.","link":"/request-help/?category=livelihood#apply","link_label":"Request this support"},{"icon":"package","title":"Food & ration","text":"Food and essential supplies for families facing hardship.","link":"/request-help/?category=food#apply","link_label":"Request food support"},{"icon":"life-buoy","title":"Disaster relief","text":"Relief support after floods and other emergencies, coordinated with volunteers.","link":"/request-help/?category=disaster#apply","link_label":"Request relief support"}]', '{"cols":"3"}', 'tint', 'visible', 20),
(@p, 'steps', 'what-happens-next', 'What happens next', 'After you send a request', NULL, NULL, NULL, '[{"icon":"send","title":"You send the request","text":"Online, in a few minutes. You receive a reference number."},{"icon":"phone","title":"We call you","text":"A member of our team calls to understand your situation better."},{"icon":"shield","title":"Verification","text":"We may ask for a document or visit - only to confirm the need."},{"icon":"heart","title":"Support or guidance","text":"Where we can help, we explain how. Where we cannot, we try to guide you elsewhere."}]', '{"align":"center"}', 'white', 'visible', 30),
(@p, 'checklist', 'privacy', 'Your privacy', 'How we protect your information', NULL, NULL, NULL, '["Only the staff and volunteers working on your request see your details","Documents are stored privately and are never published","We never publish your photo or story without your consent","You can ask us to correct or delete your information"]', '{"cols":"2"}', 'cream', 'visible', 40),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'faqs', 'faqs', 'FAQ', 'Common questions', NULL, NULL, NULL, '[{"q":"Does sending a request mean I will receive help?","a":"Sending a request does not guarantee support, but every request is read with care. If we cannot help directly, we try to guide you to other support, such as government welfare schemes."},{"q":"Do I have to pay anything?","a":"Never. We do not charge any fee for requests. If anyone asks you for money in our name, please report it on our Report Fraud page."},{"q":"I do not have an email address. Can I still apply?","a":"Yes. Email is optional - we will contact you by phone. Keep your reference number safe; you can check the status with it and your mobile number."},{"q":"Can I apply on behalf of someone else?","a":"Yes, with their permission. Fill in their details, give a phone number where they or you can be reached, and mention your relationship in the description."},{"q":"What if it is an emergency?","a":"This form is not for emergencies. In a medical or other emergency, please call the national emergency number 112 first."}]', '{"width":"narrow"}', 'white', 'visible', 60),
(@p, 'status_lookup', 'check-status', 'Already sent a request?', 'Check the status of your request', 'Enter your reference number and the mobile number or email address you gave.', NULL, NULL, NULL, '{"width":"narrow"}', 'tint', 'visible', 70),
(@p, 'link_cards', 'more-support', 'More support', 'Other places to look', NULL, NULL, NULL, '[{"icon":"list","title":"Welfare schemes","text":"Government schemes and how to apply","url":"/schemes/"},{"icon":"graduation","title":"Scholarship","text":"Support for students to continue studying","url":"/scholarship/"},{"icon":"search","title":"Application status","text":"Track your request with your reference number","url":"/application-status/"},{"icon":"mail","title":"Contact us","text":"Write to us with any question","url":"/contact/"}]', NULL, 'white', 'visible', 80);

-- application-status
SET @p := (SELECT id FROM pages WHERE slug = 'application-status');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'features', 'statuses', 'Status guide', 'What each status means', 'Volunteer, teacher, staff and internship applications move through these stages. Scholarship and help requests use similar steps.', NULL, NULL, '[{"icon":"inbox","title":"Submitted","text":"Your application has been received and is waiting for review."},{"icon":"search","title":"Under review","text":"Our team is reading your application."},{"icon":"message","title":"Information requested","text":"We need a little more information - see the message on your status page or in your email."},{"icon":"star","title":"Shortlisted","text":"Your application has moved to the next step."},{"icon":"calendar","title":"Interview scheduled","text":"A conversation has been arranged - the date and mode are shown with your status."},{"icon":"check-circle","title":"Selected & onboarding","text":"You have been selected and are completing orientation and verification."},{"icon":"heart","title":"Active","text":"You are now active with the foundation. Welcome!"},{"icon":"info","title":"Not selected","text":"We could not take this application forward this time. You are welcome to apply again later."}]', '{"cols":"4","style":"soft"}', 'tint', 'visible', 20),
(@p, 'faqs', 'faqs', 'Help', 'Questions about your status', NULL, NULL, NULL, '[{"q":"Where do I find my reference number?","a":"It is shown on the confirmation screen after you apply and in the acknowledgement email we send you. It looks like PWF-VOL-2026-00012."},{"q":"I entered my details but nothing was found.","a":"Check that the reference number has no extra spaces and use the same email address or mobile number you gave when applying. If it still does not work, contact us with your name and the date you applied."},{"q":"How long does a review take?","a":"It depends on the type of application and the number of applications we are reviewing. We email you whenever the status changes."},{"q":"Can I change something in my application?","a":"Yes. Write to us quoting your reference number and tell us what needs to be changed."},{"q":"I have a website account. Where are my applications?","a":"Applications made while you were signed in appear in your dashboard under My Applications."}]', '{"width":"narrow"}', 'white', 'visible', 30),
(@p, 'apply_cards', 'apply', 'Not applied yet?', 'Ways to get involved', NULL, NULL, NULL, NULL, NULL, 'cream', 'visible', 40);

-- ---------------------------------------------------------------------
-- Module M5
-- ---------------------------------------------------------------------
-- =====================================================================
-- M5 Accounts & member area - seed (re-runnable)
-- The account pages (login, register, forgot-password, reset-password, verify-email,
-- dashboard, logout) and the auth settings (allow_registration, require_email_verification,
-- auth_panel_title/text/image) are already in the core seed. No content, users or
-- example data are added here.
-- Account pages are private: keep them out of search engines and the XML sitemap
-- (robots.txt already disallows them).
-- =====================================================================
UPDATE pages SET noindex = 1, show_in_sitemap = 0
 WHERE slug IN ('login', 'register', 'forgot-password', 'reset-password', 'verify-email', 'dashboard', 'logout');

-- ---------------------------------------------------------------------
-- Module M6
-- ---------------------------------------------------------------------
-- =====================================================================
-- M6 Media & events: awareness days + page sections for blog, media, success-stories,
-- events-page, awareness-calendar, gallery, video-gallery.
-- No blog posts, stories, foundation events or gallery items are seeded (they must be real).
-- Awareness days: real, well-known observances 2026-10-01 .. 2027-12-31 (not events held by
-- the foundation). Re-runnable: deletes only the rows inserted here.
-- =====================================================================

SET NAMES utf8mb4;

DELETE FROM events WHERE event_type = 'awareness_day' AND slug IN (
  'international-day-of-older-persons-2026',
  'national-voluntary-blood-donation-day-2026',
  'gandhi-jayanti-2026',
  'world-mental-health-day-2026',
  'international-day-of-the-girl-child-2026',
  'global-handwashing-day-2026',
  'international-day-of-rural-women-2026',
  'world-food-day-2026',
  'international-day-for-the-eradication-of-poverty-2026',
  'national-education-day-2026',
  'national-education-day-2027',
  'childrens-day-2026',
  'childrens-day-2027',
  'world-diabetes-day-2026',
  'world-diabetes-day-2027',
  'world-toilet-day-2026',
  'world-toilet-day-2027',
  'international-day-elimination-violence-against-women-2026',
  'international-day-elimination-violence-against-women-2027',
  'constitution-day-2026',
  'constitution-day-2027',
  'world-aids-day-2026',
  'world-aids-day-2027',
  'national-pollution-control-day-2026',
  'national-pollution-control-day-2027',
  'international-day-of-persons-with-disabilities-2026',
  'international-day-of-persons-with-disabilities-2027',
  'international-volunteer-day-2026',
  'international-volunteer-day-2027',
  'human-rights-day-2026',
  'human-rights-day-2027',
  'national-energy-conservation-day-2026',
  'national-energy-conservation-day-2027',
  'national-farmers-day-2026',
  'national-farmers-day-2027',
  'national-youth-day-2027',
  'national-girl-child-day-2027',
  'national-voters-day-2027',
  'world-cancer-day-2027',
  'world-day-of-social-justice-2027',
  'international-mother-language-day-2027',
  'national-science-day-2027',
  'international-womens-day-2027',
  'international-day-of-forests-2027',
  'world-water-day-2027',
  'world-tuberculosis-day-2027',
  'world-autism-awareness-day-2027',
  'world-health-day-2027',
  'ambedkar-jayanti-2027',
  'earth-day-2027',
  'world-malaria-day-2027',
  'world-hand-hygiene-day-2027',
  'international-day-of-families-2027',
  'menstrual-hygiene-day-2027',
  'world-no-tobacco-day-2027',
  'world-environment-day-2027',
  'world-day-against-child-labour-2027',
  'world-blood-donor-day-2027',
  'international-day-of-yoga-2027',
  'national-doctors-day-2027',
  'world-population-day-2027',
  'world-youth-skills-day-2027',
  'world-day-against-trafficking-in-persons-2027',
  'international-youth-day-2027',
  'teachers-day-2027',
  'international-literacy-day-2027',
  'world-suicide-prevention-day-2027',
  'world-heart-day-2027',
  'international-day-of-older-persons-2027',
  'national-voluntary-blood-donation-day-2027',
  'gandhi-jayanti-2027',
  'world-mental-health-day-2027',
  'international-day-of-the-girl-child-2027',
  'global-handwashing-day-2027',
  'international-day-of-rural-women-2027',
  'world-food-day-2027',
  'international-day-for-the-eradication-of-poverty-2027');

INSERT INTO events (title, slug, event_type, program_id, summary, description, image, location, start_datetime, end_datetime, registration_enabled, capacity, status) VALUES
('International Day of Older Persons 2026', 'international-day-of-older-persons-2026', 'awareness_day', NULL, 'The United Nations has observed the International Day of Older Persons on 1 October since 1991 to recognise the contribution of older people and the challenges they face.', '<p>The United Nations has observed the International Day of Older Persons on 1 October since 1991 to recognise the contribution of older people and the challenges they face.</p><h3>How you can take part</h3><ul><li>Spend time with older people in your family and neighbourhood.</li><li>Help seniors use digital services and claim pensions and health schemes.</li><li>Make homes and public spaces safe and accessible for them.</li></ul>', NULL, 'India', '2026-10-01 00:00:00', '2026-10-01 23:59:00', 0, NULL, 'published'),
('National Voluntary Blood Donation Day 2026', 'national-voluntary-blood-donation-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'National Voluntary Blood Donation Day is observed in India on 1 October to promote regular, voluntary and unpaid blood donation.', '<p>National Voluntary Blood Donation Day is observed in India on 1 October to promote regular, voluntary and unpaid blood donation.</p><h3>How you can take part</h3><ul><li>Donate blood if you are healthy and eligible.</li><li>Organise or join a blood donation camp at your college or workplace.</li><li>Bust myths about blood donation among friends and family.</li></ul>', NULL, 'India', '2026-10-01 00:00:00', '2026-10-01 23:59:00', 0, NULL, 'published'),
('Gandhi Jayanti 2026', 'gandhi-jayanti-2026', 'awareness_day', NULL, 'Gandhi Jayanti, on 2 October, marks the birth anniversary of Mahatma Gandhi.', '<p>Gandhi Jayanti, on 2 October, marks the birth anniversary of Mahatma Gandhi. The United Nations also observes the day as the International Day of Non-Violence.</p><h3>How you can take part</h3><ul><li>Join a cleanliness drive in your area.</li><li>Practise and teach non-violence and respect for all.</li><li>Choose local and handmade products where you can.</li></ul>', NULL, 'India', '2026-10-02 00:00:00', '2026-10-02 23:59:00', 0, NULL, 'published'),
('World Mental Health Day 2026', 'world-mental-health-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Mental Health Day, first observed in 1992 by the World Federation for Mental Health, raises awareness of mental health and mobilises support for it.', '<p>World Mental Health Day, first observed in 1992 by the World Federation for Mental Health, raises awareness of mental health and mobilises support for it.</p><h3>How you can take part</h3><ul><li>Talk openly about mental health and listen without judgement.</li><li>Know where to find help: Tele-MANAS (14416) offers free mental health support.</li><li>Look after your own sleep, routine and social connections.</li></ul>', NULL, 'India', '2026-10-10 00:00:00', '2026-10-10 23:59:00', 0, NULL, 'published'),
('International Day of the Girl Child 2026', 'international-day-of-the-girl-child-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'women-empowerment' LIMIT 1), 'The United Nations has observed the International Day of the Girl Child on 11 October since 2012 to recognise girls'' rights and the challenges they face.', '<p>The United Nations has observed the International Day of the Girl Child on 11 October since 2012 to recognise girls'' rights and the challenges they face.</p><h3>How you can take part</h3><ul><li>Help girls stay in school and continue their education.</li><li>Support girls to learn digital and life skills.</li><li>Speak out against child marriage.</li></ul>', NULL, 'India', '2026-10-11 00:00:00', '2026-10-11 23:59:00', 0, NULL, 'published'),
('Global Handwashing Day 2026', 'global-handwashing-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'Global Handwashing Day, observed on 15 October since 2008, promotes handwashing with soap as an easy and affordable way to prevent disease.', '<p>Global Handwashing Day, observed on 15 October since 2008, promotes handwashing with soap as an easy and affordable way to prevent disease.</p><h3>How you can take part</h3><ul><li>Wash hands with soap before eating and after using the toilet.</li><li>Teach children handwashing with a simple demonstration.</li><li>Make soap and water available in schools and anganwadis.</li></ul>', NULL, 'India', '2026-10-15 00:00:00', '2026-10-15 23:59:00', 0, NULL, 'published'),
('International Day of Rural Women 2026', 'international-day-of-rural-women-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'rural-development' LIMIT 1), 'The United Nations observes the International Day of Rural Women on 15 October to recognise the role of rural women in agriculture, food security and rural development.', '<p>The United Nations observes the International Day of Rural Women on 15 October to recognise the role of rural women in agriculture, food security and rural development.</p><h3>How you can take part</h3><ul><li>Buy from rural women''s self-help groups and producer collectives.</li><li>Help rural women access training, credit and government schemes.</li><li>Value and share the unpaid work women do at home and on farms.</li></ul>', NULL, 'India', '2026-10-15 00:00:00', '2026-10-15 23:59:00', 0, NULL, 'published'),
('World Food Day 2026', 'world-food-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'food-distribution' LIMIT 1), 'World Food Day, on 16 October, marks the founding of the Food and Agriculture Organization of the United Nations in 1945 and promotes action against hunger.', '<p>World Food Day, on 16 October, marks the founding of the Food and Agriculture Organization of the United Nations in 1945 and promotes action against hunger.</p><h3>How you can take part</h3><ul><li>Do not waste food - plan meals and share surplus food safely.</li><li>Support food banks and community kitchens.</li><li>Help eligible families access ration and nutrition schemes.</li></ul>', NULL, 'India', '2026-10-16 00:00:00', '2026-10-16 23:59:00', 0, NULL, 'published'),
('International Day for the Eradication of Poverty 2026', 'international-day-for-the-eradication-of-poverty-2026', 'awareness_day', NULL, 'Observed on 17 October since 1993, this United Nations day recognises the efforts and struggles of people living in poverty and calls for action to end it.', '<p>Observed on 17 October since 1993, this United Nations day recognises the efforts and struggles of people living in poverty and calls for action to end it.</p><h3>How you can take part</h3><ul><li>Support livelihood and skill training programmes.</li><li>Help families learn about and apply for welfare schemes.</li><li>Volunteer your time or skills with organisations working with communities in need.</li></ul>', NULL, 'India', '2026-10-17 00:00:00', '2026-10-17 23:59:00', 0, NULL, 'published'),
('National Education Day 2026', 'national-education-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'National Education Day is observed in India on 11 November, the birth anniversary of Maulana Abul Kalam Azad, independent India''s first Education Minister.', '<p>National Education Day is observed in India on 11 November, the birth anniversary of Maulana Abul Kalam Azad, independent India''s first Education Minister.</p><h3>How you can take part</h3><ul><li>Spend an hour reading with a child or helping with homework.</li><li>Donate books or stationery to a community library or school.</li><li>Volunteer to teach children who need extra support.</li></ul>', NULL, 'India', '2026-11-11 00:00:00', '2026-11-11 23:59:00', 0, NULL, 'published'),
('National Education Day 2027', 'national-education-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'National Education Day is observed in India on 11 November, the birth anniversary of Maulana Abul Kalam Azad, independent India''s first Education Minister.', '<p>National Education Day is observed in India on 11 November, the birth anniversary of Maulana Abul Kalam Azad, independent India''s first Education Minister.</p><h3>How you can take part</h3><ul><li>Spend an hour reading with a child or helping with homework.</li><li>Donate books or stationery to a community library or school.</li><li>Volunteer to teach children who need extra support.</li></ul>', NULL, 'India', '2027-11-11 00:00:00', '2027-11-11 23:59:00', 0, NULL, 'published'),
('Children''s Day (Bal Diwas) 2026', 'childrens-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'Children''s Day (Bal Diwas) is celebrated in India on 14 November, the birth anniversary of Jawaharlal Nehru, the country''s first Prime Minister.', '<p>Children''s Day (Bal Diwas) is celebrated in India on 14 November, the birth anniversary of Jawaharlal Nehru, the country''s first Prime Minister. It is a day to focus on every child''s right to education, health, play and protection.</p><h3>How you can take part</h3><ul><li>Organise reading, art or sports activities for children in your neighbourhood.</li><li>Support a child''s schooling with books, a uniform or fees.</li><li>Learn how to recognise and report child abuse - call CHILDLINE 1098 if a child needs help.</li></ul>', NULL, 'India', '2026-11-14 00:00:00', '2026-11-14 23:59:00', 0, NULL, 'published'),
('Children''s Day (Bal Diwas) 2027', 'childrens-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'Children''s Day (Bal Diwas) is celebrated in India on 14 November, the birth anniversary of Jawaharlal Nehru, the country''s first Prime Minister.', '<p>Children''s Day (Bal Diwas) is celebrated in India on 14 November, the birth anniversary of Jawaharlal Nehru, the country''s first Prime Minister. It is a day to focus on every child''s right to education, health, play and protection.</p><h3>How you can take part</h3><ul><li>Organise reading, art or sports activities for children in your neighbourhood.</li><li>Support a child''s schooling with books, a uniform or fees.</li><li>Learn how to recognise and report child abuse - call CHILDLINE 1098 if a child needs help.</li></ul>', NULL, 'India', '2027-11-14 00:00:00', '2027-11-14 23:59:00', 0, NULL, 'published'),
('World Diabetes Day 2026', 'world-diabetes-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Diabetes Day, observed on 14 November, is led by the International Diabetes Federation and marks the birthday of Sir Frederick Banting, who co-discovered insulin.', '<p>World Diabetes Day, observed on 14 November, is led by the International Diabetes Federation and marks the birthday of Sir Frederick Banting, who co-discovered insulin. It raises awareness of the prevention, early detection and management of diabetes.</p><h3>How you can take part</h3><ul><li>Get your blood sugar checked, especially if diabetes runs in your family.</li><li>Choose a balanced diet and walk for at least 30 minutes a day.</li><li>Encourage older family members to attend screening camps.</li></ul>', NULL, 'India', '2026-11-14 00:00:00', '2026-11-14 23:59:00', 0, NULL, 'published'),
('World Diabetes Day 2027', 'world-diabetes-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Diabetes Day, observed on 14 November, is led by the International Diabetes Federation and marks the birthday of Sir Frederick Banting, who co-discovered insulin.', '<p>World Diabetes Day, observed on 14 November, is led by the International Diabetes Federation and marks the birthday of Sir Frederick Banting, who co-discovered insulin. It raises awareness of the prevention, early detection and management of diabetes.</p><h3>How you can take part</h3><ul><li>Get your blood sugar checked, especially if diabetes runs in your family.</li><li>Choose a balanced diet and walk for at least 30 minutes a day.</li><li>Encourage older family members to attend screening camps.</li></ul>', NULL, 'India', '2027-11-14 00:00:00', '2027-11-14 23:59:00', 0, NULL, 'published'),
('World Toilet Day 2026', 'world-toilet-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'rural-development' LIMIT 1), 'World Toilet Day is a United Nations observance on 19 November that draws attention to the billions of people still living without safely managed sanitation.', '<p>World Toilet Day is a United Nations observance on 19 November that draws attention to the billions of people still living without safely managed sanitation.</p><h3>How you can take part</h3><ul><li>Talk with your community about the health benefits of using toilets and washing hands.</li><li>Support clean, safe toilets in schools, especially for girls.</li><li>Keep shared toilets clean and report broken facilities to the local body.</li></ul>', NULL, 'India', '2026-11-19 00:00:00', '2026-11-19 23:59:00', 0, NULL, 'published'),
('World Toilet Day 2027', 'world-toilet-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'rural-development' LIMIT 1), 'World Toilet Day is a United Nations observance on 19 November that draws attention to the billions of people still living without safely managed sanitation.', '<p>World Toilet Day is a United Nations observance on 19 November that draws attention to the billions of people still living without safely managed sanitation.</p><h3>How you can take part</h3><ul><li>Talk with your community about the health benefits of using toilets and washing hands.</li><li>Support clean, safe toilets in schools, especially for girls.</li><li>Keep shared toilets clean and report broken facilities to the local body.</li></ul>', NULL, 'India', '2027-11-19 00:00:00', '2027-11-19 23:59:00', 0, NULL, 'published'),
('International Day for the Elimination of Violence against Women 2026', 'international-day-elimination-violence-against-women-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'women-empowerment' LIMIT 1), 'Observed by the United Nations on 25 November, this day opens the 16 Days of Activism against gender-based violence, which end on Human Rights Day, 10 December.', '<p>Observed by the United Nations on 25 November, this day opens the 16 Days of Activism against gender-based violence, which end on Human Rights Day, 10 December.</p><h3>How you can take part</h3><ul><li>Learn and share helpline numbers such as the Women Helpline 181 and the emergency number 112.</li><li>Speak up against harassment and discrimination at home, at work and online.</li><li>Support women''s groups and self-help groups in your area.</li></ul>', NULL, 'India', '2026-11-25 00:00:00', '2026-11-25 23:59:00', 0, NULL, 'published'),
('International Day for the Elimination of Violence against Women 2027', 'international-day-elimination-violence-against-women-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'women-empowerment' LIMIT 1), 'Observed by the United Nations on 25 November, this day opens the 16 Days of Activism against gender-based violence, which end on Human Rights Day, 10 December.', '<p>Observed by the United Nations on 25 November, this day opens the 16 Days of Activism against gender-based violence, which end on Human Rights Day, 10 December.</p><h3>How you can take part</h3><ul><li>Learn and share helpline numbers such as the Women Helpline 181 and the emergency number 112.</li><li>Speak up against harassment and discrimination at home, at work and online.</li><li>Support women''s groups and self-help groups in your area.</li></ul>', NULL, 'India', '2027-11-25 00:00:00', '2027-11-25 23:59:00', 0, NULL, 'published'),
('Constitution Day (Samvidhan Divas) 2026', 'constitution-day-2026', 'awareness_day', NULL, 'Constitution Day (Samvidhan Divas) marks 26 November 1949, the day the Constituent Assembly adopted the Constitution of India.', '<p>Constitution Day (Samvidhan Divas) marks 26 November 1949, the day the Constituent Assembly adopted the Constitution of India. It has been observed nationally since 2015.</p><h3>How you can take part</h3><ul><li>Read the Preamble aloud with your family, class or colleagues.</li><li>Learn about fundamental rights and duties.</li><li>Help someone in your community understand and claim their entitlements.</li></ul>', NULL, 'India', '2026-11-26 00:00:00', '2026-11-26 23:59:00', 0, NULL, 'published'),
('Constitution Day (Samvidhan Divas) 2027', 'constitution-day-2027', 'awareness_day', NULL, 'Constitution Day (Samvidhan Divas) marks 26 November 1949, the day the Constituent Assembly adopted the Constitution of India.', '<p>Constitution Day (Samvidhan Divas) marks 26 November 1949, the day the Constituent Assembly adopted the Constitution of India. It has been observed nationally since 2015.</p><h3>How you can take part</h3><ul><li>Read the Preamble aloud with your family, class or colleagues.</li><li>Learn about fundamental rights and duties.</li><li>Help someone in your community understand and claim their entitlements.</li></ul>', NULL, 'India', '2027-11-26 00:00:00', '2027-11-26 23:59:00', 0, NULL, 'published'),
('World AIDS Day 2026', 'world-aids-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World AIDS Day has been observed on 1 December every year since 1988 to show support for people living with HIV, remember those who have died and push for prevention, testing and treatment.', '<p>World AIDS Day has been observed on 1 December every year since 1988 to show support for people living with HIV, remember those who have died and push for prevention, testing and treatment.</p><h3>How you can take part</h3><ul><li>Learn the facts about HIV and challenge stigma and discrimination.</li><li>Know where free, confidential HIV testing is available near you.</li><li>Wear a red ribbon and share accurate information with young people.</li></ul>', NULL, 'India', '2026-12-01 00:00:00', '2026-12-01 23:59:00', 0, NULL, 'published'),
('World AIDS Day 2027', 'world-aids-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World AIDS Day has been observed on 1 December every year since 1988 to show support for people living with HIV, remember those who have died and push for prevention, testing and treatment.', '<p>World AIDS Day has been observed on 1 December every year since 1988 to show support for people living with HIV, remember those who have died and push for prevention, testing and treatment.</p><h3>How you can take part</h3><ul><li>Learn the facts about HIV and challenge stigma and discrimination.</li><li>Know where free, confidential HIV testing is available near you.</li><li>Wear a red ribbon and share accurate information with young people.</li></ul>', NULL, 'India', '2027-12-01 00:00:00', '2027-12-01 23:59:00', 0, NULL, 'published'),
('National Pollution Control Day 2026', 'national-pollution-control-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'National Pollution Control Day is observed in India on 2 December in memory of those who lost their lives in the 1984 Bhopal gas tragedy, and to raise awareness about preventing pollution.', '<p>National Pollution Control Day is observed in India on 2 December in memory of those who lost their lives in the 1984 Bhopal gas tragedy, and to raise awareness about preventing pollution.</p><h3>How you can take part</h3><ul><li>Do not burn waste; segregate dry and wet waste at home.</li><li>Use public transport, cycle or walk for short trips.</li><li>Plant and care for trees in your neighbourhood.</li></ul>', NULL, 'India', '2026-12-02 00:00:00', '2026-12-02 23:59:00', 0, NULL, 'published'),
('National Pollution Control Day 2027', 'national-pollution-control-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'National Pollution Control Day is observed in India on 2 December in memory of those who lost their lives in the 1984 Bhopal gas tragedy, and to raise awareness about preventing pollution.', '<p>National Pollution Control Day is observed in India on 2 December in memory of those who lost their lives in the 1984 Bhopal gas tragedy, and to raise awareness about preventing pollution.</p><h3>How you can take part</h3><ul><li>Do not burn waste; segregate dry and wet waste at home.</li><li>Use public transport, cycle or walk for short trips.</li><li>Plant and care for trees in your neighbourhood.</li></ul>', NULL, 'India', '2027-12-02 00:00:00', '2027-12-02 23:59:00', 0, NULL, 'published'),
('International Day of Persons with Disabilities 2026', 'international-day-of-persons-with-disabilities-2026', 'awareness_day', NULL, 'Proclaimed by the United Nations General Assembly in 1992, this day on 3 December promotes the rights, dignity and full inclusion of persons with disabilities in every part of society.', '<p>Proclaimed by the United Nations General Assembly in 1992, this day on 3 December promotes the rights, dignity and full inclusion of persons with disabilities in every part of society.</p><h3>How you can take part</h3><ul><li>Check that your school, workplace or event is accessible to everyone.</li><li>Learn about the disability certificate (UDID card) and the schemes it gives access to.</li><li>Include persons with disabilities when you plan community activities.</li></ul>', NULL, 'India', '2026-12-03 00:00:00', '2026-12-03 23:59:00', 0, NULL, 'published'),
('International Day of Persons with Disabilities 2027', 'international-day-of-persons-with-disabilities-2027', 'awareness_day', NULL, 'Proclaimed by the United Nations General Assembly in 1992, this day on 3 December promotes the rights, dignity and full inclusion of persons with disabilities in every part of society.', '<p>Proclaimed by the United Nations General Assembly in 1992, this day on 3 December promotes the rights, dignity and full inclusion of persons with disabilities in every part of society.</p><h3>How you can take part</h3><ul><li>Check that your school, workplace or event is accessible to everyone.</li><li>Learn about the disability certificate (UDID card) and the schemes it gives access to.</li><li>Include persons with disabilities when you plan community activities.</li></ul>', NULL, 'India', '2027-12-03 00:00:00', '2027-12-03 23:59:00', 0, NULL, 'published'),
('International Volunteer Day 2026', 'international-volunteer-day-2026', 'awareness_day', NULL, 'International Volunteer Day, designated by the United Nations in 1985, is observed on 5 December to recognise volunteers and the difference they make to their communities.', '<p>International Volunteer Day, designated by the United Nations in 1985, is observed on 5 December to recognise volunteers and the difference they make to their communities.</p><h3>How you can take part</h3><ul><li>Give a few hours a month to a cause you care about.</li><li>Thank the volunteers who support your community.</li><li>Sign up to volunteer with a registered organisation near you.</li></ul>', NULL, 'India', '2026-12-05 00:00:00', '2026-12-05 23:59:00', 0, NULL, 'published'),
('International Volunteer Day 2027', 'international-volunteer-day-2027', 'awareness_day', NULL, 'International Volunteer Day, designated by the United Nations in 1985, is observed on 5 December to recognise volunteers and the difference they make to their communities.', '<p>International Volunteer Day, designated by the United Nations in 1985, is observed on 5 December to recognise volunteers and the difference they make to their communities.</p><h3>How you can take part</h3><ul><li>Give a few hours a month to a cause you care about.</li><li>Thank the volunteers who support your community.</li><li>Sign up to volunteer with a registered organisation near you.</li></ul>', NULL, 'India', '2027-12-05 00:00:00', '2027-12-05 23:59:00', 0, NULL, 'published'),
('Human Rights Day 2026', 'human-rights-day-2026', 'awareness_day', NULL, 'Human Rights Day is observed on 10 December, the anniversary of the adoption of the Universal Declaration of Human Rights by the United Nations General Assembly in 1948.', '<p>Human Rights Day is observed on 10 December, the anniversary of the adoption of the Universal Declaration of Human Rights by the United Nations General Assembly in 1948.</p><h3>How you can take part</h3><ul><li>Read about the rights every person is entitled to.</li><li>Stand up against discrimination based on caste, religion, gender or disability.</li><li>Help people reach free legal aid - the NALSA helpline is 15100.</li></ul>', NULL, 'India', '2026-12-10 00:00:00', '2026-12-10 23:59:00', 0, NULL, 'published'),
('Human Rights Day 2027', 'human-rights-day-2027', 'awareness_day', NULL, 'Human Rights Day is observed on 10 December, the anniversary of the adoption of the Universal Declaration of Human Rights by the United Nations General Assembly in 1948.', '<p>Human Rights Day is observed on 10 December, the anniversary of the adoption of the Universal Declaration of Human Rights by the United Nations General Assembly in 1948.</p><h3>How you can take part</h3><ul><li>Read about the rights every person is entitled to.</li><li>Stand up against discrimination based on caste, religion, gender or disability.</li><li>Help people reach free legal aid - the NALSA helpline is 15100.</li></ul>', NULL, 'India', '2027-12-10 00:00:00', '2027-12-10 23:59:00', 0, NULL, 'published'),
('National Energy Conservation Day 2026', 'national-energy-conservation-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'National Energy Conservation Day is observed in India on 14 December to promote the efficient use of energy, led by the Bureau of Energy Efficiency under the Ministry of Power.', '<p>National Energy Conservation Day is observed in India on 14 December to promote the efficient use of energy, led by the Bureau of Energy Efficiency under the Ministry of Power.</p><h3>How you can take part</h3><ul><li>Switch off lights and fans when you leave a room.</li><li>Choose star-rated appliances and LED bulbs.</li><li>Teach children simple ways to save electricity and water.</li></ul>', NULL, 'India', '2026-12-14 00:00:00', '2026-12-14 23:59:00', 0, NULL, 'published'),
('National Energy Conservation Day 2027', 'national-energy-conservation-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'National Energy Conservation Day is observed in India on 14 December to promote the efficient use of energy, led by the Bureau of Energy Efficiency under the Ministry of Power.', '<p>National Energy Conservation Day is observed in India on 14 December to promote the efficient use of energy, led by the Bureau of Energy Efficiency under the Ministry of Power.</p><h3>How you can take part</h3><ul><li>Switch off lights and fans when you leave a room.</li><li>Choose star-rated appliances and LED bulbs.</li><li>Teach children simple ways to save electricity and water.</li></ul>', NULL, 'India', '2027-12-14 00:00:00', '2027-12-14 23:59:00', 0, NULL, 'published'),
('National Farmers'' Day (Kisan Diwas) 2026', 'national-farmers-day-2026', 'awareness_day', (SELECT id FROM programs WHERE slug = 'rural-development' LIMIT 1), 'Kisan Diwas is observed on 23 December, the birth anniversary of former Prime Minister Chaudhary Charan Singh, to honour the contribution of farmers to the nation.', '<p>Kisan Diwas is observed on 23 December, the birth anniversary of former Prime Minister Chaudhary Charan Singh, to honour the contribution of farmers to the nation.</p><h3>How you can take part</h3><ul><li>Buy directly from local farmers and farmers'' markets where you can.</li><li>Help farmers learn about government schemes, crop insurance and soil health cards.</li><li>Avoid wasting food.</li></ul>', NULL, 'India', '2026-12-23 00:00:00', '2026-12-23 23:59:00', 0, NULL, 'published'),
('National Farmers'' Day (Kisan Diwas) 2027', 'national-farmers-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'rural-development' LIMIT 1), 'Kisan Diwas is observed on 23 December, the birth anniversary of former Prime Minister Chaudhary Charan Singh, to honour the contribution of farmers to the nation.', '<p>Kisan Diwas is observed on 23 December, the birth anniversary of former Prime Minister Chaudhary Charan Singh, to honour the contribution of farmers to the nation.</p><h3>How you can take part</h3><ul><li>Buy directly from local farmers and farmers'' markets where you can.</li><li>Help farmers learn about government schemes, crop insurance and soil health cards.</li><li>Avoid wasting food.</li></ul>', NULL, 'India', '2027-12-23 00:00:00', '2027-12-23 23:59:00', 0, NULL, 'published'),
('National Youth Day 2027', 'national-youth-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'youth-empowerment' LIMIT 1), 'National Youth Day is celebrated in India on 12 January, the birth anniversary of Swami Vivekananda, whose ideas continue to inspire young people.', '<p>National Youth Day is celebrated in India on 12 January, the birth anniversary of Swami Vivekananda, whose ideas continue to inspire young people.</p><h3>How you can take part</h3><ul><li>Volunteer for a community service activity with your friends.</li><li>Mentor a younger student in studies or career planning.</li><li>Learn a new skill - digital, vocational or leadership.</li></ul>', NULL, 'India', '2027-01-12 00:00:00', '2027-01-12 23:59:00', 0, NULL, 'published'),
('National Girl Child Day 2027', 'national-girl-child-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'women-empowerment' LIMIT 1), 'National Girl Child Day has been observed in India on 24 January since 2008 to promote the rights of girls - their education, health and nutrition - and to end discrimination against them.', '<p>National Girl Child Day has been observed in India on 24 January since 2008 to promote the rights of girls - their education, health and nutrition - and to end discrimination against them.</p><h3>How you can take part</h3><ul><li>Support a girl''s education - books, fees, a bicycle or a mentor can make the difference.</li><li>Speak out against child marriage and gender discrimination.</li><li>Celebrate the achievements of girls in your family and community.</li></ul>', NULL, 'India', '2027-01-24 00:00:00', '2027-01-24 23:59:00', 0, NULL, 'published'),
('National Voters'' Day 2027', 'national-voters-day-2027', 'awareness_day', NULL, 'National Voters'' Day is observed on 25 January, the foundation day of the Election Commission of India (1950), to encourage every eligible citizen to register and vote.', '<p>National Voters'' Day is observed on 25 January, the foundation day of the Election Commission of India (1950), to encourage every eligible citizen to register and vote.</p><h3>How you can take part</h3><ul><li>Check that your name is on the electoral roll.</li><li>Help first-time voters aged 18 and above to register.</li><li>Encourage informed, inducement-free voting.</li></ul>', NULL, 'India', '2027-01-25 00:00:00', '2027-01-25 23:59:00', 0, NULL, 'published'),
('World Cancer Day 2027', 'world-cancer-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Cancer Day, led by the Union for International Cancer Control, is observed on 4 February to raise awareness of cancer prevention, early detection and treatment.', '<p>World Cancer Day, led by the Union for International Cancer Control, is observed on 4 February to raise awareness of cancer prevention, early detection and treatment.</p><h3>How you can take part</h3><ul><li>Avoid tobacco in all forms and limit alcohol.</li><li>Know the early warning signs and go for recommended screenings.</li><li>Support patients and their families in your community.</li></ul>', NULL, 'India', '2027-02-04 00:00:00', '2027-02-04 23:59:00', 0, NULL, 'published'),
('World Day of Social Justice 2027', 'world-day-of-social-justice-2027', 'awareness_day', NULL, 'The United Nations observes the World Day of Social Justice on 20 February to promote efforts to tackle poverty, exclusion, unemployment and inequality.', '<p>The United Nations observes the World Day of Social Justice on 20 February to promote efforts to tackle poverty, exclusion, unemployment and inequality.</p><h3>How you can take part</h3><ul><li>Support fair wages and decent working conditions.</li><li>Help families learn about social security and welfare schemes.</li><li>Volunteer with programmes that widen access to education and skills.</li></ul>', NULL, 'India', '2027-02-20 00:00:00', '2027-02-20 23:59:00', 0, NULL, 'published'),
('International Mother Language Day 2027', 'international-mother-language-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'Proclaimed by UNESCO in 1999, International Mother Language Day on 21 February promotes linguistic and cultural diversity and learning in one''s mother tongue.', '<p>Proclaimed by UNESCO in 1999, International Mother Language Day on 21 February promotes linguistic and cultural diversity and learning in one''s mother tongue.</p><h3>How you can take part</h3><ul><li>Read stories to children in your mother tongue.</li><li>Share books and learning material in Indian languages.</li><li>Celebrate the many languages spoken in your community.</li></ul>', NULL, 'India', '2027-02-21 00:00:00', '2027-02-21 23:59:00', 0, NULL, 'published'),
('National Science Day 2027', 'national-science-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'National Science Day is celebrated in India on 28 February to mark the discovery of the Raman effect by Sir C. V. Raman, announced in 1928.', '<p>National Science Day is celebrated in India on 28 February to mark the discovery of the Raman effect by Sir C. V. Raman, announced in 1928.</p><h3>How you can take part</h3><ul><li>Do a simple science experiment with children at home or at school.</li><li>Visit a science centre or museum.</li><li>Encourage girls and boys alike to take up science.</li></ul>', NULL, 'India', '2027-02-28 00:00:00', '2027-02-28 23:59:00', 0, NULL, 'published'),
('International Women''s Day 2027', 'international-womens-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'women-empowerment' LIMIT 1), 'International Women''s Day on 8 March celebrates the achievements of women and calls for action on gender equality.', '<p>International Women''s Day on 8 March celebrates the achievements of women and calls for action on gender equality.</p><h3>How you can take part</h3><ul><li>Support women-led self-help groups and small businesses.</li><li>Encourage women and girls to learn digital and financial skills.</li><li>Share household work and challenge gender stereotypes.</li></ul>', NULL, 'India', '2027-03-08 00:00:00', '2027-03-08 23:59:00', 0, NULL, 'published'),
('International Day of Forests 2027', 'international-day-of-forests-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'The United Nations observes the International Day of Forests on 21 March to celebrate and raise awareness of the importance of all types of forests and trees.', '<p>The United Nations observes the International Day of Forests on 21 March to celebrate and raise awareness of the importance of all types of forests and trees.</p><h3>How you can take part</h3><ul><li>Plant native trees and look after the saplings until they grow.</li><li>Use less paper and recycle.</li><li>Protect green spaces in your neighbourhood.</li></ul>', NULL, 'India', '2027-03-21 00:00:00', '2027-03-21 23:59:00', 0, NULL, 'published'),
('World Water Day 2027', 'world-water-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'World Water Day, observed by the United Nations on 22 March since 1993, focuses on the importance of fresh water and on safe water and sanitation for all.', '<p>World Water Day, observed by the United Nations on 22 March since 1993, focuses on the importance of fresh water and on safe water and sanitation for all.</p><h3>How you can take part</h3><ul><li>Fix leaking taps and pipes at home.</li><li>Harvest rainwater and reuse water where you can.</li><li>Keep ponds, wells and rivers free of waste.</li></ul>', NULL, 'India', '2027-03-22 00:00:00', '2027-03-22 23:59:00', 0, NULL, 'published'),
('World Tuberculosis Day 2027', 'world-tuberculosis-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Tuberculosis Day on 24 March marks the day in 1882 when Dr Robert Koch announced the discovery of the bacterium that causes TB.', '<p>World Tuberculosis Day on 24 March marks the day in 1882 when Dr Robert Koch announced the discovery of the bacterium that causes TB.</p><h3>How you can take part</h3><ul><li>Know the symptoms - a cough lasting two weeks or more needs a test.</li><li>Remember that TB tests and treatment are free at government health facilities in India.</li><li>Support patients to complete their full course of treatment.</li></ul>', NULL, 'India', '2027-03-24 00:00:00', '2027-03-24 23:59:00', 0, NULL, 'published'),
('World Autism Awareness Day 2027', 'world-autism-awareness-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Autism Awareness Day, observed by the United Nations on 2 April, promotes understanding, acceptance and inclusion of autistic people.', '<p>World Autism Awareness Day, observed by the United Nations on 2 April, promotes understanding, acceptance and inclusion of autistic people.</p><h3>How you can take part</h3><ul><li>Learn about autism and its early signs in young children.</li><li>Make classrooms and public spaces welcoming for autistic children and adults.</li><li>Support families who care for an autistic child.</li></ul>', NULL, 'India', '2027-04-02 00:00:00', '2027-04-02 23:59:00', 0, NULL, 'published'),
('World Health Day 2027', 'world-health-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Health Day marks the founding of the World Health Organization on 7 April 1948.', '<p>World Health Day marks the founding of the World Health Organization on 7 April 1948. Each year WHO highlights a health theme that matters to people everywhere.</p><h3>How you can take part</h3><ul><li>Get a basic health check-up and keep vaccinations up to date.</li><li>Share reliable health information with your family and neighbours.</li><li>Support local health camps and screening drives.</li></ul>', NULL, 'India', '2027-04-07 00:00:00', '2027-04-07 23:59:00', 0, NULL, 'published'),
('Dr B. R. Ambedkar Jayanti 2027', 'ambedkar-jayanti-2027', 'awareness_day', NULL, 'Ambedkar Jayanti, on 14 April, marks the birth anniversary of Dr B. R. Ambedkar, social reformer and chairman of the drafting committee of the Constitution of India.', '<p>Ambedkar Jayanti, on 14 April, marks the birth anniversary of Dr B. R. Ambedkar, social reformer and chairman of the drafting committee of the Constitution of India.</p><h3>How you can take part</h3><ul><li>Read about Dr Ambedkar''s life and his work for equality and education.</li><li>Help students from disadvantaged backgrounds find scholarships and schemes.</li><li>Stand against caste-based discrimination.</li></ul>', NULL, 'India', '2027-04-14 00:00:00', '2027-04-14 23:59:00', 0, NULL, 'published'),
('Earth Day 2027', 'earth-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'Earth Day has been observed on 22 April since 1970 and brings people around the world together to protect the environment.', '<p>Earth Day has been observed on 22 April since 1970 and brings people around the world together to protect the environment.</p><h3>How you can take part</h3><ul><li>Segregate waste at home and compost kitchen waste.</li><li>Say no to single-use plastic.</li><li>Join or organise a clean-up in your area.</li></ul>', NULL, 'India', '2027-04-22 00:00:00', '2027-04-22 23:59:00', 0, NULL, 'published'),
('World Malaria Day 2027', 'world-malaria-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Malaria Day, observed on 25 April, highlights the global effort to prevent, control and eliminate malaria.', '<p>World Malaria Day, observed on 25 April, highlights the global effort to prevent, control and eliminate malaria.</p><h3>How you can take part</h3><ul><li>Do not let water collect around your home - mosquitoes breed in stagnant water.</li><li>Use mosquito nets and repellents.</li><li>Get tested quickly if you have fever with chills.</li></ul>', NULL, 'India', '2027-04-25 00:00:00', '2027-04-25 23:59:00', 0, NULL, 'published'),
('World Hand Hygiene Day 2027', 'world-hand-hygiene-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'The World Health Organization observes World Hand Hygiene Day on 5 May to remind health workers and the public that clean hands prevent infections.', '<p>The World Health Organization observes World Hand Hygiene Day on 5 May to remind health workers and the public that clean hands prevent infections.</p><h3>How you can take part</h3><ul><li>Wash hands with soap and water for at least 20 seconds, especially before eating.</li><li>Teach children the steps of handwashing.</li><li>Make soap and water available in schools and community spaces.</li></ul>', NULL, 'India', '2027-05-05 00:00:00', '2027-05-05 23:59:00', 0, NULL, 'published'),
('International Day of Families 2027', 'international-day-of-families-2027', 'awareness_day', NULL, 'Proclaimed by the United Nations General Assembly in 1993, the International Day of Families on 15 May reflects on the importance of families and the issues that affect them.', '<p>Proclaimed by the United Nations General Assembly in 1993, the International Day of Families on 15 May reflects on the importance of families and the issues that affect them.</p><h3>How you can take part</h3><ul><li>Spend time with the elders and children in your family.</li><li>Share caregiving and household responsibilities.</li><li>Help families in need learn about the support available to them.</li></ul>', NULL, 'India', '2027-05-15 00:00:00', '2027-05-15 23:59:00', 0, NULL, 'published'),
('Menstrual Hygiene Day 2027', 'menstrual-hygiene-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'women-empowerment' LIMIT 1), 'Menstrual Hygiene Day, observed on 28 May since 2014, works to break the silence and stigma around menstruation and to improve access to menstrual products and education.', '<p>Menstrual Hygiene Day, observed on 28 May since 2014, works to break the silence and stigma around menstruation and to improve access to menstrual products and education.</p><h3>How you can take part</h3><ul><li>Talk openly about periods with the girls and boys in your family.</li><li>Support safe menstrual products and clean, private toilets in schools.</li><li>Dispose of used products safely.</li></ul>', NULL, 'India', '2027-05-28 00:00:00', '2027-05-28 23:59:00', 0, NULL, 'published'),
('World No Tobacco Day 2027', 'world-no-tobacco-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World No Tobacco Day, observed by the World Health Organization on 31 May, highlights the health risks of tobacco use and encourages people to quit.', '<p>World No Tobacco Day, observed by the World Health Organization on 31 May, highlights the health risks of tobacco use and encourages people to quit.</p><h3>How you can take part</h3><ul><li>If you use tobacco, seek help to quit - the National Tobacco Quit Line is 1800-112-356.</li><li>Keep homes and vehicles smoke-free.</li><li>Talk to young people about the harm tobacco does.</li></ul>', NULL, 'India', '2027-05-31 00:00:00', '2027-05-31 23:59:00', 0, NULL, 'published'),
('World Environment Day 2027', 'world-environment-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'environment-protection' LIMIT 1), 'World Environment Day, led by the UN Environment Programme, has been observed on 5 June since 1973 and is the largest global day for environmental action.', '<p>World Environment Day, led by the UN Environment Programme, has been observed on 5 June since 1973 and is the largest global day for environmental action.</p><h3>How you can take part</h3><ul><li>Plant a tree and care for it.</li><li>Carry a cloth bag and a water bottle to avoid single-use plastic.</li><li>Segregate waste and save electricity and water.</li></ul>', NULL, 'India', '2027-06-05 00:00:00', '2027-06-05 23:59:00', 0, NULL, 'published'),
('World Day Against Child Labour 2027', 'world-day-against-child-labour-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'The International Labour Organization launched the World Day Against Child Labour on 12 June 2002 to focus attention on ending child labour.', '<p>The International Labour Organization launched the World Day Against Child Labour on 12 June 2002 to focus attention on ending child labour.</p><h3>How you can take part</h3><ul><li>Do not employ children at home, in shops or on farms.</li><li>Help out-of-school children return to school.</li><li>Report child labour on the PENCIL portal or call CHILDLINE 1098.</li></ul>', NULL, 'India', '2027-06-12 00:00:00', '2027-06-12 23:59:00', 0, NULL, 'published'),
('World Blood Donor Day 2027', 'world-blood-donor-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Blood Donor Day on 14 June thanks voluntary blood donors and marks the birthday of Karl Landsteiner, who discovered the ABO blood group system.', '<p>World Blood Donor Day on 14 June thanks voluntary blood donors and marks the birthday of Karl Landsteiner, who discovered the ABO blood group system.</p><h3>How you can take part</h3><ul><li>Donate blood if you are healthy and eligible (generally aged 18 to 65).</li><li>Encourage friends to become regular voluntary donors.</li><li>Support blood donation camps in your area.</li></ul>', NULL, 'India', '2027-06-14 00:00:00', '2027-06-14 23:59:00', 0, NULL, 'published'),
('International Day of Yoga 2027', 'international-day-of-yoga-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'The International Day of Yoga was proclaimed by the United Nations General Assembly in December 2014 and has been observed on 21 June every year since 2015.', '<p>The International Day of Yoga was proclaimed by the United Nations General Assembly in December 2014 and has been observed on 21 June every year since 2015.</p><h3>How you can take part</h3><ul><li>Join a community yoga session or practise at home.</li><li>Make a short daily routine of stretching and breathing a family habit.</li><li>Encourage older people to stay active safely.</li></ul>', NULL, 'India', '2027-06-21 00:00:00', '2027-06-21 23:59:00', 0, NULL, 'published'),
('National Doctors'' Day 2027', 'national-doctors-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'National Doctors'' Day is observed in India on 1 July, the birth and death anniversary of Dr Bidhan Chandra Roy, physician and former Chief Minister of West Bengal.', '<p>National Doctors'' Day is observed in India on 1 July, the birth and death anniversary of Dr Bidhan Chandra Roy, physician and former Chief Minister of West Bengal.</p><h3>How you can take part</h3><ul><li>Thank the doctors and health workers who serve your community.</li><li>Go for regular check-ups rather than waiting for illness.</li><li>Avoid self-medication and follow your doctor''s advice.</li></ul>', NULL, 'India', '2027-07-01 00:00:00', '2027-07-01 23:59:00', 0, NULL, 'published'),
('World Population Day 2027', 'world-population-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Population Day, established by the United Nations Development Programme in 1989, is observed on 11 July to focus attention on population issues and reproductive health.', '<p>World Population Day, established by the United Nations Development Programme in 1989, is observed on 11 July to focus attention on population issues and reproductive health.</p><h3>How you can take part</h3><ul><li>Share information about family planning and maternal health services.</li><li>Support the education of girls.</li><li>Help families access health and nutrition schemes.</li></ul>', NULL, 'India', '2027-07-11 00:00:00', '2027-07-11 23:59:00', 0, NULL, 'published'),
('World Youth Skills Day 2027', 'world-youth-skills-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'skill-development' LIMIT 1), 'The United Nations observes World Youth Skills Day on 15 July to highlight the importance of equipping young people with skills for employment, decent work and entrepreneurship.', '<p>The United Nations observes World Youth Skills Day on 15 July to highlight the importance of equipping young people with skills for employment, decent work and entrepreneurship.</p><h3>How you can take part</h3><ul><li>Learn a new vocational or digital skill.</li><li>Help a young person enrol in a skill training course.</li><li>Share your professional skills as a volunteer trainer or mentor.</li></ul>', NULL, 'India', '2027-07-15 00:00:00', '2027-07-15 23:59:00', 0, NULL, 'published'),
('World Day against Trafficking in Persons 2027', 'world-day-against-trafficking-in-persons-2027', 'awareness_day', NULL, 'The United Nations observes the World Day against Trafficking in Persons on 30 July to raise awareness of the situation of victims of human trafficking and to protect their rights.', '<p>The United Nations observes the World Day against Trafficking in Persons on 30 July to raise awareness of the situation of victims of human trafficking and to protect their rights.</p><h3>How you can take part</h3><ul><li>Be wary of job offers that seem too good to be true, especially far from home.</li><li>Report suspected trafficking by calling 112.</li><li>Help vulnerable families learn about safe migration and their rights.</li></ul>', NULL, 'India', '2027-07-30 00:00:00', '2027-07-30 23:59:00', 0, NULL, 'published'),
('International Youth Day 2027', 'international-youth-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'youth-empowerment' LIMIT 1), 'International Youth Day, observed by the United Nations on 12 August since 2000, draws attention to the issues young people face and celebrates their role in change.', '<p>International Youth Day, observed by the United Nations on 12 August since 2000, draws attention to the issues young people face and celebrates their role in change.</p><h3>How you can take part</h3><ul><li>Join or start a youth-led community project.</li><li>Mentor a young person in studies, skills or career choices.</li><li>Make room for young voices in community decisions.</li></ul>', NULL, 'India', '2027-08-12 00:00:00', '2027-08-12 23:59:00', 0, NULL, 'published'),
('Teachers'' Day 2027', 'teachers-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'Teachers'' Day is celebrated in India on 5 September, the birth anniversary of Dr Sarvepalli Radhakrishnan, scholar, teacher and former President of India.', '<p>Teachers'' Day is celebrated in India on 5 September, the birth anniversary of Dr Sarvepalli Radhakrishnan, scholar, teacher and former President of India.</p><h3>How you can take part</h3><ul><li>Thank a teacher who made a difference to you.</li><li>Volunteer to teach children who need extra support.</li><li>Donate learning material to a community school.</li></ul>', NULL, 'India', '2027-09-05 00:00:00', '2027-09-05 23:59:00', 0, NULL, 'published'),
('International Literacy Day 2027', 'international-literacy-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'education-literacy' LIMIT 1), 'International Literacy Day, proclaimed by UNESCO, has been observed on 8 September since 1967 to highlight the importance of literacy for dignity and human rights.', '<p>International Literacy Day, proclaimed by UNESCO, has been observed on 8 September since 1967 to highlight the importance of literacy for dignity and human rights.</p><h3>How you can take part</h3><ul><li>Teach an adult or a child to read and write.</li><li>Set up or support a community reading corner.</li><li>Donate books in Indian languages.</li></ul>', NULL, 'India', '2027-09-08 00:00:00', '2027-09-08 23:59:00', 0, NULL, 'published'),
('World Suicide Prevention Day 2027', 'world-suicide-prevention-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Suicide Prevention Day, observed on 10 September, is organised by the International Association for Suicide Prevention with the World Health Organization to raise awareness that suicide can be prevented.', '<p>World Suicide Prevention Day, observed on 10 September, is organised by the International Association for Suicide Prevention with the World Health Organization to raise awareness that suicide can be prevented.</p><h3>How you can take part</h3><ul><li>Check in on friends and family who may be struggling, and listen without judgement.</li><li>Know where to find help: Tele-MANAS, India''s free mental health helpline, is 14416.</li><li>Help reduce the stigma of seeking mental health support.</li></ul>', NULL, 'India', '2027-09-10 00:00:00', '2027-09-10 23:59:00', 0, NULL, 'published'),
('World Heart Day 2027', 'world-heart-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Heart Day on 29 September, led by the World Heart Federation, raises awareness about cardiovascular disease and how to prevent it.', '<p>World Heart Day on 29 September, led by the World Heart Federation, raises awareness about cardiovascular disease and how to prevent it.</p><h3>How you can take part</h3><ul><li>Get your blood pressure checked regularly.</li><li>Eat less salt, stay active and avoid tobacco.</li><li>Learn the warning signs of a heart attack and act fast.</li></ul>', NULL, 'India', '2027-09-29 00:00:00', '2027-09-29 23:59:00', 0, NULL, 'published'),
('International Day of Older Persons 2027', 'international-day-of-older-persons-2027', 'awareness_day', NULL, 'The United Nations has observed the International Day of Older Persons on 1 October since 1991 to recognise the contribution of older people and the challenges they face.', '<p>The United Nations has observed the International Day of Older Persons on 1 October since 1991 to recognise the contribution of older people and the challenges they face.</p><h3>How you can take part</h3><ul><li>Spend time with older people in your family and neighbourhood.</li><li>Help seniors use digital services and claim pensions and health schemes.</li><li>Make homes and public spaces safe and accessible for them.</li></ul>', NULL, 'India', '2027-10-01 00:00:00', '2027-10-01 23:59:00', 0, NULL, 'published'),
('National Voluntary Blood Donation Day 2027', 'national-voluntary-blood-donation-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'National Voluntary Blood Donation Day is observed in India on 1 October to promote regular, voluntary and unpaid blood donation.', '<p>National Voluntary Blood Donation Day is observed in India on 1 October to promote regular, voluntary and unpaid blood donation.</p><h3>How you can take part</h3><ul><li>Donate blood if you are healthy and eligible.</li><li>Organise or join a blood donation camp at your college or workplace.</li><li>Bust myths about blood donation among friends and family.</li></ul>', NULL, 'India', '2027-10-01 00:00:00', '2027-10-01 23:59:00', 0, NULL, 'published'),
('Gandhi Jayanti 2027', 'gandhi-jayanti-2027', 'awareness_day', NULL, 'Gandhi Jayanti, on 2 October, marks the birth anniversary of Mahatma Gandhi.', '<p>Gandhi Jayanti, on 2 October, marks the birth anniversary of Mahatma Gandhi. The United Nations also observes the day as the International Day of Non-Violence.</p><h3>How you can take part</h3><ul><li>Join a cleanliness drive in your area.</li><li>Practise and teach non-violence and respect for all.</li><li>Choose local and handmade products where you can.</li></ul>', NULL, 'India', '2027-10-02 00:00:00', '2027-10-02 23:59:00', 0, NULL, 'published'),
('World Mental Health Day 2027', 'world-mental-health-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'World Mental Health Day, first observed in 1992 by the World Federation for Mental Health, raises awareness of mental health and mobilises support for it.', '<p>World Mental Health Day, first observed in 1992 by the World Federation for Mental Health, raises awareness of mental health and mobilises support for it.</p><h3>How you can take part</h3><ul><li>Talk openly about mental health and listen without judgement.</li><li>Know where to find help: Tele-MANAS (14416) offers free mental health support.</li><li>Look after your own sleep, routine and social connections.</li></ul>', NULL, 'India', '2027-10-10 00:00:00', '2027-10-10 23:59:00', 0, NULL, 'published'),
('International Day of the Girl Child 2027', 'international-day-of-the-girl-child-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'women-empowerment' LIMIT 1), 'The United Nations has observed the International Day of the Girl Child on 11 October since 2012 to recognise girls'' rights and the challenges they face.', '<p>The United Nations has observed the International Day of the Girl Child on 11 October since 2012 to recognise girls'' rights and the challenges they face.</p><h3>How you can take part</h3><ul><li>Help girls stay in school and continue their education.</li><li>Support girls to learn digital and life skills.</li><li>Speak out against child marriage.</li></ul>', NULL, 'India', '2027-10-11 00:00:00', '2027-10-11 23:59:00', 0, NULL, 'published'),
('Global Handwashing Day 2027', 'global-handwashing-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'healthcare' LIMIT 1), 'Global Handwashing Day, observed on 15 October since 2008, promotes handwashing with soap as an easy and affordable way to prevent disease.', '<p>Global Handwashing Day, observed on 15 October since 2008, promotes handwashing with soap as an easy and affordable way to prevent disease.</p><h3>How you can take part</h3><ul><li>Wash hands with soap before eating and after using the toilet.</li><li>Teach children handwashing with a simple demonstration.</li><li>Make soap and water available in schools and anganwadis.</li></ul>', NULL, 'India', '2027-10-15 00:00:00', '2027-10-15 23:59:00', 0, NULL, 'published'),
('International Day of Rural Women 2027', 'international-day-of-rural-women-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'rural-development' LIMIT 1), 'The United Nations observes the International Day of Rural Women on 15 October to recognise the role of rural women in agriculture, food security and rural development.', '<p>The United Nations observes the International Day of Rural Women on 15 October to recognise the role of rural women in agriculture, food security and rural development.</p><h3>How you can take part</h3><ul><li>Buy from rural women''s self-help groups and producer collectives.</li><li>Help rural women access training, credit and government schemes.</li><li>Value and share the unpaid work women do at home and on farms.</li></ul>', NULL, 'India', '2027-10-15 00:00:00', '2027-10-15 23:59:00', 0, NULL, 'published'),
('World Food Day 2027', 'world-food-day-2027', 'awareness_day', (SELECT id FROM programs WHERE slug = 'food-distribution' LIMIT 1), 'World Food Day, on 16 October, marks the founding of the Food and Agriculture Organization of the United Nations in 1945 and promotes action against hunger.', '<p>World Food Day, on 16 October, marks the founding of the Food and Agriculture Organization of the United Nations in 1945 and promotes action against hunger.</p><h3>How you can take part</h3><ul><li>Do not waste food - plan meals and share surplus food safely.</li><li>Support food banks and community kitchens.</li><li>Help eligible families access ration and nutrition schemes.</li></ul>', NULL, 'India', '2027-10-16 00:00:00', '2027-10-16 23:59:00', 0, NULL, 'published'),
('International Day for the Eradication of Poverty 2027', 'international-day-for-the-eradication-of-poverty-2027', 'awareness_day', NULL, 'Observed on 17 October since 1993, this United Nations day recognises the efforts and struggles of people living in poverty and calls for action to end it.', '<p>Observed on 17 October since 1993, this United Nations day recognises the efforts and struggles of people living in poverty and calls for action to end it.</p><h3>How you can take part</h3><ul><li>Support livelihood and skill training programmes.</li><li>Help families learn about and apply for welfare schemes.</li><li>Volunteer your time or skills with organisations working with communities in need.</li></ul>', NULL, 'India', '2027-10-17 00:00:00', '2027-10-17 23:59:00', 0, NULL, 'published');

-- Page sections (pages are registered in the core seed)
DELETE FROM page_sections WHERE page_id IN (SELECT id FROM pages WHERE slug IN ('awareness-calendar', 'blog', 'events-page', 'gallery', 'media', 'success-stories', 'video-gallery'));

SET @p := (SELECT id FROM pages WHERE slug = 'awareness-calendar');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'split', NULL, 'Awareness', 'Why awareness days matter', 'National and international awareness days are a chance for everyone to learn something new and take one small, useful action.', '<p>This calendar lists important days observed in India and around the world - for health, education, the environment, women and children, rights and social justice - together with simple ways to take part. Events organised by the foundation are shown alongside them.</p>', 'assets/images/hero/events.svg', '["Learn the facts behind each day","Share reliable information with family and friends","Plan an activity in your school, college or neighbourhood","Add any day or event to your phone calendar"]', '{"image_alt":"","reverse":true,"buttons":[{"label":"Upcoming events","url":"/events-page/","style":"primary","icon":"calendar"}]}', 'tint', 'visible', 20),
(@p, 'features', NULL, 'Ideas', 'Ways to mark an awareness day', 'Simple activities that schools, colleges, workplaces and community groups can organise.', NULL, NULL, '[{"icon":"book","title":"Talks & quizzes","text":"A short session or quiz in a classroom, office or community hall."},{"icon":"megaphone","title":"Rallies & street plays","text":"Slogans, posters and nukkad natak that carry the message into the neighbourhood."},{"icon":"activity","title":"Screening camps","text":"Health days are a good time for check-ups and referrals with local health workers."},{"icon":"leaf","title":"Clean-up & plantation","text":"Environment days are ideal for a clean-up, plantation or waste segregation drive."},{"icon":"share","title":"Share it online","text":"Share accurate information from trusted sources on social media and messaging groups."},{"icon":"users","title":"Volunteer","text":"Give a few hours to an organisation working on the cause."}]', '{"cols":"3","style":"soft"}', 'white', 'visible', 30),
(@p, 'cta', NULL, NULL, 'Organise an awareness activity with us', 'Tell us which day you would like to mark and where - our team and volunteers can help you plan a meaningful activity.', NULL, NULL, NULL, '{"buttons":[{"label":"Plan an activity","url":"/awareness-campaign/","style":"donate","icon":"megaphone","size":"lg"},{"label":"Contact us","url":"/contact/","style":"light","size":"lg"}]}', 'white', 'visible', 40);

SET @p := (SELECT id FROM pages WHERE slug = 'blog');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'features', 'about-blog', 'Our blog', 'What you will find here', 'Updates and ideas from the work of Prerit Welfare Foundation, written by our team and volunteers.', NULL, NULL, '[{"icon":"book","title":"Notes from the field","text":"What we learn while running literacy drives, health camps, skill training and relief work - the challenges as well as the progress."},{"icon":"bell","title":"News & announcements","text":"New programmes, upcoming camps and drives, volunteer calls and other updates from the foundation."},{"icon":"file-text","title":"Guides & explainers","text":"Practical, easy-to-read guides on health, education, welfare schemes, rights and financial literacy."},{"icon":"leaf","title":"Ideas for change","text":"Simple actions anyone can take for cleaner neighbourhoods, healthier families and stronger communities."}]', '{"cols":"4","style":"soft"}', 'tint', 'visible', 20),
(@p, 'callout', NULL, NULL, 'Our editorial standards', 'We publish only information we have checked. Photos and personal stories of the people we work with appear only with their consent, and we correct mistakes as soon as we know about them - write to us if you spot one.', NULL, NULL, NULL, '{"tone":"green","icon":"shield","width":"narrow"}', 'white', 'visible', 30),
(@p, 'link_cards', NULL, 'Keep reading', 'Explore more', NULL, NULL, NULL, '[{"icon":"star","title":"Success stories","text":"Change, in people’s own words","url":"/success-stories/"},{"icon":"megaphone","title":"Media / press releases","text":"Official statements and media resources","url":"/media/"},{"icon":"calendar","title":"Events","text":"Camps, drives and community programmes","url":"/events-page/"},{"icon":"mail","title":"Newsletter","text":"Occasional updates in your inbox","url":"/newsletter/"}]', NULL, 'white', 'visible', 40),
(@p, 'cta', NULL, NULL, 'Have a story idea or want to write for us?', 'Volunteers, partners and well-wishers are welcome to suggest topics or contribute articles about education, health, women empowerment and the environment.', NULL, NULL, NULL, '{"buttons":[{"label":"Write to us","url":"/contact/","style":"donate","icon":"mail","size":"lg"},{"label":"Volunteer with us","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 50);

SET @p := (SELECT id FROM pages WHERE slug = 'events-page');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'features', NULL, 'What we do', 'Events we organise', 'Our events bring services, information and people together in the communities we work with.', NULL, NULL, '[{"icon":"activity","title":"Health camps & screenings","text":"Basic check-ups, screenings, health awareness and referral support for families."},{"icon":"megaphone","title":"Awareness drives","text":"Sessions on health, hygiene, rights, financial literacy and government welfare schemes."},{"icon":"book","title":"Learning & skill workshops","text":"Literacy drives, study support, career guidance and vocational and digital skill sessions."},{"icon":"leaf","title":"Plantation & clean-up drives","text":"Tree plantation, waste management and sustainable-living activities with volunteers."},{"icon":"package","title":"Distribution & relief drives","text":"Food, essential supplies and hygiene kits, coordinated with volunteers when communities need them."},{"icon":"monitor","title":"Webinars & online sessions","text":"Online talks and trainings for volunteers, students and community members."}]', '{"cols":"3"}', 'tint', 'visible', 20),
(@p, 'steps', NULL, 'Join us', 'How to take part', NULL, NULL, NULL, '[{"icon":"search","title":"Find an event","text":"Browse upcoming events and filter by type."},{"icon":"edit","title":"Register online","text":"Fill in the short form on the event page - it takes a minute."},{"icon":"mail","title":"Get your confirmation","text":"You receive an email with the details and a calendar file."},{"icon":"users","title":"Join us on the day","text":"Arrive a few minutes early - our volunteers will guide you."}]', NULL, 'white', 'visible', 30),
(@p, 'faqs', NULL, 'FAQ', 'Questions about our events', NULL, NULL, NULL, '[{"q":"Do I need to register?","a":"Where registration is open, please register on the event page so we can plan space, material and volunteers. Awareness days listed in our calendar do not need registration."},{"q":"How will I know my registration is confirmed?","a":"You receive a confirmation email straight after registering, with the date, time, place and a calendar file. Please check your spam folder if you do not see it."},{"q":"I can no longer attend. What should I do?","a":"Please reply to the confirmation email or contact us so that your place can be offered to someone else."},{"q":"Can my school, college or organisation host an event with you?","a":"Yes. Write to us with the place, the community you work with and the kind of activity you have in mind, and our team will get in touch."}]', '{"width":"narrow"}', 'tint', 'visible', 40),
(@p, 'cta', NULL, NULL, 'Host an event with us', 'Invite us to plan a health camp, awareness session, plantation drive or skill workshop with your school, college, workplace, housing society or village.', NULL, NULL, NULL, '{"buttons":[{"label":"Contact us","url":"/contact/","style":"donate","icon":"mail","size":"lg"},{"label":"Volunteer at events","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 50);

SET @p := (SELECT id FROM pages WHERE slug = 'gallery');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'callout', NULL, NULL, 'Photos are shared with consent', 'We publish photos of identifiable children and vulnerable people only when documented consent has been obtained. If you appear in a photo and would like it removed, write to us and we will take it down.', NULL, NULL, NULL, '{"tone":"green","icon":"shield","width":"narrow","size":"sm"}', 'white', 'visible', 20),
(@p, 'link_cards', NULL, 'Explore', 'See more of our work', NULL, NULL, NULL, '[{"icon":"play","title":"Video gallery","text":"Watch our work on the ground","url":"/video-gallery/"},{"icon":"calendar","title":"Events","text":"Camps, drives and community programmes","url":"/events-page/"},{"icon":"grid","title":"Our programmes","text":"Education, health, women, environment and more","url":"/programs/"},{"icon":"star","title":"Success stories","text":"Change, in people’s own words","url":"/success-stories/"}]', NULL, 'tint', 'visible', 30),
(@p, 'cta', NULL, NULL, 'Be part of the next picture', 'Join our volunteers at the next camp or drive, or support the work you see here.', NULL, NULL, NULL, '{"buttons":[{"label":"Volunteer with us","url":"/become-a-volunteer/","style":"donate","icon":"heart","size":"lg"},{"label":"Donate","url":"/donate/","style":"light","size":"lg"}]}', 'white', 'visible', 40);

SET @p := (SELECT id FROM pages WHERE slug = 'media');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'split', NULL, 'Media enquiries', 'For journalists and media', 'We welcome enquiries from journalists, researchers and students who want to know more about our work.', '<p>Press releases issued by the foundation are published on this page. For interviews, information about a programme or permission to use our photographs, please write to us through the contact page with your name, publication and deadline.</p>', 'assets/images/hero/blog.svg', '["Information about our programmes and activities","Interviews with our team on request","Photographs used only with the consent of the people in them","Official organisation details on our NGO Details page"]', '{"image_alt":"","buttons":[{"label":"Contact us","url":"/contact/","style":"primary","icon":"mail"},{"label":"NGO details","url":"/ngo-details/","style":"outline"}]}', 'tint', 'visible', 20),
(@p, 'features', NULL, 'Resources', 'Media resources', 'Official sources of information about Prerit Welfare Foundation.', NULL, NULL, '[{"icon":"file-text","title":"Press releases","text":"Official statements are published on this page with their date of issue."},{"icon":"download","title":"Digital resources","text":"Guides and material shared by the foundation.","link":"/digital-resources/","link_label":"Open resources"},{"icon":"shield","title":"NGO details","text":"Registration and organisation details, as published by the foundation.","link":"/ngo-details/","link_label":"View details"},{"icon":"image","title":"Photo & video gallery","text":"Moments from our programmes and events.","link":"/gallery/","link_label":"Open gallery"}]', '{"cols":"4"}', 'white', 'visible', 30),
(@p, 'callout', NULL, NULL, 'Please verify before you publish', 'Confirm any information attributed to Prerit Welfare Foundation with us directly before publishing it. If you come across a suspicious request for money made in our name, please tell us through the Report Fraud page.', NULL, NULL, NULL, '{"tone":"gold","icon":"alert-circle","width":"narrow"}', 'white', 'visible', 40),
(@p, 'faqs', NULL, 'FAQ', 'Questions from the media', NULL, NULL, NULL, '[{"q":"How can I request an interview or information?","a":"Write to us through the contact page with your name, publication, topic and deadline. We will get back to you as soon as possible."},{"q":"Can I use photos from your gallery?","a":"Please ask us first. Photos of people are shared with their consent for the foundation’s own communication, so any other use needs our permission."},{"q":"Where can I find official details about the foundation?","a":"Registration and organisation details are on our NGO Details page, and certificates and reports are listed on their pages as they are published."}]', '{"width":"narrow"}', 'tint', 'visible', 50);

SET @p := (SELECT id FROM pages WHERE slug = 'success-stories');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'callout', NULL, NULL, 'Our consent & dignity policy', 'Every story here is published only with the written consent of the person it is about - and of a parent or guardian for anyone under 18. We tell stories in people’s own words, never use images that take away their dignity, change names or details on request and remove a story whenever the person asks us to.', NULL, NULL, NULL, '{"tone":"green","icon":"shield","width":"narrow","size":"sm"}', 'white', 'visible', 10),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'features', NULL, 'Our approach', 'How we tell stories', 'Stories help others understand what change looks like. We share them carefully.', NULL, NULL, '[{"icon":"shield","title":"Consent first","text":"Written consent is recorded before a story or photo is published, and it can be withdrawn at any time."},{"icon":"message","title":"In their own words","text":"We share people’s experiences as they tell them, without exaggeration."},{"icon":"heart","title":"Dignity always","text":"No pictures of distress or need used to attract sympathy - people are shown with respect."},{"icon":"lock","title":"Privacy protected","text":"Names, places and faces are changed or hidden when the person prefers it or when it keeps them safe."}]', '{"cols":"4","style":"soft"}', 'tint', 'visible', 30),
(@p, 'steps', NULL, 'The process', 'How a story is shared', NULL, NULL, NULL, '[{"icon":"message","title":"Conversation","text":"A team member or volunteer listens to the person’s experience."},{"icon":"file-text","title":"Written consent","text":"The person (or guardian) agrees in writing to what will be shared and how."},{"icon":"eye","title":"Review","text":"The story and photos are shown to the person before publication."},{"icon":"check-circle","title":"Publication","text":"Only then is the story published - and it is removed whenever they ask."}]', NULL, 'white', 'visible', 40),
(@p, 'cta', NULL, NULL, 'Part of our programmes? Share your experience', 'If our work has made a difference to you or your family and you would like to share it, we would love to hear from you.', NULL, NULL, NULL, '{"buttons":[{"label":"Write to us","url":"/contact/","style":"donate","icon":"mail","size":"lg"},{"label":"Support our work","url":"/donate/","style":"light","size":"lg"}]}', 'white', 'visible', 50);

SET @p := (SELECT id FROM pages WHERE slug = 'video-gallery');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'callout', NULL, NULL, 'Privacy-friendly videos', 'Videos are hosted on YouTube. Until you press play, only a preview image is shown from the YouTube image server; the video player itself loads only when you press play, in the privacy-enhanced (youtube-nocookie.com) mode. People appear in our videos only with their consent.', NULL, NULL, NULL, '{"tone":"blue","icon":"shield","width":"narrow","size":"sm"}', 'white', 'visible', 20),
(@p, 'link_cards', NULL, 'Explore', 'See more of our work', NULL, NULL, NULL, '[{"icon":"image","title":"Photo gallery","text":"Moments from our programmes and events","url":"/gallery/"},{"icon":"calendar","title":"Events","text":"Camps, drives and community programmes","url":"/events-page/"},{"icon":"file-text","title":"Blogs & articles","text":"Updates and ideas from our work","url":"/blog/"},{"icon":"mail","title":"Newsletter","text":"Occasional updates in your inbox","url":"/newsletter/"}]', NULL, 'tint', 'visible', 30),
(@p, 'cta', NULL, NULL, 'Help us do more', 'Your time and support make this work possible.', NULL, NULL, NULL, '{"buttons":[{"label":"Donate","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Volunteer with us","url":"/become-a-volunteer/","style":"light","size":"lg"}]}', 'white', 'visible', 40);

-- ---------------------------------------------------------------------
-- Module M7
-- ---------------------------------------------------------------------
-- =====================================================================
-- M7 Home page: sections of the "home" page (Admin > Pages > Home).
-- Descriptive content only: no donations, figures, testimonials or impact numbers.
-- The impact counters section shows LIVE verified figures from the database and hides itself while they are zero;
-- module sections (programmes, campaigns, events, stories, gallery, testimonials, articles) render nothing until
-- published data exists.
-- Re-runnable: removes only the home page's sections first.
-- =====================================================================
SET NAMES utf8mb4;

DELETE FROM page_sections WHERE page_id IN (SELECT id FROM pages WHERE slug = 'home');
SET @home := (SELECT id FROM pages WHERE slug = 'home');

INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES

-- 1. Hero slider (the first slide's title is the page's <h1>)
(@home, 'hero_slider', 'welcome', NULL, NULL, NULL, NULL, NULL,
'[{"eyebrow":"Prerit Welfare Foundation · New Delhi","title":"मिलकर बनाये बेहतर कल – Together, let us build a better tomorrow","text":"We work to empower underprivileged communities through education, healthcare, women empowerment, environmental care and skill development – so that everyone can live with dignity and equal opportunity.","image":"assets/images/hero/community.svg","image_alt":"","button1_label":"Donate Now","button1_url":"/donate/","button2_label":"Become a Volunteer","button2_url":"/become-a-volunteer/"},
{"eyebrow":"Education & literacy","title":"Every child deserves the chance to learn","text":"Through literacy drives, community learning circles, study material distribution and school support, we help children from underserved families keep learning.","image":"assets/images/programs/education.svg","image_alt":"","button1_label":"Our Programmes","button1_url":"/programs/","button2_label":"Teach with us","button2_url":"/volunteer-teacher/"},
{"eyebrow":"Women & youth empowerment","title":"Skills, confidence and opportunity for women and young people","text":"Skill development for women, Self-Help Group support, career guidance, mentoring and digital skills – and volunteers like you help make it possible.","image":"assets/images/programs/women-empowerment.svg","image_alt":"","button1_label":"Become a Volunteer","button1_url":"/become-a-volunteer/","button2_label":"Donate Now","button2_url":"/donate/"}]',
'{"label":"Highlights from our work"}', 'white', 'visible', 10),

-- 2. Slim band with our areas of work
(@home, 'marquee_cta', 'areas-of-work', NULL, NULL, NULL, NULL, NULL,
'["Education & Literacy","Healthcare","Women Empowerment","Youth Empowerment","Rural Development","Environment Protection","Skill Development","Digital Learning","Scholarships","Food Distribution","Disaster Relief"]',
'{"message":"Our areas of work","icon":"leaf","tone":"gold","size":"sm","buttons":[{"label":"Explore our programmes","url":"/programs/","style":"primary","size":"sm","icon":"arrow-right"}]}',
'white', 'visible', 15),

-- 3. Live, verified impact counters (hidden while every figure is zero)
(@home, 'impact_counters', 'impact', 'Our impact', 'Progress you can verify',
'Every figure below is calculated live from our records: only confirmed donations, active volunteers and events that have already taken place are counted.',
NULL, NULL, NULL, '{"align":"center","note":"Live figures from verified records"}', 'green', 'visible', 20),

-- 4. About the foundation
(@home, 'split', 'about', 'Who we are', 'A nonprofit working for a fair and inclusive society',
'Prerit Welfare Foundation is a nonprofit organization dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care, and skill development.',
'<p>We strive to build a fair and inclusive society where everyone can live with dignity and equal opportunity. Based in New Delhi, we work alongside volunteers, teachers and local communities – from literacy drives and health camps to skill training, awareness campaigns and relief work.</p>',
'assets/images/hero/about.svg',
'["Education & literacy","Healthcare & awareness","Women & youth empowerment","Environmental care","Skill development & livelihoods","Food & disaster relief"]',
'{"image_alt":"","buttons":[{"label":"About us","url":"/about/","style":"primary","icon":"arrow-right"},{"label":"Mission & vision","url":"/our-mission-vision/","style":"outline"}]}',
'white', 'visible', 30),

-- 5. Programmes (M2)
(@home, 'programs_grid', 'programs', 'Our work', 'Our programmes',
'From classrooms to community health camps, our programmes respond to the everyday needs of the communities we serve.',
NULL, NULL, NULL, '{"limit":8,"home_only":true,"align":"center"}', 'tint', 'visible', 40),

-- 6. Ways to get involved (M4)
(@home, 'apply_cards', 'get-involved', 'Get involved', 'Get involved',
'Give your time, share your knowledge or grow with us – there is a place for everyone in our work.',
NULL, NULL, NULL, '{"align":"center"}', 'white', 'visible', 50),

-- 7. Active campaigns (M3)
(@home, 'campaigns_grid', 'campaigns', 'Fundraising', 'Active campaigns',
'Choose a cause to support. Progress shown on every campaign comes from verified donations only.',
NULL, NULL, NULL, '{"status":"active","limit":3}', 'cream', 'visible', 60),

-- 8. General donation appeal (M3)
(@home, 'donation_appeal', 'donate', 'Donate', 'Support our work',
'Your donation helps us run literacy drives, health camps, skill training and relief work. Every confirmed donation receives a receipt.',
NULL, NULL, NULL, '{}', 'tint', 'visible', 70),

-- 9. Upcoming events (M6)
(@home, 'events_list', 'events', 'Events', 'Upcoming events',
'Camps, drives, awareness programmes and community events you can be part of.',
NULL, NULL, NULL, '{"when":"upcoming","limit":3}', 'white', 'visible', 80),

-- 10. Awareness days (M6)
(@home, 'awareness_days', 'awareness-days', 'Awareness calendar', 'Upcoming awareness days',
'National and international observances coming up - a good time to spread awareness in your community. Want to organise an activity? Talk to us.',
NULL, NULL, NULL, '{"limit":4}', 'tint', 'visible', 90),

-- 11. Success stories (M6 - only stories published with consent)
(@home, 'stories_list', 'stories', 'Stories of change', 'Success stories',
'Real stories from the communities we work with, shared with the consent of the people in them.',
NULL, NULL, NULL, '{"limit":3}', 'white', 'visible', 100),

-- 12. Gallery (M6)
(@home, 'gallery_strip', 'gallery', 'On the ground', 'Our work in pictures',
'Moments from our programmes, events and campaigns.',
NULL, NULL, NULL, '{"limit":8,"media":"image","align":"center"}', 'tint', 'visible', 110),

-- 13. Testimonials (M8 - consented, published testimonials only)
(@home, 'testimonials', 'voices', 'Voices', 'What people say',
'In their own words – shared with their permission.',
NULL, NULL, NULL, '{"limit":3,"align":"center"}', 'cream', 'visible', 120),

-- 14. Latest articles (M6)
(@home, 'posts_list', 'news', 'News & articles', 'From our blog',
'Updates, ideas and stories from our work.',
NULL, NULL, NULL, '{"category":"blog","limit":3}', 'white', 'visible', 130),

-- 15. Trust markers
(@home, 'trust_strip', 'trust', 'Why trust us', 'Giving with confidence',
'We are committed to accountability for every rupee and to protecting your information.',
NULL, NULL,
'[{"icon":"shield","title":"Verified payments","text":"Online donations go through a secure payment gateway and are counted only after our server confirms the payment.","link":"/financial-transparency/","link_label":"Financial transparency"},
{"icon":"file-text","title":"Receipts for every confirmed donation","text":"Each confirmed donation receives a receipt by email, and registered donors can download their receipts from their account.","link":"/donation-refund-policy/","link_label":"Donation & refund policy"},
{"icon":"book","title":"Published policies","text":"Our privacy, volunteer and child protection policies are public, so you always know how we work.","link":"/child-protection-policy/","link_label":"Child protection policy"},
{"icon":"lock","title":"Data protection","text":"We collect only the details we need, protect accounts with secure sign-in, and our website statistics never store IP addresses.","link":"/privacy-policy/","link_label":"Privacy policy"}]',
'{"align":"center"}', 'tint', 'visible', 140),

-- 16. Newsletter (M8)
(@home, 'newsletter_signup', 'newsletter', 'Stay connected', 'Get updates on our work',
'Occasional emails about our programmes, events and campaigns. You can unsubscribe at any time.',
NULL, NULL, NULL, '{"no_heading":true}', 'white', 'visible', 150),

-- 17. Closing call to action with the Hindi tagline
(@home, 'cta', 'join-us', NULL, 'Be a part of the change',
'Start a fundraiser for a cause you care about, or become a member of the foundation and support our mission.',
NULL, NULL, NULL,
'{"hindi_tagline":true,"buttons":[{"label":"Start a Fundraiser","url":"/start-a-fundraiser/","style":"donate","icon":"flag","size":"lg"},{"label":"Become a Member","url":"/membership/","style":"light","icon":"id-card","size":"lg"}]}',
'white', 'visible', 160);

-- ---------------------------------------------------------------------
-- Module M8
-- ---------------------------------------------------------------------
-- =====================================================================
-- M8 Information & policies: published FAQs and the content sections of the contact, grievance,
-- fraud-report, feedback, FAQ, newsletter, team, NGO details, documents and policy pages.
-- Descriptive / policy content only: no team members, testimonials, documents or registration
-- numbers are seeded (they are added by the foundation in the admin panel when verified).
-- Re-runnable: removes only the sections of the pages below and the FAQs inserted here.
-- =====================================================================
SET NAMES utf8mb4;

DELETE FROM page_sections WHERE page_id IN (SELECT id FROM pages WHERE slug IN ('privacy-policy', 'terms-conditions', 'donation-refund-policy', 'volunteer-policy', 'child-protection-policy', 'contact', 'grievance-redressal', 'report-fraud', 'feedback-form', 'faq', 'newsletter', 'management-body', 'volunteers', 'ngo-details', 'certifications', 'digital-resources', 'impact-reports'));
DELETE FROM faqs WHERE question IN (
  'What does Prerit Welfare Foundation do?',
  'How can I check that a call, message or request really comes from the foundation?',
  'Where can I find the foundation''s registration details?',
  'How can my company or organisation partner with you or support our work through CSR?',
  'Can our school, college or community group invite you for an awareness session or camp?',
  'How can I donate online?',
  'Can I donate by bank transfer or cheque?',
  'When will I receive my donation receipt?',
  'Will my donation qualify for a tax deduction under Section 80G?',
  'Can I donate from outside India or as a foreign national?',
  'Can I get a refund of my donation?',
  'My payment failed but money was debited from my account. What should I do?',
  'How can I see how donations are used?',
  'Can I donate without my name being shown publicly?',
  'How do I become a volunteer?',
  'Do I have to pay any fee to volunteer, intern or apply for a job?',
  'Will I receive a certificate for volunteering?',
  'How does the Volunteer Teacher Program work, and how are children kept safe?',
  'How does the Volunteer Teacher Programme work, and how are children kept safe?',
  'Do you offer internships?',
  'How do I apply for a job with the foundation?',
  'How can I request help for myself or someone I know?',
  'How do I apply for a scholarship?',
  'How can I check the status of my application?',
  'Can you help me apply for government welfare schemes?',
  'Do I need an account to donate or apply?',
  'How is my personal information protected?',
  'How can I correct my data, download it or delete my account?',
  'I forgot my password. How can I sign in?',
  'How do I report fraud or misuse of the foundation''s name?',
  'How do I register for an event?',
  'Can I volunteer at an event or awareness drive?');

-- ---------------------------------------------------------------------
-- FAQs (Admin > FAQs). Also used by the FAQ page, FAQ sections and the help assistant.
-- ---------------------------------------------------------------------
INSERT INTO faqs (question, answer, category, sort_order, status) VALUES
('What does Prerit Welfare Foundation do?', 'Prerit Welfare Foundation is a nonprofit organization based in New Delhi, dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care and skill development.

Our activities include literacy drives and community learning circles, health camps and awareness programmes, skill training and Self-Help Group support for women, career guidance for young people, tree plantation and waste management drives, and relief work such as food and essential supplies during emergencies. The Our Programmes page describes each area in detail.', 'general', 10, 'published'),
('How can I check that a call, message or request really comes from the foundation?', 'Use only the contact details published on this website (Contact Us page) to verify any request made in our name. We never ask for an OTP, password, PIN or card details, we never charge a fee for jobs, internships, volunteering or certificates, and donations should be made only through the Donate page of this website.

If something feels wrong, do not pay. Report it to us on the Report Fraud page. If you have lost money, call the national cyber crime helpline 1930 or report at cybercrime.gov.in as soon as possible.', 'general', 20, 'published'),
('Where can I find the foundation''s registration details?', 'Registration details such as the registration number, PAN, 12A, 80G, CSR-1 and NITI Aayog Darpan ID are published on the NGO Details page once they have been verified and entered by the foundation. Copies of certificates are added to the Certificates page as they become available. Please rely only on details shown on this website.', 'general', 30, 'published'),
('How can my company or organisation partner with you or support our work through CSR?', 'Write to us using the Contact Us page and choose "Partnership / CSR collaboration" as the type of enquiry. Tell us about your organisation, the cause or location you are interested in and how you would like to collaborate. Our team will get back to you and share the documents that are available for due diligence.', 'general', 40, 'published'),
('Can our school, college or community group invite you for an awareness session or camp?', 'Yes, we welcome such requests. Send us the details through the Contact Us page - the topic you have in mind (for example health, hygiene, environment, digital literacy or career guidance), the location, the expected group size and possible dates. We will let you know whether and when we can organise it.', 'general', 50, 'published'),
('How can I donate online?', 'When online donation is available, go to the Donate page (or choose a campaign on the Campaigns page), enter the amount and your details, and complete the payment in the secure Razorpay checkout using UPI, debit or credit card, net banking or another method offered there.

Your card, UPI and bank login details are handled by the payment gateway - they are never seen or stored by the foundation.

If the Donate page says online donation is not available, write to us and we will share the current ways to donate.', 'donations', 10, 'published'),
('Can I donate by bank transfer or cheque?', 'Offline donations are possible only to the bank details published by the foundation on the Donate page. Never transfer money to a personal account or to account details shared by phone, WhatsApp or social media.

After an offline donation, please write to us with the date, amount and transaction reference (or cheque number) so that we can record it and issue a receipt once the amount is received in our account.', 'donations', 20, 'published'),
('When will I receive my donation receipt?', 'A receipt is issued only after the payment has been confirmed by the payment gateway and verified by our system. It is sent to the email address you entered, and if you have an account with the same email address you can also download it from your dashboard under My donations.

For offline donations, the receipt is issued after the amount has been received in the foundation''s bank account and reconciled.', 'donations', 30, 'published'),
('Will my donation qualify for a tax deduction under Section 80G?', 'A deduction under Section 80G of the Income Tax Act is possible only if the foundation holds a valid 80G registration. When it does, the 80G registration number is shown on the NGO Details page and on your receipt, and you will need to provide your PAN when donating.

If no 80G registration is listed on the NGO Details page, please do not expect a tax benefit for your donation.

Cash donations above ₹2,000 do not qualify for deduction under Section 80G. The deduction is claimed on the basis of the Form 10BE certificate that the foundation issues after filing Form 10BD, so please give your PAN when donating.', 'donations', 40, 'published'),
('Can I donate from outside India or as a foreign national?', 'Indian citizens, including those living abroad, can donate from their own funds through Indian bank accounts, cards or UPI.

Unless an FCRA registration is listed on our NGO Details page, the foundation cannot accept foreign contributions under the Foreign Contribution (Regulation) Act, 2010 - that is, donations from foreign nationals (including OCI card holders), foreign companies, foreign trusts or any other foreign source. Even when an FCRA registration is listed, foreign contributions can be received only in our designated FCRA bank account, so please contact us first. A foreign contribution received by mistake will be returned.', 'donations', 45, 'published'),
('Can I get a refund of my donation?', 'Donations are voluntary and are generally not refundable once received. We do refund duplicate payments and donations made in error (for example, a wrong amount entered by mistake). Please contact us as soon as possible with the date, amount and transaction reference.

Approved refunds are made to the original payment method through the payment gateway. The full process is explained in our Donation & Refund Policy.', 'donations', 50, 'published'),
('My payment failed but money was debited from my account. What should I do?', 'If a payment is not completed, the amount is usually reversed automatically by your bank or the payment gateway. If it does not reach your account within the period your bank mentions, contact us with the date, amount and any reference shown by your bank or UPI app, and we will check the transaction with the payment gateway.

Please do not repeat a payment several times - check first whether the earlier one was successful.', 'donations', 60, 'published'),
('How can I see how donations are used?', 'Campaign pages show the amount raised from verified donations only, along with updates from the field. The Campaign Utilisation Reports and Financial Transparency pages show how campaign funds have been used as reports are published. Annual and impact reports are added to the Impact Reports page when they are ready.', 'donations', 70, 'published'),
('Can I donate without my name being shown publicly?', 'Yes. Choose the anonymous option when you donate and your name will not be displayed on the website. We still need your name and contact details for the receipt and our records, and these are kept confidential.', 'donations', 80, 'published'),
('How do I become a volunteer?', 'Fill in the application on the Volunteer with Us page, choosing your areas of interest and availability. You will receive a reference number by email. Our volunteer coordinator reviews each application and contacts suitable applicants for a conversation or orientation before assigning activities.

You can follow your application on the Application Status page or in your dashboard.', 'volunteering', 10, 'published'),
('Do I have to pay any fee to volunteer, intern or apply for a job?', 'No. The foundation never charges any fee - for applications, registration, training, interviews, joining, uniforms or certificates. If anyone asks you to pay money for a job, internship or volunteering opportunity in our name, it is a fraud. Please report it on the Report Fraud page.', 'volunteering', 20, 'published'),
('Will I receive a certificate for volunteering?', 'Yes, certificates are issued for verified service. Your coordinator confirms the activities and hours you have completed, and the certificate is then approved and made available for download in your dashboard. Certificates are never sold and cannot be issued without verified participation.', 'volunteering', 30, 'published'),
('How does the Volunteer Teacher Programme work, and how are children kept safe?', 'Apply on the Volunteer Teacher Programme page with your subjects, preferred classes, languages and whether you can teach in person, online or both. Shortlisted applicants are invited for a conversation and an orientation that includes our Child Protection Policy.

Every volunteer teacher agrees to our code of conduct: another adult is always present or the session is in an open, visible space, there is no private contact with children on phone or social media, and photographs are taken only with the consent of parents or guardians.', 'volunteering', 40, 'published'),
('Do you offer internships?', 'Yes. Students and young professionals can apply on the Internships page for areas such as education, health, women empowerment, environment, communication, fundraising and research. Details such as duration, mode (in person or online), tasks and any stipend are confirmed in writing at the time of selection. A certificate is issued on successful completion.', 'volunteering', 50, 'published'),
('How do I apply for a job with the foundation?', 'Open positions, when available, are listed on the Volunteer Staff Application page, where you can apply with your resume. Shortlisted candidates are contacted by email or phone for an interview. We never ask candidates to pay for any part of the selection process.', 'volunteering', 60, 'published'),
('How can I request help for myself or someone I know?', 'Use the Request Help page to tell us about the need - education, health, livelihood, food or relief support - and how to contact you. Our team reviews every request and may call you or ask for documents to understand the situation.

We cannot support every request, but we will let you know the outcome and, where possible, suggest other help or government schemes. In an emergency, please call 112.', 'support', 10, 'published'),
('How do I apply for a scholarship?', 'Apply on the Scholarship Application page with the student''s details, institution, course and family income, and upload supporting documents if you have them. Applications are reviewed by our team and you will receive updates by email. Scholarship support depends on the funds available for the purpose.', 'support', 20, 'published'),
('How can I check the status of my application?', 'Open the Application Status page and enter the reference number you received by email (for example PWF-VOL-2026-00012) along with the contact details you used when applying. If you have an account, all your applications are also listed in your dashboard.', 'support', 30, 'published'),
('Can you help me apply for government welfare schemes?', 'Yes. Our volunteers can explain schemes, help you check your eligibility and documents, and guide you through the official application - free of charge. Verified scheme guides with links to their official sources are published on our Welfare Schemes page as they are checked. Decisions on applications are taken only by the government department concerned - we cannot guarantee approval.', 'support', 40, 'published'),
('Do I need an account to donate or apply?', 'No. You can donate and submit most applications without an account. A free account lets you see all your donations and receipts, follow your applications, register for events and download certificates in one place.', 'accounts', 10, 'published'),
('How is my personal information protected?', 'We collect only the information needed for the purpose you share it for. Passwords are stored in encrypted (hashed) form, uploaded documents such as resumes and ID proofs are kept in private storage that only authorised staff can open, and staff access is limited by role. We never sell your data. Our Privacy Policy explains everything in detail.', 'accounts', 20, 'published'),
('How can I correct my data, download it or delete my account?', 'You can update your profile details from your dashboard at any time. To request a copy of your data, its correction or the deletion of your account, use the data request option in your dashboard or write to us through the Contact Us page.

Some records, such as donation receipts, must be kept for the period required by law even after an account is closed.', 'accounts', 30, 'published'),
('I forgot my password. How can I sign in?', 'Choose "Forgot password" on the login page and enter your email address. If an account exists, we will email you a secure link to choose a new password. The link works only for a limited time and can be used once.', 'accounts', 40, 'published'),
('How do I report fraud or misuse of the foundation''s name?', 'Use the Report Fraud page to tell us what happened, how you were contacted and the number, link or profile that was used. You will receive a reference number. If you have lost money, call the national cyber crime helpline 1930 and report at cybercrime.gov.in immediately, and inform your bank.', 'accounts', 50, 'published'),
('How do I register for an event?', 'Open the event on the Events page and fill in the short registration form. You will receive a confirmation email. Registration is free. If you can no longer attend, please let us know so that someone else can take your place.', 'events', 10, 'published'),
('Can I volunteer at an event or awareness drive?', 'Yes. Registered volunteers are informed about upcoming camps, drives and awareness programmes by their coordinator. If you are not yet a volunteer, apply on the Volunteer with Us page and mention the kind of events you would like to help with.', 'events', 20, 'published');

-- ---------------------------------------------------------------------
-- /privacy-policy/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'privacy-policy');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'richtext', NULL, NULL, 'About this policy', NULL, '<p>Prerit Welfare Foundation ("the foundation", "we", "us") respects your privacy. This policy explains what personal data we collect through this website, why we collect it, how long we keep it, who we share it with and the choices and rights you have.</p>
<p>We follow the principles of India''s <strong>Digital Personal Data Protection Act, 2023</strong> (DPDP Act): we collect personal data only for clear purposes, use it only for those purposes, keep it only as long as needed and protect it with reasonable security safeguards. This policy applies to this website and the online services on it, such as donations, applications, accounts and forms.</p>', NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'richtext', NULL, NULL, 'Information we collect', NULL, '<p>We collect only the information you choose to give us, and a limited amount of technical information needed to run the website securely.</p>
<h3>Account information</h3>
<ul><li>Name, email address and mobile number, and optionally your address, city, state and PIN code.</li><li>Your password, stored only in encrypted (hashed) form - we cannot read it.</li><li>Your communication preferences and the date you accepted this policy.</li></ul>
<h3>Donations</h3>
<ul><li>Name, email, mobile number, and - if you provide them - your PAN and address (needed for tax receipts where applicable).</li><li>Amount, campaign, your message and whether you wish to remain anonymous on the website.</li><li>Payment references from the payment gateway (order and payment IDs, status and payment method type).</li></ul>
<h3>Applications and requests for support</h3>
<ul><li>Volunteer, volunteer teacher, staff and internship applications: contact details, date of birth, gender, education or qualifications, skills, availability, experience and, where requested, a resume.</li><li>Membership, scholarship and help requests: the details needed to assess the request, which may include family and income information and supporting documents.</li><li>Event registrations: name, email and mobile number.</li></ul>
<h3>Messages and newsletter</h3>
<ul><li>Enquiries, grievances, fraud reports and feedback: your contact details, the message and any reference numbers you include.</li><li>Newsletter: your email address, optional name, the consent text you agreed to, when and where you subscribed.</li></ul>
<h3>Uploaded documents</h3>
<p>Resumes, identity or income documents and similar files you upload are stored in private storage. They are not publicly accessible and can be opened only by authorised staff through the secure admin panel.</p>
<h3>Technical information</h3>
<p>When you submit a form or sign in, we record your IP address and browser type together with the action, to prevent spam and misuse, to keep a security record and to investigate problems.</p>', NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'richtext', NULL, NULL, 'How we use your information', NULL, '<ul>
<li>To process donations, verify payments with the payment gateway, issue receipts and keep the accounting records required by law.</li>
<li>To review applications, contact applicants, arrange orientation and interviews, assign volunteer activities and issue certificates for verified service.</li>
<li>To assess and respond to requests for help and scholarships.</li>
<li>To reply to enquiries, resolve grievances and act on reports of fraud or misuse of our name.</li>
<li>To send newsletters - only if you have subscribed.</li>
<li>To manage your account, keep it secure and show you your donations, receipts, applications, certificates and notifications.</li>
<li>To understand, in aggregate, how the website is used so we can improve it.</li>
<li>To meet legal, tax and regulatory obligations.</li>
</ul>
<p>We do not use your personal data for automated decisions that significantly affect you, and we do not sell it or use it for third-party advertising.</p>', NULL, NULL, NULL, 'white', 'visible', 30),
(@p, 'richtext', NULL, NULL, 'Consent and legal basis', NULL, '<p>We process your personal data on the basis of your <strong>consent</strong>, which you give when you submit a form, create an account, donate or subscribe, and for the <strong>legitimate uses</strong> permitted under the DPDP Act - for example, using the details you voluntarily provided for the purpose you provided them, or complying with a legal obligation such as keeping donation records.</p>
<p>You may withdraw your consent at any time - for example, by unsubscribing from the newsletter with the link in any email, by changing your analytics choice with the Cookie settings link (when Google Analytics is in use), or by asking us to close your account. Withdrawal does not affect processing already carried out, and we may need to keep certain records where the law requires it.</p>', NULL, NULL, NULL, 'white', 'visible', 40),
(@p, 'richtext', NULL, NULL, 'Payments', NULL, '<p>Online donations are processed by our payment gateway, <strong>Razorpay</strong>. When you pay, you enter your card, UPI or bank details directly in Razorpay''s secure checkout. <strong>The foundation never sees or stores your card number, CVV, UPI PIN or net banking password.</strong></p>
<p>We receive only the information needed to confirm and record your donation, such as the payment reference, amount, status and payment method type. Razorpay processes payment data under its own privacy policy and the applicable regulations for payment providers.</p>', NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'richtext', NULL, NULL, 'Website analytics and cookies', NULL, '<h3>Privacy-friendly analytics</h3>
<p>We use our own first-party statistics to count page visits, the pages people find useful and how they reached us (for example a search engine or a campaign link). <strong>These statistics do not store your IP address</strong>: a visitor is represented by a code that changes every day and cannot be used to identify you. We respect your browser''s "Do Not Track" and "Global Privacy Control" signals.</p>
<h3>Google Analytics (optional)</h3>
<p>If the foundation turns on Google Analytics, it loads only after you agree in the cookie notice. If you choose "No, thanks", it is not loaded and the website works fully.</p>
<h3>Cookies and browser storage</h3>
<ul>
<li><strong>Session cookie</strong> - keeps you signed in while you use the website and protects forms against misuse. It is deleted when you close the browser.</li>
<li><strong>"Remember me" cookie</strong> - only if you choose it when signing in, so you stay signed in on that device. It can be removed by signing out.</li>
<li><strong>Your choices</strong> - your analytics choice and whether you have closed a notice are remembered in your browser. If Google Analytics is turned on, you can change your analytics choice at any time with the "Cookie settings" link at the bottom of every page.</li>
<li><strong>Google Analytics cookies</strong> (_ga, _ga_*) - set only if Google Analytics is turned on and you choose "Allow analytics"; removed if you later change your choice to "No, thanks".</li>
</ul>
<p>We do not use advertising cookies, and we do not set any analytics cookies unless you allow Google Analytics.</p>', NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'richtext', NULL, NULL, 'How long we keep your information', NULL, '<p>We keep personal data only as long as it is needed for the purpose it was collected for, or as long as the law requires:</p>
<ul>
<li><strong>Donation and receipt records</strong> are kept for the period required under tax and accounting laws.</li>
<li><strong>Applications</strong> that are not successful are deleted or anonymised after a limited period; records of volunteers, interns and staff are kept while the association continues and for a reasonable period afterwards, including to verify certificates.</li>
<li><strong>Enquiries, grievances and feedback</strong> are kept for a limited period after they are resolved.</li>
<li><strong>Security logs</strong> are kept for a limited period, normally up to one year.</li>
<li><strong>Newsletter records</strong> are kept while you are subscribed; after you unsubscribe we keep only what is needed to respect your choice.</li>
</ul>
<p>When data is no longer needed, it is deleted or anonymised.</p>', NULL, NULL, NULL, 'white', 'visible', 70),
(@p, 'richtext', NULL, NULL, 'Who we share information with', NULL, '<p>We do not sell, rent or trade your personal data. We share it only when necessary:</p>
<ul>
<li><strong>Service providers</strong> who help us run the website and process donations - the payment gateway (Razorpay), our web hosting provider and our email service - only for providing their service to us.</li>
<li><strong>Government authorities</strong> where required by law, for example tax authorities for donation reporting where applicable, or law enforcement in response to a lawful request or a report of a crime.</li>
<li><strong>Auditors and professional advisers</strong> bound by confidentiality, for statutory audits and compliance.</li>
</ul>
<p>Information shared with partner organisations for a specific programme is limited to what is necessary and shared only with your knowledge.</p>', NULL, NULL, NULL, 'white', 'visible', 80),
(@p, 'richtext', NULL, NULL, 'How we protect your information', NULL, '<ul>
<li>The website uses an encrypted connection (HTTPS).</li>
<li>Passwords are stored only as secure hashes; password reset and email verification links expire and work once.</li>
<li>Forms are protected against automated misuse and repeated attempts are limited.</li>
<li>Uploaded documents are kept in private storage and are delivered only to authorised staff.</li>
<li>Staff access the admin panel with individual accounts, role-based permissions and optional two-step login; staff actions are recorded in an audit log.</li>
</ul>
<p>No system is completely secure. If we become aware of a personal data breach that affects you, we will inform you and the authorities as required by law.</p>', NULL, NULL, NULL, 'white', 'visible', 90),
(@p, 'richtext', NULL, NULL, 'Children''s information', NULL, '<p>Our services are meant for adults. Information about a child (anyone under 18) - for example in a scholarship application or a request for help - should be submitted by a parent or lawful guardian, whose consent we rely on. We do not knowingly create accounts for children, track children''s behaviour or direct advertising at them.</p>
<p>Photographs of children are published only with the written consent of a parent or guardian, as described in our <a href="/child-protection-policy/">Child Protection Policy</a>.</p>', NULL, NULL, NULL, 'white', 'visible', 100),
(@p, 'richtext', NULL, NULL, 'Your rights', NULL, '<p>Under the DPDP Act and this policy, you have the right to:</p>
<ul>
<li><strong>Access</strong> - ask for a summary of the personal data we hold about you and how it is used.</li>
<li><strong>Correction and updating</strong> - correct inaccurate or incomplete data. You can edit most profile details yourself in your <a href="/dashboard/">dashboard</a>.</li>
<li><strong>Erasure</strong> - ask us to delete your data or close your account, unless we must keep it by law.</li>
<li><strong>Withdraw consent</strong> - for example, unsubscribe from the newsletter at any time.</li>
<li><strong>Grievance redressal</strong> - raise a complaint about how your data is handled.</li>
<li><strong>Nominate</strong> - nominate another person to exercise your rights in the event of your death or incapacity.</li>
</ul>
<p>To use these rights, use the data request option in your dashboard or write to us through the <a href="/contact/">Contact Us</a> page. We may need to verify your identity before acting on a request.</p>', NULL, NULL, NULL, 'white', 'visible', 110),
(@p, 'richtext', NULL, NULL, 'Questions and grievances', NULL, '<p>If you have a question about this policy or a concern about how your personal data is handled, please contact us through the <a href="/contact/">Contact Us</a> page or raise it on the <a href="/grievance-redressal/">Grievance Redressal</a> page. We will acknowledge your concern and respond as early as possible.</p>
<p>If you are not satisfied with our response, you may approach the Data Protection Board of India in accordance with the DPDP Act.</p>', NULL, NULL, NULL, 'white', 'visible', 120),
(@p, 'richtext', NULL, NULL, 'Changes to this policy', NULL, '<p>We may update this policy when our services or the law change. The date of the latest update is shown at the end of this page. Significant changes will be highlighted on the website.</p>', NULL, NULL, NULL, 'white', 'visible', 130),
(@p, 'callout', NULL, NULL, 'We never sell your data', 'Your information is used only for the purpose you shared it for - processing your donation, application or request, or replying to you. Card and UPI details are handled by the payment gateway and never stored by us.', NULL, NULL, NULL, '{"tone":"green","icon":"lock"}', 'white', 'visible', 140),
(@p, 'cta', NULL, NULL, 'Have a question about your data?', 'Write to us and we will help you access, correct or delete your information.', NULL, NULL, NULL, '{"buttons":[{"label":"Contact us","url":"/contact/","style":"donate","icon":"mail","size":"lg"},{"label":"Raise a grievance","url":"/grievance-redressal/","style":"light","size":"lg"}]}', 'white', 'visible', 150);

-- ---------------------------------------------------------------------
-- /terms-conditions/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'terms-conditions');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'richtext', NULL, NULL, 'Acceptance of these terms', NULL, '<p>These terms apply to your use of the website of Prerit Welfare Foundation, a nonprofit organisation based in New Delhi, India, and to the online services offered on it, including donations, accounts, applications and forms. By using the website you agree to these terms. If you do not agree, please do not use the website.</p>
<p>These terms should be read together with our <a href="/privacy-policy/">Privacy Policy</a> and <a href="/donation-refund-policy/">Donation &amp; Refund Policy</a>.</p>', NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'richtext', NULL, NULL, 'Using the website', NULL, '<p>You agree to use the website lawfully and respectfully. You must not:</p>
<ul>
<li>submit false information, impersonate another person or apply on someone''s behalf without their permission;</li>
<li>attempt to access accounts, data or areas of the website that you are not authorised to use, or interfere with its security or operation;</li>
<li>upload files containing viruses or harmful code, or content that is unlawful, abusive, defamatory or that violates someone''s privacy;</li>
<li>use automated tools to send messages, create accounts or collect information from the website;</li>
<li>use the foundation''s name, logo or content to collect money, offer jobs or make any representation on our behalf.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'richtext', NULL, NULL, 'Your account', NULL, '<p>You are responsible for keeping your password confidential and for activity under your account. Please give accurate details and keep them up to date. Tell us immediately if you suspect unauthorised use of your account.</p>
<p>We may suspend or close an account that is used in breach of these terms, for fraud or to harm others. You can ask us to close your account at any time, as explained in the Privacy Policy.</p>', NULL, NULL, NULL, 'white', 'visible', 30),
(@p, 'richtext', NULL, NULL, 'Donations', NULL, '<ul>
<li>Donations are voluntary contributions and are not payment for goods or services.</li>
<li>Online donations are processed by our payment gateway. A donation is treated as received only after the payment gateway confirms it and our system verifies it; receipts are issued only for verified donations.</li>
<li>Tax benefits, such as a deduction under Section 80G, are available only if the foundation holds the relevant registration, as shown on the <a href="/ngo-details/">NGO Details</a> page.</li>
<li>Donations to a campaign are used for that campaign''s purpose; how funds are used if a campaign is over- or under-funded is explained in the <a href="/donation-refund-policy/">Donation &amp; Refund Policy</a>, which also covers refunds.</li>
<li>Amounts raised shown on the website include verified donations only.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 40),
(@p, 'richtext', NULL, NULL, 'Applications, volunteering and certificates', NULL, '<ul>
<li>The foundation <strong>never charges any fee</strong> for applications, interviews, joining, training or certificates.</li>
<li>Submitting an application does not guarantee selection. Selection is at the foundation''s discretion, based on its needs and the suitability of applicants.</li>
<li>Information in applications must be true and complete. We may withdraw an offer or end an association if information is found to be false.</li>
<li>Volunteers, volunteer teachers and interns must follow the <a href="/volunteer-policy/">Volunteer Policy</a> and the <a href="/child-protection-policy/">Child Protection Policy</a>.</li>
<li>Certificates are issued only for verified participation and remain the record of the foundation. Altering or misusing a certificate is not permitted.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'richtext', NULL, NULL, 'Content and intellectual property', NULL, '<p>The name and logo of Prerit Welfare Foundation, and the text, graphics and photographs on this website, belong to the foundation or are used with permission. You may share pages and quote short extracts for non-commercial purposes with a link to the source. Any other use requires our written permission.</p>
<p>Photographs of people are published only with their consent (and for children, the consent of a parent or guardian). If you believe content on the website should be removed, please contact us.</p>
<p>When you send us feedback or suggestions, you allow us to use them to improve our work. We publish testimonials only with the express consent of the person concerned.</p>', NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'richtext', NULL, NULL, 'Third-party services and links', NULL, '<p>The website uses third-party services such as the Razorpay payment gateway, embedded videos and maps, and may link to other websites, including government portals for welfare schemes. These services are governed by their own terms and privacy policies. We are not responsible for the content or practices of other websites.</p>', NULL, NULL, NULL, 'white', 'visible', 70),
(@p, 'richtext', NULL, NULL, 'Accuracy of information', NULL, '<p>We work to keep the information on this website accurate and up to date, including information about welfare schemes, which we link to official sources. However, information may change, and scheme rules are decided by the authorities concerned. Please verify important details with the official source before relying on them.</p>', NULL, NULL, NULL, 'white', 'visible', 80),
(@p, 'richtext', NULL, NULL, 'Limitation of liability', NULL, '<p>The website is provided on an "as available" basis. To the extent permitted by law, the foundation is not liable for any indirect or consequential loss arising from the use of the website, from temporary unavailability, or from reliance on information on third-party websites. Nothing in these terms limits any liability that cannot be limited under applicable law.</p>', NULL, NULL, NULL, 'white', 'visible', 90),
(@p, 'richtext', NULL, NULL, 'Fraud and impersonation', NULL, '<p>The foundation communicates only through the contact details published on this website. We never ask for OTPs, passwords or card details, and we never ask for money to be paid into personal accounts. If someone uses our name to ask for money or personal information, please do not respond and report it on the <a href="/report-fraud/">Report Fraud</a> page.</p>', NULL, NULL, NULL, 'white', 'visible', 100),
(@p, 'richtext', NULL, NULL, 'Changes and governing law', NULL, '<p>We may update these terms from time to time; the date of the latest update is shown at the end of this page. Continued use of the website after a change means you accept the updated terms.</p>
<p>These terms are governed by the laws of India. Subject to applicable law, the courts at New Delhi shall have jurisdiction over any dispute relating to them.</p>', NULL, NULL, NULL, 'white', 'visible', 110),
(@p, 'richtext', NULL, NULL, 'Contact', NULL, '<p>Questions about these terms can be sent through the <a href="/contact/">Contact Us</a> page. Complaints can be raised on the <a href="/grievance-redressal/">Grievance Redressal</a> page.</p>', NULL, NULL, NULL, 'white', 'visible', 120),
(@p, 'cta', NULL, NULL, 'Thank you for supporting our work', 'Every donation, hour of volunteering and kind word helps us build a fair and inclusive society.', NULL, NULL, NULL, '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"light","size":"lg"}],"hindi_tagline":true}', 'white', 'visible', 140);

-- ---------------------------------------------------------------------
-- /donation-refund-policy/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'donation-refund-policy');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'richtext', NULL, NULL, 'Our commitment to donors', NULL, '<p>Every donation to Prerit Welfare Foundation is a voluntary act of trust. This policy explains how we receive donations, when receipts are issued, how donations are used and in which situations a donation can be refunded.</p>', NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'richtext', NULL, NULL, 'How you can donate', NULL, '<h3>Online</h3>
<p>When online donation is available, donate on the <a href="/donate/">Donate</a> page or on a campaign page. Payments are processed securely by our payment gateway, Razorpay, using UPI, cards, net banking or other methods offered in the checkout. Your card and UPI details are never seen or stored by the foundation.</p>
<h3>Offline</h3>
<p>Bank transfers and cheques are accepted only to the bank details published by the foundation on the Donate page. Never transfer money to a personal account or to account details sent to you by phone, WhatsApp, email or social media. After an offline donation, please write to us with the date, amount and transaction reference or cheque number so that we can record it.</p>
<h3>Who can donate</h3>
<p>Unless an FCRA registration is shown on our <a href="/ngo-details/">NGO Details</a> page, the foundation cannot accept foreign contributions under the Foreign Contribution (Regulation) Act, 2010. We can therefore accept donations only from Indian citizens, including Indian citizens living abroad who give from their own funds through Indian bank accounts, cards or UPI. We cannot accept donations from foreign nationals (including OCI card holders), foreign companies, foreign trusts or any other foreign source. Even when an FCRA registration is listed, foreign contributions can be received only in our designated FCRA bank account, so please contact us first. A foreign contribution received by mistake will be returned to the original payment method.</p>', NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'richtext', NULL, NULL, 'Verification and receipts', NULL, '<ul>
<li>An online donation is treated as received only after the payment gateway confirms the payment and our system verifies it. Pending or failed payments are not counted.</li>
<li>A receipt is issued for every verified donation. It is emailed to you and, if you have an account with the same email address, it is available in your <a href="/dashboard/">dashboard</a>.</li>
<li>Offline donations are receipted after the amount is received in the foundation''s bank account and reconciled.</li>
<li>Please enter your name, email address and - if you need a tax receipt - your PAN and address correctly. A receipt can be corrected if you contact us promptly.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 30),
(@p, 'richtext', NULL, NULL, 'Tax benefits', NULL, '<p>A deduction under Section 80G of the Income Tax Act is available only if the foundation holds a valid 80G registration. When it does, the registration number is shown on the <a href="/ngo-details/">NGO Details</a> page and printed on your receipt, and your PAN is required. If no 80G registration is listed, no tax benefit should be assumed. Tax benefits are subject to the rules and limits of the Income Tax Act. Cash donations above ₹2,000 do not qualify for deduction under Section 80G. The deduction is claimed on the basis of the Form 10BE certificate that the foundation issues after filing its statement of donations in Form 10BD, so please give your PAN when donating.</p>', NULL, NULL, NULL, 'white', 'visible', 40),
(@p, 'richtext', NULL, NULL, 'How donations are used', NULL, '<ul>
<li><strong>Campaign donations</strong> are used for the purpose described on the campaign page.</li>
<li><strong>General donations</strong> are used where the need is greatest across our programmes - education, healthcare, women empowerment, environment, skill development and relief work.</li>
<li>A reasonable part of a donation may support costs that are directly needed to deliver a programme, such as materials, transport and coordination.</li>
<li>Totals shown on the website include verified donations only, and we publish campaign updates and utilisation reports to show how funds are used.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'richtext', NULL, NULL, 'If a campaign is over- or under-funded', NULL, '<p><strong>If a campaign receives more than it needs</strong>, or its need ends early (for example, relief is no longer required), the remaining funds are used for the closest related purpose - such as the same type of support in the same area, or the programme the campaign belongs to. We explain this on the campaign page or in its utilisation report.</p>
<p><strong>If a campaign raises less than its goal</strong>, we use the funds received for the campaign''s purpose at the scale that is possible, may combine them with other funding for the same purpose, and report what was done. If a campaign cannot go ahead at all, the funds are used for the closest related purpose and this is explained in an update. If you would prefer otherwise, please contact us.</p>', NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'richtext', NULL, NULL, 'Refunds', NULL, '<p>Donations are generally not refundable, because they are applied to programmes once received. We will, however, refund:</p>
<ul>
<li><strong>duplicate payments</strong> - the same donation charged more than once;</li>
<li><strong>erroneous donations</strong> - for example, a wrong amount entered by mistake or a technical error during payment;</li>
<li><strong>unauthorised transactions</strong> - a payment made without the account holder''s permission;</li>
<li><strong>donations from a foreign source</strong> that we are not permitted to accept.</li>
</ul>
<p>Please report the problem as soon as possible, preferably within 30 days of the transaction, so that we can check it with the payment gateway. Approved refunds are made <strong>to the original payment method through the payment gateway</strong>; we do not refund in cash or to a different account. The time the amount takes to reach you depends on your bank and the payment method. Any receipt issued for a refunded donation is cancelled.</p>', NULL, NULL, NULL, 'white', 'visible', 70),
(@p, 'richtext', NULL, NULL, 'How to request a refund', NULL, '<p>Write to us through the <a href="/contact/">Contact Us</a> page or the <a href="/grievance-redressal/">Grievance Redressal</a> page with:</p>
<ul><li>your name, email address and mobile number;</li><li>the date and amount of the donation;</li><li>the payment or receipt reference, and the reason for the request.</li></ul>
<p>We will acknowledge your request, check the transaction with the payment gateway and let you know the outcome.</p>', NULL, NULL, NULL, 'white', 'visible', 80),
(@p, 'richtext', NULL, NULL, 'Failed payments', NULL, '<p>If money was debited but the payment did not complete, it is usually reversed automatically by your bank or the payment gateway. If it does not reach your account within the period your bank mentions, contact us with the date, amount and the reference shown by your bank or UPI app and we will help you check it. Please do not repeat a payment several times without checking whether the earlier one succeeded.</p>', NULL, NULL, NULL, 'white', 'visible', 90),
(@p, 'richtext', NULL, NULL, 'Privacy and anonymous donations', NULL, '<p>Donor details are kept confidential and used as described in our <a href="/privacy-policy/">Privacy Policy</a>. You may choose to donate anonymously so that your name is not shown on the website; we still keep your details for the receipt and our legal records.</p>', NULL, NULL, NULL, 'white', 'visible', 100),
(@p, 'callout', NULL, NULL, 'Donate only through this website', 'The foundation never asks for donations to personal bank accounts or UPI IDs, and never asks for an OTP. If someone requests money in our name, please report it on the Report Fraud page.', NULL, NULL, NULL, '{"tone":"gold","icon":"shield"}', 'white', 'visible', 110),
(@p, 'cta', NULL, NULL, 'Your support helps us empower communities', 'Choose a cause you care about or make a general donation. Every verified donation receives a receipt.', NULL, NULL, NULL, '{"buttons":[{"label":"Donate Now","url":"/donate/","style":"donate","icon":"heart","size":"lg"},{"label":"See campaigns","url":"/campaigns/","style":"light","size":"lg"}]}', 'white', 'visible', 120);

-- ---------------------------------------------------------------------
-- /volunteer-policy/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'volunteer-policy');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'richtext', NULL, NULL, 'Purpose and scope', NULL, '<p>Volunteers are at the heart of Prerit Welfare Foundation''s work. This policy explains what volunteers can expect from us and what we expect from volunteers. It applies to all volunteers, volunteer teachers, interns and volunteer staff, whether they work in person or online.</p>', NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'richtext', NULL, NULL, 'Joining as a volunteer', NULL, '<ul>
<li>Volunteers apply through this website. Each application is reviewed by a volunteer coordinator.</li>
<li>Suitable applicants are invited for a conversation and an orientation about our programmes, this policy and the Child Protection Policy.</li>
<li>Roles that involve regular contact with children or vulnerable people require identity verification and may require references or police verification before the volunteer starts.</li>
<li>Volunteers must be at least 18 years old. Students below 18 may join specific activities only with the written consent of a parent or guardian and under supervision.</li>
<li>Every volunteer confirms that they have read and will follow this policy and the code of conduct.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'richtext', NULL, NULL, 'No fees and no payment', NULL, '<p>The foundation <strong>never charges a fee</strong> to volunteer, apply, attend training or receive a certificate. Volunteering is unpaid: <strong>no payment is made for volunteering unless it has been agreed in writing</strong> by the foundation, for example a stipend stated in an internship letter. Out-of-pocket expenses are reimbursed only when they were approved in advance and are supported by bills.</p>', NULL, NULL, NULL, 'white', 'visible', 30),
(@p, 'richtext', NULL, NULL, 'Code of conduct', NULL, '<p>While volunteering with us, you agree to:</p>
<ul>
<li>treat every person - beneficiaries, colleagues and the public - with respect and dignity, without discrimination on the basis of caste, religion, gender, disability, age or any other ground;</li>
<li>be punctual and reliable, and inform your coordinator in advance if you cannot attend;</li>
<li>be honest, and never accept gifts, money or favours from beneficiaries or their families;</li>
<li>never collect money, donations or materials in the foundation''s name unless authorised in writing;</li>
<li>not consume alcohol, tobacco or drugs while volunteering, and not volunteer under their influence;</li>
<li>not use the foundation''s activities for political or religious campaigning, or for personal or commercial gain;</li>
<li>follow the instructions of your coordinator and the safety guidance for each activity.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 40),
(@p, 'richtext', NULL, NULL, 'Safeguarding', NULL, '<p>All volunteers must follow our <a href="/child-protection-policy/">Child Protection Policy</a>. In particular:</p>
<ul>
<li>follow the two-adult rule - never be alone with a child out of sight of other adults;</li>
<li>do not contact children privately by phone, messaging apps or social media;</li>
<li>never use physical punishment or humiliating language;</li>
<li>report any concern about the safety or wellbeing of a child or vulnerable adult to your coordinator immediately.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'richtext', NULL, NULL, 'Confidentiality and personal data', NULL, '<p>Volunteers may learn personal information about beneficiaries, donors and colleagues. This information must be kept confidential, used only for the assigned work and never shared, copied or posted. Documents and materials belonging to the foundation must be returned when an activity or association ends. Personal data must be handled in line with our <a href="/privacy-policy/">Privacy Policy</a>.</p>', NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'richtext', NULL, NULL, 'Social media, photographs and speaking for the foundation', NULL, '<ul>
<li>Do not post photographs or videos of beneficiaries - especially children - unless consent has been recorded and the foundation has approved the use.</li>
<li>Never share details that could identify a child or a vulnerable person, such as full name, school, address or location.</li>
<li>You are welcome to share your volunteering experience, but do not speak on behalf of the foundation, make commitments in its name or share information about donations or beneficiaries without permission.</li>
<li>Media enquiries should be passed to the foundation.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 70),
(@p, 'richtext', NULL, NULL, 'Activities, records and certificates', NULL, '<p>Tasks and activities are assigned by your coordinator and can be followed in your dashboard. Certificates are issued <strong>only for verified service</strong> - activities and hours confirmed by your coordinator - and can be downloaded from your dashboard after approval. Certificates are never sold and cannot be issued for participation that has not been verified.</p>', NULL, NULL, NULL, 'white', 'visible', 80),
(@p, 'richtext', NULL, NULL, 'Concerns and grievances', NULL, '<p>If you have a concern about your volunteering, a colleague or an activity, speak to your coordinator. If the matter is not resolved, or you are uncomfortable raising it directly, use the <a href="/grievance-redressal/">Grievance Redressal</a> page. Concerns are handled confidentially and fairly.</p>', NULL, NULL, NULL, 'white', 'visible', 90),
(@p, 'richtext', NULL, NULL, 'Ending a volunteering role', NULL, '<p>Volunteers may end their association at any time; we ask that you inform your coordinator so that activities can be handed over. The foundation may end a volunteering role if this policy, the Child Protection Policy or the law is breached, if information in the application is found to be false, or if the role is no longer required. When a role ends, the volunteer must return any materials and stop representing the foundation. Serious misconduct may be reported to the authorities.</p>', NULL, NULL, NULL, 'white', 'visible', 100),
(@p, 'callout', NULL, NULL, 'We will never charge you to volunteer', 'No fee is ever charged for applications, training, joining or certificates. If anyone asks you to pay in our name, please report it on the Report Fraud page.', NULL, NULL, NULL, '{"tone":"green","icon":"shield"}', 'white', 'visible', 110),
(@p, 'cta', NULL, NULL, 'Ready to give your time and skills?', 'Join our volunteers, volunteer teachers and interns in education, health, women empowerment, environment and skill development.', NULL, NULL, NULL, '{"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"donate","icon":"users","size":"lg"},{"label":"Teach with us","url":"/volunteer-teacher/","style":"light","size":"lg"}]}', 'white', 'visible', 120);

-- ---------------------------------------------------------------------
-- /child-protection-policy/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'child-protection-policy');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'richtext', NULL, NULL, 'Our commitment', NULL, '<p>Many of Prerit Welfare Foundation''s programmes - literacy drives, learning circles, school support, health camps and scholarships - involve children. Every child has the right to be safe, respected and protected from harm. <strong>The foundation has zero tolerance for any form of abuse or exploitation of children.</strong></p>
<p>This policy sets out how we keep children safe and what we expect from everyone who works with us. It applies to all staff, volunteers, volunteer teachers, interns, members, partners, visitors and anyone representing the foundation, in person and online.</p>', NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'richtext', NULL, NULL, 'What we mean by a child and by abuse', NULL, '<p>A <strong>child</strong> is anyone below 18 years of age. <strong>Abuse</strong> includes physical abuse, sexual abuse, emotional abuse, neglect, and exploitation - including child labour, trafficking and online abuse such as grooming or sharing images of children. Harm can be caused by action or by failing to act.</p>', NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'richtext', NULL, NULL, 'Safe recruitment', NULL, '<ul>
<li>Everyone who will work with children applies through our formal process and is reviewed by a coordinator.</li>
<li>Identity is verified, and references and police verification are sought where appropriate for roles with regular or unsupervised access to children.</li>
<li>Every person receives an orientation on this policy and signs the code of behaviour before starting.</li>
<li>No one with a known history of harm to children is engaged in any role.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 30),
(@p, 'richtext', NULL, NULL, 'Code of behaviour', NULL, '<p>Everyone working with children on behalf of the foundation must:</p>
<ul>
<li><strong>follow the two-adult rule</strong> - never be alone with a child out of sight of other adults; activities take place in open, visible spaces or with another adult present;</li>
<li>treat every child with respect, regardless of gender, caste, religion, disability or background;</li>
<li>never hit, punish physically, humiliate, threaten or use abusive language with a child;</li>
<li>never engage in any sexual behaviour or inappropriate physical contact, comments or jokes with a child;</li>
<li>not contact children privately by phone, messaging apps or social media - communication about activities goes through parents, guardians, teachers or the coordinator;</li>
<li>not give gifts, money or special favours to individual children, or invite children to their home;</li>
<li>not transport a child alone, except in an emergency and with the coordinator informed;</li>
<li>never employ a child in any work, including domestic work.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 40),
(@p, 'richtext', NULL, NULL, 'Photographs, videos and consent', NULL, '<ul>
<li>Photographs and videos of children are taken and published <strong>only with the written consent of a parent or guardian</strong>, and the child''s own agreement where they are old enough to give it.</li>
<li>Published images never show children in a state of undress, distress or in a degrading manner.</li>
<li>We do not publish details that could identify where a child can be found, such as full name, school, address or exact location, together with a photograph.</li>
<li>Volunteers must not take or share photographs of children on personal devices or social media unless approved by the foundation. Images are stored securely and removed on request.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 50),
(@p, 'richtext', NULL, NULL, 'Online sessions', NULL, '<p>Digital learning sessions use platforms approved by the foundation. Sessions are arranged through parents, guardians, teachers or coordinators, and wherever possible another adult is present. Volunteers must not ask children for personal contact details, photographs or private one-to-one online contact.</p>', NULL, NULL, NULL, 'white', 'visible', 60),
(@p, 'richtext', NULL, NULL, 'Reporting a concern', NULL, '<p>If a child tells you about abuse, or you see or suspect that a child is being harmed:</p>
<ol>
<li><strong>If the child is in immediate danger, call 112</strong> (national emergency number).</li>
<li>Listen calmly, reassure the child and do not ask leading questions or investigate yourself. Do not promise to keep it a secret.</li>
<li>Write down what you saw or heard, in the child''s words, with the date and time.</li>
<li>Report it to your coordinator or the foundation immediately - through the <a href="/grievance-redressal/">Grievance Redressal</a> page or by writing to us marked "Confidential".</li>
</ol>
<p><strong>Childline 1098</strong> is India''s national, free, 24-hour helpline for children in need of care and protection. Anyone - including a child - can call it.</p>
<p>Concerns are handled confidentially and shared only with those who need to know to protect the child. No one who reports a concern in good faith will face any adverse action.</p>', NULL, NULL, NULL, 'white', 'visible', 70),
(@p, 'richtext', NULL, NULL, 'The POCSO Act and our legal duty', NULL, '<p>The <strong>Protection of Children from Sexual Offences (POCSO) Act, 2012</strong> protects children from sexual abuse and exploitation. Under the Act, <strong>any person who knows or suspects that a sexual offence has been committed against a child must report it</strong> to the local police or the Special Juvenile Police Unit; failing to report is itself an offence.</p>
<p>The foundation reports such concerns to the authorities as required by law and cooperates fully with the police and child welfare authorities. All staff and volunteers are made aware of this duty during orientation.</p>', NULL, NULL, NULL, 'white', 'visible', 80),
(@p, 'richtext', NULL, NULL, 'Responding to allegations', NULL, '<ul>
<li>Every allegation is taken seriously and acted on promptly.</li>
<li>The person concerned is removed from any contact with children while the matter is examined, without presuming guilt.</li>
<li>The foundation reports the matter to the police or child welfare authorities where required, and supports the child and family in accessing help.</li>
<li>Where a breach of this policy is confirmed, the person''s association with the foundation is ended and the matter may be reported to the authorities.</li>
</ul>', NULL, NULL, NULL, 'white', 'visible', 90),
(@p, 'richtext', NULL, NULL, 'Training and review', NULL, '<p>Staff and volunteers receive orientation on child protection before they begin, and refreshers when needed. This policy is reviewed regularly and whenever the law or our programmes change. The date of the latest update is shown at the end of this page.</p>', NULL, NULL, NULL, 'white', 'visible', 100),
(@p, 'callout', NULL, NULL, 'Child in danger? Call 112 or Childline 1098', 'If a child is in immediate danger, call 112. For a child in need of care and protection, call Childline 1098 - free, 24 hours. Then inform the foundation so we can act.', NULL, NULL, NULL, '{"tone":"gold","icon":"alert-triangle"}', 'white', 'visible', 110),
(@p, 'cta', NULL, NULL, 'Raise a concern confidentially', 'Concerns about the safety of a child in any of our programmes are treated with the highest priority.', NULL, NULL, NULL, '{"buttons":[{"label":"Raise a concern","url":"/grievance-redressal/","style":"donate","icon":"shield","size":"lg"},{"label":"Contact us","url":"/contact/","style":"light","size":"lg"}]}', 'white', 'visible', 120);

-- ---------------------------------------------------------------------
-- /contact/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'contact');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'link_cards', 'quick-help', 'Quick help', 'Looking for something specific?', 'Many questions can be answered straight away on these pages.', NULL, NULL, '[{"icon":"help-circle","title":"Frequently asked questions","text":"Donations, receipts, volunteering and support","url":"/faq/"},{"icon":"search","title":"Track an application","text":"Use your reference number","url":"/application-status/"},{"icon":"life-buoy","title":"Request help","text":"Education, health, livelihood or relief support","url":"/request-help/"},{"icon":"heart","title":"Volunteer with us","text":"Give your time and skills","url":"/become-a-volunteer/"},{"icon":"shield","title":"Raise a grievance","text":"Tell us if something went wrong","url":"/grievance-redressal/"},{"icon":"alert-triangle","title":"Report fraud","text":"Suspicious requests made in our name","url":"/report-fraud/"}]', NULL, 'tint', 'visible', 20),
(@p, 'features', 'partnerships', 'Partnerships', 'Work with us', 'We welcome organisations, institutions and media who share our commitment to communities.', NULL, NULL, '[{"icon":"briefcase","title":"Partnerships & CSR","text":"Companies and foundations can collaborate on education, health, women empowerment, environment and skill development. Choose \\"Partnership / CSR collaboration\\" in the form and tell us about your interest.","tone":"green"},{"icon":"book","title":"Schools & communities","text":"Invite us for awareness sessions, health camps, plantation drives or learning activities. Share the topic, place and possible dates.","tone":"blue"},{"icon":"megaphone","title":"Media","text":"Journalists and content creators can choose \\"Media / press\\" in the form for information about our work and programmes.","tone":"gold"}]', '{"cols":"3"}', 'white', 'visible', 30),
(@p, 'faq_list', NULL, 'FAQ', 'Common questions', 'Quick answers about the foundation.', NULL, NULL, NULL, '{"category":"general","limit":5}', 'cream', 'visible', 40),
(@p, 'callout', NULL, NULL, 'Please note', 'We never ask for OTPs, passwords or card details, and we never charge fees for jobs, internships or volunteering. Verify any request made in our name using the contact details on this page.', NULL, NULL, NULL, '{"tone":"gold","icon":"shield","width":"narrow"}', 'white', 'visible', 50);

-- ---------------------------------------------------------------------
-- /grievance-redressal/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'grievance-redressal');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'richtext', NULL, NULL, 'Our commitment', NULL, '<p>We want everyone who interacts with Prerit Welfare Foundation - donors, volunteers, applicants, beneficiaries, partners and members of the public - to be treated fairly and with respect. If something has gone wrong, we want to know so that we can put it right and improve.</p>
<p>You can raise a grievance about a donation or receipt, an application or selection decision, the behaviour of a volunteer or staff member, a programme or service, or the way your personal data has been handled. Grievances are handled <strong>confidentially</strong>, and no one will face any adverse treatment for raising a concern in good faith.</p>', NULL, NULL, '{"width":"narrow"}', 'white', 'visible', 10),
(@p, 'steps', 'process', 'Process', 'How the process works', 'A clear, fair process for every grievance.', NULL, NULL, '[{"icon":"edit","title":"Tell us","text":"Submit the form below with the details of your grievance."},{"icon":"file-text","title":"Reference number","text":"You get a reference number on screen and by email."},{"icon":"check-circle","title":"Acknowledgement","text":"We acknowledge your grievance and let you know what happens next."},{"icon":"search","title":"Fair review","text":"A responsible person not involved in the matter looks into it and may contact you."},{"icon":"message","title":"Outcome","text":"We write to you with the outcome. Not satisfied? Ask for a further review."}]', NULL, 'tint', 'visible', 20),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 30),
(@p, 'callout', NULL, NULL, 'Concerned about the safety of a child?', 'If a child is in immediate danger, call 112. For a child in need of care and protection, call Childline 1098. Then tell us using this form - child safety concerns are treated with the highest priority under our Child Protection Policy.', NULL, NULL, NULL, '{"tone":"gold","icon":"alert-triangle","width":"narrow"}', 'white', 'visible', 50),
(@p, 'link_cards', NULL, NULL, 'Related pages', NULL, NULL, NULL, '[{"icon":"alert-triangle","title":"Report fraud","text":"Someone misusing our name","url":"/report-fraud/"},{"icon":"lock","title":"Privacy Policy","text":"Your data and your rights","url":"/privacy-policy/"},{"icon":"shield","title":"Child Protection Policy","text":"How we keep children safe","url":"/child-protection-policy/"},{"icon":"file-text","title":"Donation & Refund Policy","text":"Receipts and refunds","url":"/donation-refund-policy/"}]', NULL, 'cream', 'visible', 60);

-- ---------------------------------------------------------------------
-- /report-fraud/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'report-fraud');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'features', 'warning-signs', 'Stay alert', 'How to recognise fraud', 'People sometimes misuse the names of charities to cheat others. These are common warning signs.', NULL, NULL, '[{"icon":"rupee","title":"Money to personal accounts","text":"Requests to send a donation to a personal bank account, UPI ID or QR code shared by phone, WhatsApp or social media.","tone":"orange"},{"icon":"briefcase","title":"Fees for jobs or internships","text":"Any \\"registration\\", \\"training\\", \\"uniform\\" or \\"certificate\\" fee for a job, internship or volunteering. We never charge such fees.","tone":"gold"},{"icon":"lock","title":"OTPs and passwords","text":"Requests for an OTP, password, PIN, card number or screen-sharing. We will never ask for these.","tone":"blue"},{"icon":"zap","title":"Pressure and urgency","text":"Messages that rush you to pay immediately, threaten consequences or promise rewards in return for money.","tone":"orange"},{"icon":"globe","title":"Look-alike websites and profiles","text":"Websites, pages or accounts that copy our name or logo but use different contact details.","tone":"teal"},{"icon":"file-text","title":"Fake receipts or letters","text":"Receipts, certificates or appointment letters that do not come from our official system or email.","tone":"green"}]', '{"cols":"3"}', 'white', 'visible', 10),
(@p, 'split', 'official-channels', 'Verify', 'Our official channels', 'When in doubt, verify the request using only the details published on this website.', '<p>The foundation communicates through the contact details on its <a href="/contact/">Contact Us</a> page. Donations are accepted only through the <a href="/donate/">Donate</a> page of this website or the bank details published there, and every verified donation receives a receipt from our system.</p>', 'assets/images/hero/policy.svg', '["This website and the contact details shown on it","Official email: info@preritwelfarefoundation.org","Donations only through the Donate page of this website","No fees - ever - for jobs, internships, volunteering or certificates","We never ask for OTPs, passwords or card details"]', '{"image_alt":"","reverse":true}', 'cream', 'visible', 20),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 30),
(@p, 'steps', 'what-to-do', 'What to do', 'If you have been targeted', 'Act quickly - it can make a difference.', NULL, NULL, '[{"icon":"x","title":"Stop","text":"Do not pay, do not share OTPs or personal details, and stop responding."},{"icon":"image","title":"Save evidence","text":"Keep screenshots, phone numbers, links, account details and transaction IDs."},{"icon":"phone","title":"Lost money? Call 1930","text":"Call the national cyber crime helpline 1930 or report at cybercrime.gov.in immediately, and inform your bank."},{"icon":"flag","title":"Tell us","text":"Report it using the form above so we can act and warn others."}]', NULL, 'tint', 'visible', 40),
(@p, 'callout', NULL, NULL, 'National cyber crime helpline: 1930', 'For financial fraud, call 1930 or file a complaint at https://cybercrime.gov.in as soon as possible. Quick reporting improves the chance of stopping the transfer of money.', NULL, NULL, NULL, '{"tone":"gold","icon":"phone","width":"narrow"}', 'white', 'visible', 50);

-- ---------------------------------------------------------------------
-- /feedback-form/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'feedback-form');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'features', NULL, 'Feedback', 'Your voice shapes our work', 'Whether you are a donor, volunteer, participant or visitor, your feedback helps us do better.', NULL, NULL, '[{"icon":"eye","title":"Every response is read","text":"Feedback reaches our team directly and is reviewed regularly.","tone":"green"},{"icon":"refresh","title":"Used to improve","text":"Suggestions help us improve our programmes, events, communication and this website.","tone":"blue"},{"icon":"lock","title":"Kept private","text":"Your details are used only to follow up on your feedback, never published without your consent.","tone":"gold"}]', '{"cols":"3"}', 'white', 'visible', 10),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'callout', NULL, NULL, 'Have a complaint?', 'If something went wrong and you would like it looked into, please use the Grievance Redressal page so that your concern is tracked with a reference number.', NULL, NULL, NULL, '{"tone":"blue","icon":"shield","width":"narrow"}', 'white', 'visible', 30),
(@p, 'link_cards', NULL, NULL, 'Other ways to reach us', NULL, NULL, NULL, '[{"icon":"shield","title":"Grievance Redressal","text":"Raise a complaint","url":"/grievance-redressal/"},{"icon":"mail","title":"Contact Us","text":"Questions and enquiries","url":"/contact/"},{"icon":"alert-triangle","title":"Report fraud","text":"Misuse of our name","url":"/report-fraud/"}]', NULL, 'tint', 'visible', 40);

-- ---------------------------------------------------------------------
-- /faq/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'faq');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'callout', NULL, NULL, 'Find answers quickly', 'Search the questions below or filter them by topic. If your question is not answered here, our team will be glad to help through the Contact Us page.', NULL, NULL, NULL, '{"tone":"blue","icon":"help-circle","width":"narrow","size":"sm"}', 'white', 'visible', 5),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'link_cards', NULL, 'We are here to help', 'Still have a question?', NULL, NULL, NULL, '[{"icon":"mail","title":"Contact us","text":"Write to our team","url":"/contact/"},{"icon":"search","title":"Application status","text":"Track with your reference number","url":"/application-status/"},{"icon":"life-buoy","title":"Request help","text":"Support for individuals and families","url":"/request-help/"},{"icon":"alert-triangle","title":"Report fraud","text":"Misuse of our name","url":"/report-fraud/"}]', NULL, 'tint', 'visible', 20),
(@p, 'cta', NULL, NULL, 'Found what you were looking for?', 'If you would like to help, you can donate, volunteer or share our programmes with people who need them.', NULL, NULL, NULL, '{"hindi_tagline":true}', 'white', 'visible', 30);

-- ---------------------------------------------------------------------
-- /newsletter/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'newsletter');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'features', NULL, 'Your inbox, your choice', 'Our promise to subscribers', NULL, NULL, NULL, '[{"icon":"check-circle","title":"Only with your consent","text":"We email only people who have subscribed themselves and recorded their consent.","tone":"green"},{"icon":"clock","title":"Occasional, not frequent","text":"We write when there is something worth sharing - not every day.","tone":"blue"},{"icon":"log-out","title":"One-click unsubscribe","text":"Every email includes a personal link to unsubscribe instantly.","tone":"gold"},{"icon":"lock","title":"Never sold or shared","text":"Your email address is used only to send our updates.","tone":"teal"}]', '{"cols":"4"}', 'tint', 'visible', 20),
(@p, 'faqs', 'newsletter-faq', NULL, 'Newsletter questions', NULL, NULL, NULL, '[{"q":"How often will I receive emails?","a":"Occasionally - when we have news about our programmes, events, campaigns or volunteering opportunities. We do not send daily emails."},{"q":"How do I unsubscribe?","a":"Click the unsubscribe link at the end of any newsletter. It works with one click and you will receive a short confirmation. You can subscribe again at any time."},{"q":"What information do you keep about subscribers?","a":"Your email address, your name if you share it, the consent text you agreed to, and when and on which page you subscribed (including the IP address at that time, as a record of consent). See our Privacy Policy for details."},{"q":"Will unsubscribing stop receipts and application updates?","a":"No. Emails about your own donations, receipts, applications or account are sent separately and are not affected by the newsletter."}]', '{"width":"narrow"}', 'white', 'visible', 30),
(@p, 'cta', NULL, NULL, 'Want to do more than read about it?', 'Join us as a volunteer or support a cause you care about.', NULL, NULL, NULL, '{"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"donate","icon":"users","size":"lg"},{"label":"Donate Now","url":"/donate/","style":"light","icon":"heart","size":"lg"}]}', 'white', 'visible', 40);

-- ---------------------------------------------------------------------
-- /management-body/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'management-body');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'governance', 'Governance', 'Governance and accountability', 'Prerit Welfare Foundation is guided by a management body that is responsible for its direction, decisions and accountability.', '<p>The management body oversees the foundation''s programmes, finances and policies, and ensures that every activity serves our mission of empowering underprivileged communities with dignity and equal opportunity.</p>', 'assets/images/hero/team.svg', '["Sets the direction and priorities of our programmes","Oversees how donations and funds are used","Approves policies such as child protection and volunteering","Ensures transparency and compliance with the law"]', '{"image_alt":""}', 'white', 'visible', 10),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'features', NULL, 'How we work', 'Our governance principles', NULL, NULL, NULL, '[{"icon":"shield","title":"Integrity","text":"Decisions are made in the interest of the communities we serve, with conflicts of interest declared and avoided.","tone":"green"},{"icon":"rupee","title":"Financial care","text":"Only verified donations are counted, every verified donation is receipted, and expenses are recorded against campaigns.","tone":"gold"},{"icon":"eye","title":"Transparency","text":"We publish registration details, reports and campaign utilisation as they become available.","tone":"blue"},{"icon":"heart","title":"Safeguarding","text":"Child protection, volunteer and privacy policies apply to everyone who works with us.","tone":"teal"}]', '{"cols":"4"}', 'tint', 'visible', 30),
(@p, 'link_cards', NULL, NULL, 'Learn more about the foundation', NULL, NULL, NULL, '[{"icon":"file-text","title":"NGO Details","text":"Registration and organisation details","url":"/ngo-details/"},{"icon":"shield","title":"Financial Transparency","text":"Verified totals and accountability","url":"/financial-transparency/"},{"icon":"award","title":"Certificates","text":"Registrations and recognition","url":"/certifications/"},{"icon":"users","title":"Our Team","text":"The people behind our work","url":"/volunteers/"}]', NULL, 'white', 'visible', 40),
(@p, 'cta', NULL, NULL, 'Join us in building a better tomorrow', 'Support the foundation''s work with your time, skills or a donation.', NULL, NULL, NULL, '{"hindi_tagline":true}', 'white', 'visible', 50);

-- ---------------------------------------------------------------------
-- /volunteers/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'volunteers');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'about-team', 'Our team', 'The people behind our work', 'Our work is carried out by a dedicated team and by volunteers who give their time and skills to communities.', '<p>From literacy drives and health camps to skill training, plantation drives and relief work, our team plans and coordinates activities while volunteers, volunteer teachers and interns bring them to life.</p>', 'assets/images/hero/volunteer.svg', '["Programme coordination and community outreach","Volunteer teachers for learning circles and online classes","Volunteers for camps, drives, events and campaigns","Interns supporting research, communication and fundraising"]', '{"image_alt":""}', 'white', 'visible', 10),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 20),
(@p, 'features', NULL, 'Get involved', 'Join our team', 'There is a place for everyone who wants to make a difference.', NULL, NULL, '[{"icon":"heart","title":"Volunteer","text":"Support our programmes, events and campaigns in your free time.","link":"/become-a-volunteer/","link_label":"Apply as a volunteer","tone":"green"},{"icon":"book","title":"Volunteer teacher","text":"Teach children in learning circles, in person or online.","link":"/volunteer-teacher/","link_label":"Teach with us","tone":"blue"},{"icon":"briefcase","title":"Staff positions","text":"Build a career with purpose when positions are open.","link":"/volunteer-staff/","link_label":"See positions","tone":"gold"},{"icon":"award","title":"Internships","text":"Learn by doing - for students and young professionals.","link":"/internships/","link_label":"Apply for an internship","tone":"teal"}]', '{"cols":"4"}', 'tint', 'visible', 30),
(@p, 'cta', NULL, NULL, 'Become part of the team', 'Give your time and skills to build a better tomorrow - मिलकर बनाये बेहतर कल.', NULL, NULL, NULL, '{"buttons":[{"label":"Become a Volunteer","url":"/become-a-volunteer/","style":"donate","icon":"users","size":"lg"},{"label":"Volunteer Policy","url":"/volunteer-policy/","style":"light","size":"lg"}]}', 'white', 'visible', 40);

-- ---------------------------------------------------------------------
-- /ngo-details/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'ngo-details');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'features', 'areas', 'What we do', 'Our areas of work', 'We work with underprivileged communities across these areas.', NULL, NULL, '[{"icon":"book","title":"Education","text":"Literacy drives, community learning circles, study material and school support.","link":"/programs/education-literacy/","link_label":"Learn more","tone":"green"},{"icon":"activity","title":"Healthcare","text":"Health camps and screenings, awareness and prevention, and referral support.","link":"/programs/healthcare/","link_label":"Learn more","tone":"blue"},{"icon":"users","title":"Women empowerment","text":"Skill development, Self-Help Group support, rights and financial literacy.","link":"/programs/women-empowerment/","link_label":"Learn more","tone":"gold"},{"icon":"leaf","title":"Environment","text":"Tree plantation, waste management and sustainable living.","link":"/programs/environment-protection/","link_label":"Learn more","tone":"teal"},{"icon":"tool","title":"Skill development","text":"Vocational and digital training, career guidance and placement support.","link":"/skill-development/","link_label":"Learn more","tone":"orange"},{"icon":"life-buoy","title":"Relief","text":"Food and essential supplies, hygiene support and relief coordination with volunteers.","link":"/programs/disaster-relief/","link_label":"Learn more","tone":"green"}]', '{"cols":"3"}', 'tint', 'visible', 20),
(@p, 'link_cards', NULL, 'Policies', 'Our policies', 'The rules we follow to protect donors, volunteers, beneficiaries and children.', NULL, NULL, '[{"icon":"lock","title":"Privacy Policy","text":"How we protect your information","url":"/privacy-policy/"},{"icon":"file-text","title":"Terms & Conditions","text":"Terms of use of this website","url":"/terms-conditions/"},{"icon":"rupee","title":"Donation & Refund Policy","text":"Receipts, use of funds and refunds","url":"/donation-refund-policy/"},{"icon":"users","title":"Volunteer Policy","text":"Code of conduct for volunteers","url":"/volunteer-policy/"},{"icon":"shield","title":"Child Protection Policy","text":"Keeping children safe","url":"/child-protection-policy/"},{"icon":"message","title":"Grievance Redressal","text":"Raise a concern with us","url":"/grievance-redressal/"}]', NULL, 'white', 'visible', 30),
(@p, 'cta', NULL, NULL, 'Support our work', 'Your donation or time helps us reach more people with education, healthcare and opportunity.', NULL, NULL, NULL, '{"hindi_tagline":true}', 'white', 'visible', 40);

-- ---------------------------------------------------------------------
-- /certifications/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'certifications');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'callout', NULL, NULL, 'Only verified documents', 'Registration and recognition certificates are published here only after the foundation has verified them. Please rely only on documents and numbers shown on this website. For CSR due diligence, write to us through the Contact Us page.', NULL, NULL, NULL, '{"tone":"blue","icon":"shield","width":"narrow","size":"sm"}', 'white', 'visible', 5),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'link_cards', NULL, NULL, 'Related information', NULL, NULL, NULL, '[{"icon":"file-text","title":"NGO Details","text":"Registration and organisation details","url":"/ngo-details/"},{"icon":"shield","title":"Financial Transparency","text":"Verified totals and accountability","url":"/financial-transparency/"},{"icon":"bar-chart","title":"Impact Reports","text":"Reports on our programmes","url":"/impact-reports/"},{"icon":"mail","title":"Contact Us","text":"Request documents for due diligence","url":"/contact/"}]', NULL, 'tint', 'visible', 20);

-- ---------------------------------------------------------------------
-- /digital-resources/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'digital-resources');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'features', NULL, 'Free resources', 'What you will find here', 'Guides and learning material prepared by the foundation are added here as they become ready - free to download and share.', NULL, NULL, '[{"icon":"list","title":"Welfare scheme guides","text":"Simple explanations of how to apply for schemes, with links to official sources.","tone":"green"},{"icon":"activity","title":"Awareness material","text":"Health, hygiene, environment and safety awareness for communities.","tone":"blue"},{"icon":"book","title":"Learning resources","text":"Material for students, learning circles and volunteer teachers.","tone":"gold"},{"icon":"users","title":"Volunteer toolkits","text":"Guidance that helps volunteers plan and run activities well.","tone":"teal"}]', '{"cols":"4"}', 'white', 'visible', 5),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'link_cards', NULL, NULL, 'More to explore', NULL, NULL, NULL, '[{"icon":"list","title":"Welfare Schemes","text":"Schemes and how to apply","url":"/schemes/"},{"icon":"calendar","title":"Awareness Calendar","text":"Important awareness days","url":"/awareness-calendar/"},{"icon":"file-text","title":"Blogs & Articles","text":"Stories and updates","url":"/blog/"},{"icon":"help-circle","title":"FAQ","text":"Common questions","url":"/faq/"}]', NULL, 'tint', 'visible', 20),
(@p, 'newsletter_signup', NULL, NULL, 'Get new resources by email', 'Subscribe to hear when new guides and learning material are published.', NULL, NULL, NULL, '{"no_heading":true,"source":"digital_resources"}', 'white', 'visible', 30);

-- ---------------------------------------------------------------------
-- /impact-reports/
-- ---------------------------------------------------------------------
SET @p := (SELECT id FROM pages WHERE slug = 'impact-reports');
INSERT INTO page_sections (page_id, section_type, anchor, pill, title, intro, body, image, items, settings, background, status, sort_order) VALUES
(@p, 'split', 'reporting', 'Reporting', 'How we report on our work', 'We publish annual and impact reports as they are prepared, so that donors, partners and communities can see what has been done.', '<p>Reports describe our programmes, activities and outcomes using figures from the foundation''s own records. Campaign-wise use of funds is published separately in the utilisation reports.</p>', 'assets/images/hero/reports.svg', '["Annual reports on the foundation''s activities","Impact reports on individual programmes","Campaign utilisation reports with verified figures","Registration certificates on the Certificates page"]', '{"image_alt":""}', 'white', 'visible', 5),
(@p, 'module', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'white', 'visible', 10),
(@p, 'link_cards', NULL, NULL, 'Related reports', NULL, NULL, NULL, '[{"icon":"pie-chart","title":"Campaign Utilisation Reports","text":"How campaign funds were used","url":"/utilization-reports/"},{"icon":"shield","title":"Financial Transparency","text":"Verified donation totals","url":"/financial-transparency/"},{"icon":"star","title":"Success Stories","text":"Stories shared with consent","url":"/success-stories/"},{"icon":"award","title":"Certificates","text":"Registrations and recognition","url":"/certifications/"}]', NULL, 'tint', 'visible', 20),
(@p, 'cta', NULL, NULL, 'Be part of the next report', 'Your support today becomes the progress we report tomorrow.', NULL, NULL, NULL, '{"hindi_tagline":true}', 'white', 'visible', 30);
