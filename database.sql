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
(7,  'Registered Volunteer',   'registered_volunteer',   'Website user who volunteers with the foundation.', 0, 1, 1, 7),
(8,  'Member',                 'member',                 'Registered member of the foundation.', 0, 1, 1, 8),
(9,  'Donor',                  'donor',                  'Supporter who donates to campaigns.', 0, 1, 1, 9),
(10, 'Beneficiary Applicant',  'beneficiary_applicant',  'Person requesting support or a scholarship.', 0, 1, 1, 10);

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
('seo_default_description', 'Prerit Welfare Foundation, New Delhi - empowering underprivileged communities through education, healthcare, women empowerment, environmental care and skill development.'),
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
('fraud_alert_points', 'Do not share OTPs, passwords, card or bank details with anyone.\nDonate only through the Donate page on this website or the bank details shown on it.\nIf someone contacts you in our name, verify it using the contact details on this website.'),
('fraud_alert_report_text', 'To report a suspicious call, message, website or social media account, use the reporting form or write to us. For financial fraud you can also call the national cyber crime helpline 1930 or report at cybercrime.gov.in.'),
('fraud_alert_verified_note', ''),
('fraud_alert_version', '1'),
('fraud_alert_show', 'once_per_visit'),
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
('home',                    'home',               'Home', NULL, NULL, NULL, 'Prerit Welfare Foundation, New Delhi - empowering underprivileged communities through education, healthcare, women empowerment, environmental care and skill development.', NULL, 1, 1, 0, 1),
('about',                   'default',            'About Us', 'About us', 'Together, let us build a better tomorrow - मिलकर बनाये बेहतर कल', 'assets/images/hero/about.svg', 'Learn about Prerit Welfare Foundation, a nonprofit organization in New Delhi working for education, health, women empowerment, environment and skill development.', NULL, 1, 1, 0, 10),
('our-mission-vision',      'default',            'Mission & Vision', 'About us', 'What drives everything we do', 'assets/images/hero/community.svg', 'The mission, vision and values of Prerit Welfare Foundation.', NULL, 1, 1, 0, 11),
('management-body',         'team',               'Management Body', 'About us', 'The people responsible for the governance of the foundation', 'assets/images/hero/team.svg', 'Management body and governance of Prerit Welfare Foundation.', '{"group":"management"}', 1, 1, 0, 12),
('ngo-details',             'ngo_details',        'NGO Details', 'About us', 'Registration and organisation details', 'assets/images/hero/transparency.svg', 'Registration, legal and contact details of Prerit Welfare Foundation.', NULL, 1, 1, 0, 13),
('volunteers',              'team',               'Our Team', 'About us', 'The team and volunteers behind our work', 'assets/images/hero/team.svg', 'Meet the team and volunteers of Prerit Welfare Foundation.', '{"group":"team"}', 1, 1, 0, 14),
('achievements',            'default',            'Achievements', 'About us', 'Milestones in our journey', 'assets/images/hero/reports.svg', 'Achievements and milestones of Prerit Welfare Foundation.', NULL, 1, 1, 0, 15),
('certifications',          'documents',          'Certificates', 'About us', 'Registration and recognition certificates', 'assets/images/hero/transparency.svg', 'Registration certificates and recognitions of Prerit Welfare Foundation.', '{"category":"certification"}', 1, 1, 0, 16),
('gallery',                 'gallery',            'Photo Gallery', 'Media', 'Moments from our programmes, events and campaigns', 'assets/images/hero/gallery.svg', 'Photos from the programmes, events and campaigns of Prerit Welfare Foundation.', '{"media_type":"image"}', 1, 1, 0, 17),
('video-gallery',           'gallery',            'Video Gallery', 'Media', 'Watch our work on the ground', 'assets/images/hero/gallery.svg', 'Videos from the programmes and events of Prerit Welfare Foundation.', '{"media_type":"video"}', 1, 1, 0, 18),
('become-a-volunteer',      'volunteer',          'Volunteer with Us', 'Get involved', 'Give your time and skills to build a better tomorrow', 'assets/images/hero/volunteer.svg', 'Volunteer with Prerit Welfare Foundation in education, health, women empowerment, environment and skill development.', NULL, 1, 1, 0, 20),
('volunteer-teacher',       'teacher',            'Volunteer Teacher Program', 'Get involved', 'Share your knowledge with children who need it most', 'assets/images/hero/teacher.svg', 'Become a volunteer teacher with Prerit Welfare Foundation, in person or online.', NULL, 1, 1, 0, 21),
('volunteer-staff',         'staff',              'Volunteer Staff Application', 'Get involved', 'Join our team and build a career with purpose', 'assets/images/hero/career.svg', 'Apply for staff and volunteer staff positions at Prerit Welfare Foundation.', NULL, 1, 1, 0, 22),
('internships',             'internship',         'Internships', 'Get involved', 'Learn by doing - internships for students and young professionals', 'assets/images/hero/career.svg', 'Internship opportunities at Prerit Welfare Foundation for students and young professionals.', NULL, 1, 1, 0, 23),
('campaigns',               'campaigns',          'Donate / Fundraise', 'Fundraising', 'Choose a cause - progress shows verified donations only', 'assets/images/hero/campaigns.svg', 'Support fundraising campaigns of Prerit Welfare Foundation, including disaster relief, education and food distribution.', NULL, 1, 1, 0, 24),
('awareness-campaign',      'default',            'Awareness Campaign', 'Get involved', 'Spreading knowledge that changes lives', 'assets/images/hero/events.svg', 'Awareness campaigns by Prerit Welfare Foundation on health, education, environment and rights.', NULL, 1, 1, 0, 25),
('skill-development',       'program',            'Skill Development & Training', 'Our programmes', 'Training for jobs and enterprise opportunities', 'assets/images/programs/skill-development.svg', 'Skill development and training by Prerit Welfare Foundation - vocational and digital training, career guidance and livelihood support.', '{"program":"skill-development"}', 1, 1, 0, 26),
('blog',                    'blog',               'Blogs & Articles', 'Media', 'Stories, updates and ideas from our work', 'assets/images/hero/blog.svg', 'Articles and news from Prerit Welfare Foundation.', '{"categories":["blog","news"]}', 1, 1, 0, 27),
('success-stories',         'stories',            'Success Stories', 'Media', 'Real stories of change from the communities we serve', 'assets/images/hero/community.svg', 'Success stories from the programmes of Prerit Welfare Foundation.', NULL, 1, 1, 0, 28),
('media',                   'blog',               'Media / Press Releases', 'Media', 'Press releases and media coverage', 'assets/images/hero/blog.svg', 'Press releases and media resources of Prerit Welfare Foundation.', '{"categories":["press"]}', 1, 1, 0, 29),
('digital-resources',       'documents',          'Digital Resources', 'Media', 'Guides, toolkits and learning resources', 'assets/images/hero/reports.svg', 'Free digital resources and guides from Prerit Welfare Foundation.', '{"category":"digital_resource"}', 1, 1, 0, 30),
('membership',              'membership',         'Membership', 'Get involved', 'Become a member of Prerit Welfare Foundation', 'assets/images/hero/membership.svg', 'Become a member of Prerit Welfare Foundation and support our mission.', NULL, 1, 1, 0, 31),
('scholarship',             'scholarship',        'Scholarship Application', 'Support', 'Helping deserving students continue their education', 'assets/images/hero/scholarship.svg', 'Apply for scholarship support from Prerit Welfare Foundation.', NULL, 1, 1, 0, 32),
('request-help',            'request_help',       'Request Help', 'Support', 'Tell us how we can support you or someone you know', 'assets/images/hero/help.svg', 'Request support from Prerit Welfare Foundation for education, health, livelihood, food or disaster relief.', NULL, 1, 1, 0, 33),
('beneficiary-support',     'default',            'Beneficiary Support', 'Support', 'How individuals and families can request support', 'assets/images/hero/help.svg', 'How individuals and families can request support from Prerit Welfare Foundation.', NULL, 1, 1, 0, 34),
('schemes',                 'schemes',            'Welfare Schemes', 'Support', 'Government and welfare schemes - and how we can help you apply', 'assets/images/hero/help.svg', 'Information on government and welfare schemes for education, health, women, livelihood and social security, with help to apply.', NULL, 1, 1, 0, 36),
('application-status',      'application_status', 'Application Status', 'Support', 'Track your application with your reference number', 'assets/images/hero/status.svg', 'Check the status of your volunteer, teacher, internship, staff, scholarship or help application.', NULL, 1, 1, 0, 35),
('programs',                'programs',           'Our Programs', 'Our work', 'Empowering vulnerable and underserved communities', 'assets/images/hero/community.svg', 'Programmes of Prerit Welfare Foundation: education, healthcare, women and youth empowerment, rural development, environment, skills, digital learning, scholarships, food distribution and disaster relief.', NULL, 1, 1, 0, 40),
('events-page',             'events',             'Events', 'Events & community', 'Camps, drives, awareness programmes and community events', 'assets/images/hero/events.svg', 'Upcoming and past events of Prerit Welfare Foundation.', NULL, 1, 1, 0, 50),
('event-details',           'event_detail',       'Event Details', 'Events & community', NULL, 'assets/images/hero/events.svg', 'Event details.', NULL, 1, 0, 0, 51),
('awareness-calendar',      'calendar',           'Awareness Calendar', 'Events & community', 'Important awareness days and our planned activities', 'assets/images/hero/events.svg', 'Awareness calendar of Prerit Welfare Foundation.', NULL, 1, 1, 0, 52),
('impact-reports',          'documents',          'Impact Reports', 'Transparency', 'Reporting on our programmes and outcomes', 'assets/images/hero/reports.svg', 'Impact and annual reports of Prerit Welfare Foundation.', '{"category":"impact_report","also":["annual_report"]}', 1, 1, 0, 53),
('campaign',                'campaign_detail',    'Campaign', 'Fundraising', NULL, 'assets/images/hero/campaigns.svg', 'Campaign details.', NULL, 1, 0, 0, 60),
('donate',                  'donate',             'Donate Now', 'Donate', 'Your support helps us empower underprivileged communities', 'assets/images/hero/donate.svg', 'Donate online securely to Prerit Welfare Foundation and receive a receipt for every verified donation.', NULL, 1, 1, 0, 61),
('donation-success',        'donation_success',   'Thank You', NULL, NULL, NULL, 'Donation status.', NULL, 1, 0, 1, 62),
('donation-failed',         'donation_failed',    'Payment Not Completed', NULL, NULL, NULL, 'Donation status.', NULL, 1, 0, 1, 63),
('donation-receipt',        'donation_receipt',   'Donation Receipt', NULL, NULL, NULL, 'Donation receipt.', NULL, 1, 0, 1, 64),
('start-a-fundraiser',      'fundraiser',         'Start a Fundraiser', 'Fundraising', 'Propose a fundraising campaign for a cause you care about', 'assets/images/hero/campaigns.svg', 'Propose a fundraising campaign with Prerit Welfare Foundation.', NULL, 1, 1, 0, 65),
('financial-transparency',  'transparency',       'Financial Transparency', 'Transparency', 'Accountability for every rupee received', 'assets/images/hero/transparency.svg', 'Financial transparency, verified donation totals and registration details of Prerit Welfare Foundation.', NULL, 1, 1, 0, 66),
('utilization-reports',     'utilization',        'Campaign Utilization Reports', 'Transparency', 'How campaign funds have been used', 'assets/images/hero/reports.svg', 'Campaign utilisation reports of Prerit Welfare Foundation.', NULL, 1, 1, 0, 67),
('contact',                 'contact',            'Contact Us', 'Contact', 'We would love to hear from you', 'assets/images/hero/contact.svg', 'Contact Prerit Welfare Foundation, New Delhi.', '{"form":"contact"}', 1, 1, 0, 70),
('feedback-form',           'feedback',           'Feedback Form', 'Contact', 'Help us improve - share your feedback', 'assets/images/hero/contact.svg', 'Share your feedback with Prerit Welfare Foundation.', NULL, 1, 1, 0, 71),
('grievance-redressal',     'contact',            'Grievance Redressal', 'Policies', 'Raise a concern or complaint with us', 'assets/images/hero/policy.svg', 'Grievance redressal mechanism of Prerit Welfare Foundation.', '{"form":"grievance"}', 1, 1, 0, 72),
('report-fraud',            'contact',            'Report Fraud or Misuse', 'Safety', 'Tell us about suspicious requests made in our name', 'assets/images/hero/policy.svg', 'Report a suspicious call, message, website or account using the name of Prerit Welfare Foundation.', '{"form":"fraud_report"}', 1, 1, 0, 73),
('privacy-policy',          'policy',             'Privacy Policy', 'Policies', 'How we collect, use and protect your information', 'assets/images/hero/policy.svg', 'Privacy policy of Prerit Welfare Foundation.', NULL, 1, 1, 0, 74),
('terms-conditions',        'policy',             'Terms & Conditions', 'Policies', 'Terms of use of this website', 'assets/images/hero/policy.svg', 'Terms and conditions for using the Prerit Welfare Foundation website.', NULL, 1, 1, 0, 75),
('donation-refund-policy',  'policy',             'Donation & Refund Policy', 'Policies', 'How donations are received, used and refunded', 'assets/images/hero/policy.svg', 'Donation and refund policy of Prerit Welfare Foundation.', NULL, 1, 1, 0, 76),
('volunteer-policy',        'policy',             'Volunteer Policy', 'Policies', 'Guidelines for volunteers, interns and volunteer teachers', 'assets/images/hero/policy.svg', 'Volunteer policy and code of conduct of Prerit Welfare Foundation.', NULL, 1, 1, 0, 77),
('child-protection-policy', 'policy',             'Child Protection Policy', 'Policies', 'Our commitment to keeping children safe', 'assets/images/hero/policy.svg', 'Child protection and safeguarding policy of Prerit Welfare Foundation.', NULL, 1, 1, 0, 78),
('newsletter',              'newsletter',         'Newsletter', 'Stay connected', 'Occasional updates about our work, events and campaigns', 'assets/images/hero/contact.svg', 'Subscribe to email updates from Prerit Welfare Foundation.', NULL, 1, 1, 0, 79),
('faq',                     'faq',                'Frequently Asked Questions', 'Help', 'Quick answers about donations, volunteering and support', 'assets/images/hero/faq.svg', 'Answers to common questions about donating, volunteering and getting support.', NULL, 1, 1, 0, 79),
('sitemap',                 'sitemap',            'Sitemap', NULL, 'All pages on our website', NULL, 'Sitemap of the Prerit Welfare Foundation website.', NULL, 1, 1, 0, 80),
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
(6,  1, NULL, 'Programs',   '/programs/', NULL, NULL, 6),
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
(1, 6, 'All Programs',          '/programs/', 'grid', 'Overview of our work', 1),
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
(1, 7, 'Utilization Reports',   '/utilization-reports/', 'bar-chart', 'How funds were used', 4),
(1, 7, 'Financial Transparency','/financial-transparency/', 'shield', 'Verified totals', 5),
(1, 8, 'Volunteer with Us',     '/become-a-volunteer/', 'heart', 'Give your time and skills', 1),
(1, 8, 'Volunteer Teacher Program', '/volunteer-teacher/', 'book', 'Teach children in need', 2),
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
('programs/environment', '/programs/environment-protection/'), ('wp-login.php', '/login/'), ('wp-admin', '/admin/');

-- =====================================================================
-- MODULE CONTENT (page sections, programmes, campaigns as drafts, FAQs,
-- policies, awareness days). Descriptive text only - no invented data.
-- =====================================================================
