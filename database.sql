-- =====================================================================
-- Prerit Welfare Foundation - Complete database schema
-- MySQL 5.7+ / MariaDB 10.3+   |   Character set: utf8mb4
--
-- Import this file once using phpMyAdmin (Import tab) or:
--   mysql -u DB_USER -p DB_NAME < database.sql
--
-- A default super-admin account is created at the end of this file:
--   Email:    admin@preritwelfarefoundation.org
--   Password: Admin@12345
-- You will be forced to change this password on first login.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '+05:30';

DROP TABLE IF EXISTS password_resets, activity_logs, certificates, website_settings, website_pages,
    newsletter_subscribers, contact_enquiries, faqs, testimonials, blog_posts, gallery,
    event_registrations, events, scholarships, beneficiaries, staff_applications,
    teacher_applications, volunteers, donation_receipts, donations, campaigns, members,
    users, admins, roles;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
-- Admin roles & permissions (permissions = comma separated keys)
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name          VARCHAR(80)  NOT NULL,
    slug          VARCHAR(80)  NOT NULL,
    description   VARCHAR(255) DEFAULT NULL,
    permissions   TEXT         NOT NULL,
    is_system     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Administrators (back office accounts)
-- ---------------------------------------------------------------------
CREATE TABLE admins (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id               INT UNSIGNED NOT NULL,
    name                  VARCHAR(120) NOT NULL,
    email                 VARCHAR(190) NOT NULL,
    phone                 VARCHAR(20)  DEFAULT NULL,
    password_hash         VARCHAR(255) NOT NULL,
    status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
    must_change_password  TINYINT(1)   NOT NULL DEFAULT 0,
    last_login_at         DATETIME     DEFAULT NULL,
    last_login_ip         VARCHAR(45)  DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email),
    KEY idx_admins_role (role_id),
    CONSTRAINT fk_admins_role FOREIGN KEY (role_id) REFERENCES roles (id) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Website users (donors, volunteers, members)
-- ---------------------------------------------------------------------
CREATE TABLE users (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name           VARCHAR(120) NOT NULL,
    email          VARCHAR(190) NOT NULL,
    phone          VARCHAR(20)  DEFAULT NULL,
    password_hash  VARCHAR(255) NOT NULL,
    address        VARCHAR(255) DEFAULT NULL,
    city           VARCHAR(80)  DEFAULT NULL,
    state          VARCHAR(80)  DEFAULT NULL,
    pincode        VARCHAR(10)  DEFAULT NULL,
    status         ENUM('active','blocked') NOT NULL DEFAULT 'active',
    last_login_at  DATETIME     DEFAULT NULL,
    created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Membership records (one per user)
-- ---------------------------------------------------------------------
CREATE TABLE members (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          INT UNSIGNED NOT NULL,
    membership_no    VARCHAR(40)  DEFAULT NULL,
    membership_type  ENUM('general','volunteer','student','life') NOT NULL DEFAULT 'general',
    gender           ENUM('female','male','other','prefer_not') DEFAULT NULL,
    date_of_birth    DATE         DEFAULT NULL,
    occupation       VARCHAR(120) DEFAULT NULL,
    address          VARCHAR(255) DEFAULT NULL,
    city             VARCHAR(80)  DEFAULT NULL,
    state            VARCHAR(80)  DEFAULT NULL,
    pincode          VARCHAR(10)  DEFAULT NULL,
    areas_of_interest VARCHAR(255) DEFAULT NULL,
    motivation       TEXT         DEFAULT NULL,
    status           ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
    valid_until      DATE         DEFAULT NULL,
    admin_notes      TEXT         DEFAULT NULL,
    approved_by      INT UNSIGNED DEFAULT NULL,
    approved_at      DATETIME     DEFAULT NULL,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_members_user (user_id),
    UNIQUE KEY uq_members_no (membership_no),
    KEY idx_members_status (status),
    CONSTRAINT fk_members_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_members_admin FOREIGN KEY (approved_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Fundraising campaigns
-- ---------------------------------------------------------------------
CREATE TABLE campaigns (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title              VARCHAR(200)  NOT NULL,
    slug               VARCHAR(220)  NOT NULL,
    category           VARCHAR(60)   NOT NULL DEFAULT 'general',
    short_description  VARCHAR(300)  NOT NULL,
    description        MEDIUMTEXT    NOT NULL,
    goal_amount        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    image              VARCHAR(255)  DEFAULT NULL,
    location           VARCHAR(150)  DEFAULT NULL,
    start_date         DATE          DEFAULT NULL,
    end_date           DATE          DEFAULT NULL,
    status             ENUM('draft','active','completed','closed') NOT NULL DEFAULT 'draft',
    is_featured        TINYINT(1)    NOT NULL DEFAULT 0,
    is_urgent          TINYINT(1)    NOT NULL DEFAULT 0,
    created_by         INT UNSIGNED  DEFAULT NULL,
    updated_by         INT UNSIGNED  DEFAULT NULL,
    created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_campaigns_slug (slug),
    KEY idx_campaigns_status (status),
    KEY idx_campaigns_category (category),
    CONSTRAINT fk_campaigns_created FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL,
    CONSTRAINT fk_campaigns_updated FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Donations. Only rows with status = 'success' count towards totals.
-- Online payments become 'success' only after server-side gateway
-- verification (signature + payment fetch) or a verified webhook.
-- ---------------------------------------------------------------------
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
    KEY idx_donations_created (created_at),
    CONSTRAINT fk_donations_user     FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_donations_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns (id) ON DELETE SET NULL,
    CONSTRAINT fk_donations_admin    FOREIGN KEY (verified_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Donation receipts (created only for confirmed donations)
-- ---------------------------------------------------------------------
CREATE TABLE donation_receipts (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    donation_id     INT UNSIGNED NOT NULL,
    receipt_no      VARCHAR(40)  NOT NULL,
    financial_year  VARCHAR(9)   NOT NULL,
    status          ENUM('issued','cancelled') NOT NULL DEFAULT 'issued',
    issued_at       DATETIME     NOT NULL,
    emailed_at      DATETIME     DEFAULT NULL,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_receipts_donation (donation_id),
    UNIQUE KEY uq_receipts_no (receipt_no),
    CONSTRAINT fk_receipts_donation FOREIGN KEY (donation_id) REFERENCES donations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Volunteer & internship applications
-- ---------------------------------------------------------------------
CREATE TABLE volunteers (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    application_no    VARCHAR(40)  DEFAULT NULL,
    application_type  ENUM('volunteer','intern') NOT NULL DEFAULT 'volunteer',
    user_id           INT UNSIGNED DEFAULT NULL,
    name              VARCHAR(120) NOT NULL,
    email             VARCHAR(190) NOT NULL,
    phone             VARCHAR(20)  NOT NULL,
    date_of_birth     DATE         DEFAULT NULL,
    gender            ENUM('female','male','other','prefer_not') DEFAULT NULL,
    address           VARCHAR(255) DEFAULT NULL,
    city              VARCHAR(80)  NOT NULL,
    state             VARCHAR(80)  DEFAULT NULL,
    pincode           VARCHAR(10)  DEFAULT NULL,
    occupation        VARCHAR(120) DEFAULT NULL,
    education         VARCHAR(150) DEFAULT NULL,
    interests         VARCHAR(255) NOT NULL,
    availability      VARCHAR(60)  NOT NULL,
    skills            VARCHAR(500) DEFAULT NULL,
    experience        TEXT         DEFAULT NULL,
    motivation        TEXT         NOT NULL,
    status            ENUM('pending','under_review','shortlisted','approved','rejected','on_hold') NOT NULL DEFAULT 'pending',
    admin_notes       TEXT         DEFAULT NULL,
    reviewed_by       INT UNSIGNED DEFAULT NULL,
    reviewed_at       DATETIME     DEFAULT NULL,
    ip_address        VARCHAR(45)  DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_volunteers_appno (application_no),
    KEY idx_volunteers_status (status),
    KEY idx_volunteers_user (user_id),
    KEY idx_volunteers_email (email),
    CONSTRAINT fk_volunteers_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_volunteers_admin FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Volunteer teacher applications
-- ---------------------------------------------------------------------
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
    status               ENUM('pending','under_review','shortlisted','approved','rejected','on_hold') NOT NULL DEFAULT 'pending',
    admin_notes          TEXT         DEFAULT NULL,
    reviewed_by          INT UNSIGNED DEFAULT NULL,
    reviewed_at          DATETIME     DEFAULT NULL,
    ip_address           VARCHAR(45)  DEFAULT NULL,
    created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_teacher_appno (application_no),
    KEY idx_teacher_status (status),
    KEY idx_teacher_user (user_id),
    KEY idx_teacher_email (email),
    CONSTRAINT fk_teacher_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_teacher_admin FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Staff (job) applications
-- ---------------------------------------------------------------------
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
    status                ENUM('pending','under_review','shortlisted','approved','rejected','on_hold') NOT NULL DEFAULT 'pending',
    interview_at          DATETIME     DEFAULT NULL,
    admin_notes           TEXT         DEFAULT NULL,
    reviewed_by           INT UNSIGNED DEFAULT NULL,
    reviewed_at           DATETIME     DEFAULT NULL,
    ip_address            VARCHAR(45)  DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_staff_appno (application_no),
    KEY idx_staff_status (status),
    KEY idx_staff_user (user_id),
    KEY idx_staff_email (email),
    CONSTRAINT fk_staff_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_staff_admin FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Requests for help (beneficiary applications)
-- ---------------------------------------------------------------------
CREATE TABLE beneficiaries (
    id                INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    reference_no      VARCHAR(40)   DEFAULT NULL,
    user_id           INT UNSIGNED  DEFAULT NULL,
    name              VARCHAR(120)  NOT NULL,
    email             VARCHAR(190)  DEFAULT NULL,
    phone             VARCHAR(20)   NOT NULL,
    gender            ENUM('female','male','other','prefer_not') DEFAULT NULL,
    age               TINYINT UNSIGNED DEFAULT NULL,
    address           VARCHAR(255)  NOT NULL,
    city              VARCHAR(80)   NOT NULL,
    state             VARCHAR(80)   DEFAULT NULL,
    pincode           VARCHAR(10)   DEFAULT NULL,
    family_members    TINYINT UNSIGNED DEFAULT NULL,
    annual_income     DECIMAL(12,2) DEFAULT NULL,
    help_category     ENUM('education','health','women','livelihood','food','disaster','other') NOT NULL,
    description       TEXT          NOT NULL,
    document_path     VARCHAR(255)  DEFAULT NULL,
    consent           TINYINT(1)    NOT NULL DEFAULT 0,
    status            ENUM('pending','under_review','approved','assisted','rejected') NOT NULL DEFAULT 'pending',
    admin_notes       TEXT          DEFAULT NULL,
    reviewed_by       INT UNSIGNED  DEFAULT NULL,
    reviewed_at       DATETIME      DEFAULT NULL,
    ip_address        VARCHAR(45)   DEFAULT NULL,
    created_at        TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_beneficiaries_ref (reference_no),
    KEY idx_beneficiaries_status (status),
    KEY idx_beneficiaries_user (user_id),
    KEY idx_beneficiaries_category (help_category),
    CONSTRAINT fk_beneficiaries_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_beneficiaries_admin FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Scholarship applications
-- ---------------------------------------------------------------------
CREATE TABLE scholarships (
    id                     INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    application_no         VARCHAR(40)   DEFAULT NULL,
    user_id                INT UNSIGNED  DEFAULT NULL,
    student_name           VARCHAR(120)  NOT NULL,
    guardian_name          VARCHAR(120)  NOT NULL,
    email                  VARCHAR(190)  NOT NULL,
    phone                  VARCHAR(20)   NOT NULL,
    date_of_birth          DATE          DEFAULT NULL,
    gender                 ENUM('female','male','other','prefer_not') DEFAULT NULL,
    address                VARCHAR(255)  NOT NULL,
    city                   VARCHAR(80)   NOT NULL,
    state                  VARCHAR(80)   DEFAULT NULL,
    pincode                VARCHAR(10)   DEFAULT NULL,
    institution            VARCHAR(200)  NOT NULL,
    course_class           VARCHAR(120)  NOT NULL,
    academic_year          VARCHAR(20)   NOT NULL,
    last_exam_percentage   DECIMAL(5,2)  DEFAULT NULL,
    annual_family_income   DECIMAL(12,2) NOT NULL,
    reason                 TEXT          NOT NULL,
    document_path          VARCHAR(255)  DEFAULT NULL,
    status                 ENUM('pending','under_review','shortlisted','approved','rejected','disbursed') NOT NULL DEFAULT 'pending',
    admin_notes            TEXT          DEFAULT NULL,
    reviewed_by            INT UNSIGNED  DEFAULT NULL,
    reviewed_at            DATETIME      DEFAULT NULL,
    ip_address             VARCHAR(45)   DEFAULT NULL,
    created_at             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_scholarships_appno (application_no),
    KEY idx_scholarships_status (status),
    KEY idx_scholarships_user (user_id),
    CONSTRAINT fk_scholarships_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_scholarships_admin FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Events & registrations
-- ---------------------------------------------------------------------
CREATE TABLE events (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title                 VARCHAR(200) NOT NULL,
    slug                  VARCHAR(220) NOT NULL,
    category              VARCHAR(60)  NOT NULL DEFAULT 'general',
    summary               VARCHAR(300) NOT NULL,
    description           MEDIUMTEXT   NOT NULL,
    image                 VARCHAR(255) DEFAULT NULL,
    location              VARCHAR(200) NOT NULL,
    start_datetime        DATETIME     NOT NULL,
    end_datetime          DATETIME     DEFAULT NULL,
    registration_enabled  TINYINT(1)   NOT NULL DEFAULT 1,
    capacity              INT UNSIGNED DEFAULT NULL,
    status                ENUM('draft','published','cancelled') NOT NULL DEFAULT 'draft',
    created_by            INT UNSIGNED DEFAULT NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_events_slug (slug),
    KEY idx_events_status_date (status, start_datetime),
    CONSTRAINT fk_events_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
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
    UNIQUE KEY uq_event_reg_email (event_id, email),
    KEY idx_event_reg_user (user_id),
    CONSTRAINT fk_event_reg_event FOREIGN KEY (event_id) REFERENCES events (id) ON DELETE CASCADE,
    CONSTRAINT fk_event_reg_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Photo gallery
-- ---------------------------------------------------------------------
CREATE TABLE gallery (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title        VARCHAR(150) NOT NULL,
    category     VARCHAR(60)  NOT NULL DEFAULT 'general',
    image_path   VARCHAR(255) NOT NULL,
    description  VARCHAR(300) DEFAULT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    status       ENUM('published','hidden') NOT NULL DEFAULT 'published',
    uploaded_by  INT UNSIGNED DEFAULT NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_gallery_category (category),
    KEY idx_gallery_status (status),
    CONSTRAINT fk_gallery_admin FOREIGN KEY (uploaded_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Blog, news, press releases and success stories
-- ---------------------------------------------------------------------
CREATE TABLE blog_posts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title         VARCHAR(220) NOT NULL,
    slug          VARCHAR(240) NOT NULL,
    category      ENUM('blog','news','press','success_story','resource') NOT NULL DEFAULT 'blog',
    excerpt       VARCHAR(400) NOT NULL,
    content       MEDIUMTEXT   NOT NULL,
    image         VARCHAR(255) DEFAULT NULL,
    author_name   VARCHAR(120) DEFAULT NULL,
    status        ENUM('draft','published') NOT NULL DEFAULT 'draft',
    published_at  DATETIME     DEFAULT NULL,
    views         INT UNSIGNED NOT NULL DEFAULT 0,
    created_by    INT UNSIGNED DEFAULT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_blog_slug (slug),
    KEY idx_blog_status (status, published_at),
    KEY idx_blog_category (category),
    CONSTRAINT fk_blog_admin FOREIGN KEY (created_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE testimonials (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name         VARCHAR(120) NOT NULL,
    designation  VARCHAR(150) DEFAULT NULL,
    message      TEXT         NOT NULL,
    photo        VARCHAR(255) DEFAULT NULL,
    sort_order   INT          NOT NULL DEFAULT 0,
    status       ENUM('published','hidden') NOT NULL DEFAULT 'published',
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_testimonials_status (status)
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
    KEY idx_faqs_status (status, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Contact, feedback and grievance submissions
-- ---------------------------------------------------------------------
CREATE TABLE contact_enquiries (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference_no  VARCHAR(40)  DEFAULT NULL,
    type          ENUM('contact','feedback','grievance') NOT NULL DEFAULT 'contact',
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
    KEY idx_enquiries_status (status),
    KEY idx_enquiries_type (type),
    CONSTRAINT fk_enquiries_admin FOREIGN KEY (handled_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    email              VARCHAR(190) NOT NULL,
    name               VARCHAR(120) DEFAULT NULL,
    status             ENUM('subscribed','unsubscribed') NOT NULL DEFAULT 'subscribed',
    unsubscribe_token  CHAR(64)     NOT NULL,
    ip_address         VARCHAR(45)  DEFAULT NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_newsletter_email (email),
    UNIQUE KEY uq_newsletter_token (unsubscribe_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Editable website content
-- ---------------------------------------------------------------------
CREATE TABLE website_pages (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug              VARCHAR(100) NOT NULL,
    title             VARCHAR(200) NOT NULL,
    subtitle          VARCHAR(300) DEFAULT NULL,
    content           MEDIUMTEXT   NOT NULL,
    meta_description  VARCHAR(300) DEFAULT NULL,
    banner_image      VARCHAR(255) DEFAULT NULL,
    page_group        ENUM('about','program','involve','policy','resource') NOT NULL DEFAULT 'about',
    status            ENUM('published','hidden') NOT NULL DEFAULT 'published',
    updated_by        INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pages_slug (slug),
    CONSTRAINT fk_pages_admin FOREIGN KEY (updated_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE website_settings (
    setting_key    VARCHAR(100) NOT NULL,
    setting_value  MEDIUMTEXT   DEFAULT NULL,
    updated_at     TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Certificates issued to volunteers, members, interns, teachers
-- ---------------------------------------------------------------------
CREATE TABLE certificates (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    certificate_no    VARCHAR(40)  NOT NULL,
    user_id           INT UNSIGNED DEFAULT NULL,
    recipient_name    VARCHAR(120) NOT NULL,
    recipient_email   VARCHAR(190) DEFAULT NULL,
    certificate_type  ENUM('volunteer','internship','teaching','membership','appreciation','participation') NOT NULL,
    title             VARCHAR(200) NOT NULL,
    description       VARCHAR(500) DEFAULT NULL,
    issue_date        DATE         NOT NULL,
    file_path         VARCHAR(255) DEFAULT NULL,
    status            ENUM('valid','revoked') NOT NULL DEFAULT 'valid',
    issued_by         INT UNSIGNED DEFAULT NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_certificates_no (certificate_no),
    KEY idx_certificates_user (user_id),
    CONSTRAINT fk_certificates_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_certificates_admin FOREIGN KEY (issued_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Audit trail & security events
-- ---------------------------------------------------------------------
CREATE TABLE activity_logs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id    INT UNSIGNED DEFAULT NULL,
    user_id     INT UNSIGNED DEFAULT NULL,
    action      VARCHAR(80)  NOT NULL,
    details     VARCHAR(500) DEFAULT NULL,
    ip_address  VARCHAR(45)  DEFAULT NULL,
    user_agent  VARCHAR(255) DEFAULT NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_logs_action_ip (action, ip_address, created_at),
    KEY idx_logs_admin (admin_id),
    KEY idx_logs_user (user_id),
    KEY idx_logs_created (created_at),
    CONSTRAINT fk_logs_admin FOREIGN KEY (admin_id) REFERENCES admins (id) ON DELETE SET NULL,
    CONSTRAINT fk_logs_user  FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- =====================================================================
-- DEFAULT DATA
-- Only real foundation information is included. Registration numbers,
-- phone numbers, bank details and social links are intentionally left
-- blank: add the verified values from Admin > Settings.
-- =====================================================================

INSERT INTO roles (id, name, slug, description, permissions, is_system) VALUES
(1, 'Super Admin', 'super_admin', 'Full access to every section including users, roles and settings.', 'all', 1),
(2, 'Manager', 'manager', 'Runs day-to-day operations: campaigns, applications, members, content and enquiries.', 'campaigns,donations,applications,members,beneficiaries,content,enquiries,reports', 1),
(3, 'Finance', 'finance', 'Donations, receipts and financial reports.', 'campaigns,donations,reports', 1),
(4, 'Content Editor', 'editor', 'Website pages, events, gallery, blog, testimonials and FAQs.', 'content', 1),
(5, 'Application Reviewer', 'reviewer', 'Reviews volunteer, teacher, staff, beneficiary and scholarship applications.', 'applications,beneficiaries,members,enquiries', 1);

INSERT INTO admins (role_id, name, email, password_hash, status, must_change_password) VALUES
(1, 'Site Administrator', 'admin@preritwelfarefoundation.org', '$2y$10$iphNgp5.B9HY75AgnVWmOOSD7/HiZA/dKJXEc7SWtiqysZppUgBtK', 'active', 1);

INSERT INTO website_settings (setting_key, setting_value) VALUES
('site_name', 'Prerit Welfare Foundation'),
('site_tagline', 'मिलकर बनाये बेहतर कल'),
('site_tagline_en', 'Together, let us build a better tomorrow'),
('site_description', 'Prerit Welfare Foundation is a nonprofit organization dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care, and skill development.'),
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
('bank_account_name', ''),
('bank_name', ''),
('bank_account_number', ''),
('bank_ifsc', ''),
('bank_branch', ''),
('upi_id', ''),
('donation_min_amount', '100'),
('donation_presets', '500,1000,2500,5000'),
('staff_positions', ''),
('receipt_signatory', 'Authorised Signatory'),
('receipt_footer_note', 'This is a computer-generated receipt for a donation received by Prerit Welfare Foundation.'),
('logo_path', ''),
('show_impact_counters', '1'),
('home_work_title', 'Our Work'),
('home_work_intro', 'We work to empower vulnerable and underserved communities through education, health, women''s empowerment, environmental sustainability, and employable skills.'),
('hero_slides', '[{"eyebrow":"Women Empowerment","title":"Empowering Women, Transforming Society","text":"Skill development, SHG support and financial literacy programs to strengthen women''s livelihoods.","button_text":"Donate Now","button_link":"donate.php","image":"assets/images/programs/women-empowerment.svg"},{"eyebrow":"Education","title":"Building Strong Futures Through Learning","text":"Literacy drives, community learning circles, study material distribution and school support.","button_text":"Support Education","button_link":"education.php","image":"assets/images/programs/education.svg"},{"eyebrow":"Health","title":"Accessible Care for Every Family","text":"Health camps, screenings, awareness campaigns and referral to essential health services.","button_text":"Our Health Work","button_link":"healthcare.php","image":"assets/images/programs/healthcare.svg"},{"eyebrow":"Environment Protection","title":"A Cleaner, Greener Tomorrow","text":"Tree plantation drives, waste management initiatives and sustainable living practices.","button_text":"Get Involved","button_link":"environment.php","image":"assets/images/programs/environment.svg"},{"eyebrow":"Skill Development","title":"Skills for Jobs and Enterprise","text":"Vocational and digital training, career guidance, placement support and livelihood opportunities.","button_text":"Volunteer With Us","button_link":"volunteer.php","image":"assets/images/programs/skill-development.svg"}]'),
('main_menu', '');

-- Website pages (editable from Admin > Pages)
INSERT INTO website_pages (slug, title, subtitle, page_group, meta_description, content) VALUES
('about', 'About Us', 'Together, let us build a better tomorrow - मिलकर बनाये बेहतर कल', 'about',
 'Prerit Welfare Foundation is a nonprofit organization empowering underprivileged communities through education, healthcare, women empowerment, environmental care and skill development.',
 '<p><strong>Prerit Welfare Foundation</strong> is a nonprofit organization dedicated to empowering underprivileged communities through education, healthcare, women empowerment, environmental care, and skill development. We strive to build a fair and inclusive society where everyone can live with dignity and equal opportunity.</p>
<p>Based in New Delhi, we work with vulnerable and underserved communities, volunteers, educators and well-wishers who share our belief that lasting change happens when people come together. Our motto, <em>मिलकर बनाये बेहतर कल</em> (Together, let us build a better tomorrow), guides everything we do.</p>
<h2 id="mission">Our Mission</h2>
<p>To empower vulnerable and underserved communities through education, health, women''s empowerment, environmental sustainability, and employable skills.</p>
<h2 id="vision">Our Vision</h2>
<p>A fair and inclusive society where everyone can live with dignity and equal opportunity.</p>
<h2>Our Values</h2>
<ul>
<li><strong>Dignity</strong> - every person we work with is treated with respect.</li>
<li><strong>Inclusion</strong> - our programmes are open to people regardless of caste, religion, gender or background.</li>
<li><strong>Transparency</strong> - we account openly for the support we receive.</li>
<li><strong>Collaboration</strong> - we work alongside communities, schools, volunteers and partners.</li>
</ul>'),

('our-team', 'Our Team', 'The people and governing body behind Prerit Welfare Foundation', 'about',
 'Meet the management body, volunteers and team of Prerit Welfare Foundation.',
 '<p>Prerit Welfare Foundation is guided by its management body and supported by a dedicated team of staff members, volunteer teachers and community volunteers.</p>
<h2 id="management">Management Body</h2>
<p>Details of our governing / management body members will be published here by the foundation. To update this section, sign in to the admin panel and edit the <em>Our Team</em> page.</p>
<h2>Volunteers &amp; Educators</h2>
<p>Our programmes are made possible by volunteers who give their time and skills. If you would like to join them, please <a href="volunteer.php">apply to volunteer</a> or <a href="volunteer-teacher.php">become a volunteer teacher</a>.</p>'),

('our-work', 'Our Work', 'Empowering vulnerable and underserved communities', 'program',
 'Our work spans education, health, women empowerment, youth empowerment, rural development, environment protection, skill development and disaster relief.',
 '<p>We work to empower vulnerable and underserved communities through education, health, women''s empowerment, environmental sustainability, and employable skills. Explore our programme areas below to learn what we do and how you can help.</p>'),

('education', 'Education', 'Building strong futures through learning and school support', 'program',
 'Literacy drives, community learning circles, study material distribution and school collaboration by Prerit Welfare Foundation.',
 '<p>Education is the foundation of a dignified life. Our education programme helps children and adults from underserved communities learn, stay in school and build strong futures.</p>
<h2>What we do</h2>
<ul>
<li>Literacy drives and community learning circles</li>
<li>Study material distribution</li>
<li>School collaboration &amp; support</li>
</ul>
<h2>How you can help</h2>
<p>You can support this programme by donating towards education campaigns, contributing study material, or volunteering as a teacher. Students in need may also apply for our scholarship support.</p>'),

('healthcare', 'Health', 'Accessible care and awareness for every family', 'program',
 'Health camps, screenings, awareness and prevention campaigns and referrals to essential health services.',
 '<p>Good health allows families to work, learn and thrive. Our health programme brings care and awareness closer to communities that find it hard to access them.</p>
<h2>What we do</h2>
<ul>
<li>Health camps and screenings</li>
<li>Awareness &amp; prevention campaigns</li>
<li>Referral &amp; essential health services</li>
</ul>
<h2>How you can help</h2>
<p>Doctors, nurses, health workers and students can volunteer at our camps. Donations help us organise camps and awareness activities.</p>'),

('women-empowerment', 'Women Empowerment', 'Strengthening livelihoods and leadership among women', 'program',
 'Skill development programmes, Self-Help Group (SHG) formation and support, and rights and financial literacy awareness for women.',
 '<p>When women are empowered, families and communities are transformed. We help women build skills, earn livelihoods and take on leadership roles.</p>
<h2>What we do</h2>
<ul>
<li>Skill development programmes</li>
<li>Self-Help Group (SHG) formation &amp; support</li>
<li>Rights &amp; financial literacy awareness</li>
</ul>
<h2>How you can help</h2>
<p>Trainers, mentors and professionals can volunteer their expertise. Donations support training sessions and materials for women''s groups.</p>'),

('youth-empowerment', 'Youth Empowerment', 'Helping young people discover their potential', 'program',
 'Youth empowerment through guidance, skills, leadership and community participation.',
 '<p>Young people are the changemakers of tomorrow. Our youth empowerment work helps them gain confidence, skills and the opportunity to contribute to their communities.</p>
<h2>Focus areas</h2>
<ul>
<li>Career guidance and mentoring</li>
<li>Leadership, life skills and digital skills</li>
<li>Community service and volunteering opportunities</li>
<li>Linkages to training and placement support</li>
</ul>
<h2>How you can help</h2>
<p>Professionals can mentor young people, and students can join us as volunteers or interns.</p>'),

('rural-development', 'Rural Development', 'Strengthening villages and rural communities', 'program',
 'Rural development work focused on awareness, livelihoods and access to services for rural communities.',
 '<p>Many rural families face limited access to education, healthcare and livelihood opportunities. Our rural development work brings our programmes to villages and helps communities access the services they are entitled to.</p>
<h2>Focus areas</h2>
<ul>
<li>Community awareness on health, hygiene and education</li>
<li>Livelihood and skill-building activities</li>
<li>Support for women''s self-help groups</li>
<li>Awareness about government welfare schemes</li>
</ul>'),

('environment', 'Environment Protection', 'Promoting a cleaner, greener and sustainable lifestyle', 'program',
 'Tree plantation drives, waste management initiatives and sustainable living practices.',
 '<p>A healthy environment is essential for healthy communities. We encourage people to adopt cleaner, greener and more sustainable ways of living.</p>
<h2>What we do</h2>
<ul>
<li>Tree plantation drives</li>
<li>Waste management initiatives</li>
<li>Sustainable living practices</li>
</ul>
<h2>How you can help</h2>
<p>Join our plantation and clean-up drives as a volunteer, or support our environment campaigns.</p>'),

('skill-development', 'Skill Development', 'Training for jobs and enterprise opportunities', 'program',
 'Vocational and digital training, career guidance and placement support, and entrepreneurship and livelihood opportunities.',
 '<p>Employable skills open the door to dignified work. Our skill development programme prepares people for jobs and self-employment.</p>
<h2>What we do</h2>
<ul>
<li>Vocational &amp; digital training</li>
<li>Career guidance &amp; placement support</li>
<li>Entrepreneurship &amp; livelihood opportunities</li>
</ul>
<h2>How you can help</h2>
<p>Trainers and industry professionals can volunteer to teach or mentor. Employers can partner with us for placement opportunities.</p>'),

('disaster-relief', 'Disaster Relief', 'Standing with communities in times of crisis', 'program',
 'Disaster relief campaigns providing food, essential supplies and support to affected communities.',
 '<p>When floods, fires, extreme weather or other emergencies strike, vulnerable families are hit the hardest. Through our disaster relief campaigns we mobilise support for affected communities.</p>
<h2>How relief is provided</h2>
<ul>
<li>Food and essential supplies for affected families</li>
<li>Hygiene and health support</li>
<li>Coordination with local volunteers and authorities</li>
</ul>
<p>Active relief campaigns are listed below. Every amount shown as raised comes only from verified, successful donations.</p>'),

('volunteer', 'Volunteer With Us', 'Give your time and skills to build a better tomorrow', 'involve',
 'Volunteer or intern with Prerit Welfare Foundation in education, health, women empowerment, environment and skill development.',
 '<p>Volunteers are the heart of Prerit Welfare Foundation. Whether you can give a few hours a month or work with us as an intern, your time and skills can make a real difference.</p>
<h2>Who can volunteer?</h2>
<ul>
<li>Students, working professionals and retired persons</li>
<li>Anyone aged 18 years or above (younger volunteers need guardian consent)</li>
<li>People who are respectful, reliable and committed to our values</li>
</ul>
<h2>How it works</h2>
<ol>
<li>Submit the volunteer application form.</li>
<li>Our team reviews your application and contacts you.</li>
<li>Selected volunteers receive an orientation and are matched with activities.</li>
</ol>'),

('volunteer-teacher', 'Become a Volunteer Teacher', 'Share your knowledge with children who need it most', 'involve',
 'Apply to become a volunteer teacher with Prerit Welfare Foundation.',
 '<p>Our education programme depends on dedicated volunteer teachers who help children with literacy, school subjects and life skills, in person or online.</p>
<h2>Who should apply?</h2>
<ul>
<li>Graduates, teachers, retired educators and senior students</li>
<li>People comfortable teaching in Hindi and/or English</li>
<li>Applicants willing to follow our child protection policy</li>
</ul>
<p>All volunteer teachers must agree to our <a href="child-protection-policy.php">Child Protection Policy</a>.</p>'),

('volunteer-staff', 'Work With Us', 'Join our team and build a career with purpose', 'involve',
 'Staff and job opportunities at Prerit Welfare Foundation.',
 '<p>We welcome applications from committed individuals who want to build a career in the social sector. Open positions, when available, are listed on this page. You may also submit a general application.</p>'),

('membership', 'Membership', 'Become a member of Prerit Welfare Foundation', 'involve',
 'Become a member of Prerit Welfare Foundation and support our mission.',
 '<p>Members are part of the Prerit Welfare Foundation family. They support our mission, take part in our activities and help us reach more people.</p>
<h2>Membership types</h2>
<ul>
<li><strong>General Member</strong> - supporters who wish to stay connected and participate.</li>
<li><strong>Volunteer Member</strong> - members who regularly volunteer with us.</li>
<li><strong>Student Member</strong> - students who want to learn and contribute.</li>
<li><strong>Life Member</strong> - long-term supporters of the foundation.</li>
</ul>
<p>Membership applications are reviewed by the foundation. Approved members receive a membership number in their dashboard.</p>'),

('beneficiary', 'Beneficiary Support', 'How individuals and families can request support', 'involve',
 'Information for individuals and families who wish to request support from Prerit Welfare Foundation.',
 '<p>Prerit Welfare Foundation supports vulnerable individuals and families through its programmes in education, health, women empowerment, livelihoods and disaster relief.</p>
<h2>How to request help</h2>
<ol>
<li>Fill in the <a href="request-help.php">Request Help form</a> with accurate details.</li>
<li>Our team will review the request and may contact you for verification.</li>
<li>Support is provided according to need, eligibility and available resources.</li>
</ol>
<p>Submitting a request does not guarantee assistance. All information you share is kept confidential and used only to assess your request.</p>'),

('scholarship', 'Scholarship Support', 'Helping deserving students continue their education', 'involve',
 'Apply for scholarship support from Prerit Welfare Foundation.',
 '<p>Financial difficulties should not stop a deserving student from studying. Our scholarship support aims to help students from economically weaker families continue their education.</p>
<h2>Who can apply?</h2>
<ul>
<li>Students currently enrolled in a school, college or recognised training institute</li>
<li>Students from economically weaker families</li>
</ul>
<h2>Documents</h2>
<p>Please keep a copy of your latest marksheet and income proof ready (PDF or image, maximum 5 MB). Documents are stored privately and seen only by authorised staff.</p>
<p>Scholarship decisions depend on available funds and verification. The foundation''s decision is final.</p>'),

('impact-reports', 'Impact Reports', 'Reporting on our programmes and outcomes', 'resource',
 'Impact reports and programme updates from Prerit Welfare Foundation.',
 '<p>We believe donors and communities deserve to know how support is used. Our impact reports and programme updates will be published on this page as they become available.</p>'),

('financial-transparency', 'Financial Transparency', 'Accountability for every rupee received', 'resource',
 'Financial transparency, registration details and donation information for Prerit Welfare Foundation.',
 '<p>Prerit Welfare Foundation is committed to using every contribution responsibly. Donation figures on this website are calculated automatically from verified, successful transactions only.</p>
<p>Audited financial statements and annual reports will be published on this page once available.</p>'),

('privacy-policy', 'Privacy Policy', 'How we collect, use and protect your information', 'policy',
 'Privacy policy of Prerit Welfare Foundation.',
 '<p>Prerit Welfare Foundation ("we", "us") respects your privacy. This policy explains what information we collect through this website and how we use it.</p>
<h2>Information we collect</h2>
<ul>
<li>Details you submit in forms - such as name, email, phone number, address and messages.</li>
<li>Application details and documents you upload when applying to volunteer, teach, work with us, request help or apply for a scholarship.</li>
<li>Donation details such as amount, PAN (optional, for tax receipts) and payment reference. Card, UPI and bank details are processed securely by our payment gateway and are never stored on our servers.</li>
<li>Basic technical data such as IP address and browser type, used for security.</li>
</ul>
<h2>How we use information</h2>
<ul>
<li>To process donations and issue receipts.</li>
<li>To review applications and respond to enquiries.</li>
<li>To send updates you have asked for, such as our newsletter.</li>
<li>To meet legal, tax and audit requirements.</li>
</ul>
<h2>Sharing</h2>
<p>We do not sell or rent your personal information. We share it only with service providers that help us operate (such as our payment gateway and email provider) or when required by law.</p>
<h2>Security</h2>
<p>Passwords are stored in hashed form, uploaded documents are kept in a private area of our server and only authorised staff can view applications.</p>
<h2>Your choices</h2>
<p>You may update your profile from your account, unsubscribe from our newsletter at any time, or write to us to request access to or correction of your data.</p>
<h2>Contact</h2>
<p>For privacy questions please write to <a href="mailto:info@preritwelfarefoundation.org">info@preritwelfarefoundation.org</a>.</p>'),

('terms', 'Terms & Conditions', 'Terms of use of this website', 'policy',
 'Terms and conditions for using the Prerit Welfare Foundation website.',
 '<p>By using this website you agree to the following terms.</p>
<h2>Use of the website</h2>
<ul>
<li>You will provide accurate information in all forms and applications.</li>
<li>You will not misuse the website, attempt unauthorised access or upload harmful files.</li>
<li>You are responsible for keeping your account password confidential.</li>
</ul>
<h2>Donations</h2>
<p>Donations are governed by our <a href="donation-policy.php">Donation &amp; Refund Policy</a>.</p>
<h2>Applications</h2>
<p>Submitting an application (volunteer, teacher, staff, scholarship or help request) does not guarantee selection or assistance. Decisions are made by the foundation based on need, suitability and available resources.</p>
<h2>Content</h2>
<p>Text, logos and images on this website belong to Prerit Welfare Foundation unless stated otherwise and may not be reused without permission.</p>
<h2>Changes</h2>
<p>We may update these terms from time to time. Continued use of the website means you accept the updated terms.</p>'),

('donation-policy', 'Donation & Refund Policy', 'How donations are received, used and refunded', 'policy',
 'Donation and refund policy of Prerit Welfare Foundation.',
 '<h2>Receiving donations</h2>
<ul>
<li>Online donations are processed through a secure, RBI-regulated payment gateway. We never store card or bank details.</li>
<li>A donation is confirmed only after the payment is verified with the payment gateway. A receipt is issued only for confirmed donations.</li>
<li>Donations made towards a specific campaign are used for that campaign. If a campaign is over-funded or cannot be completed, funds are used for similar programmes of the foundation.</li>
</ul>
<h2>Receipts and tax benefits</h2>
<p>Receipts are available from the confirmation page, by email and in your account under <em>My Donations</em>. Tax exemption (if applicable) is subject to the foundation''s registration status shown on the receipt and to applicable law. Please provide your PAN if you wish to claim a deduction.</p>
<h2>Refunds</h2>
<ul>
<li>If you made a donation by mistake (for example, a wrong amount or a duplicate payment), please write to us within 7 days with your donation reference number.</li>
<li>Approved refunds are made to the original payment method. Gateway processing times apply.</li>
<li>Refunds may not be possible once a tax receipt has been used for a claim.</li>
</ul>
<h2>Contact</h2>
<p>For any donation query, write to <a href="mailto:info@preritwelfarefoundation.org">info@preritwelfarefoundation.org</a>.</p>'),

('volunteer-policy', 'Volunteer Policy', 'Guidelines for volunteers, interns and volunteer teachers', 'policy',
 'Volunteer policy and code of conduct of Prerit Welfare Foundation.',
 '<h2>Eligibility</h2>
<p>Volunteers should normally be 18 years or older. Younger volunteers may participate with written consent of a parent or guardian.</p>
<h2>Code of conduct</h2>
<ul>
<li>Treat beneficiaries, colleagues and community members with dignity and respect.</li>
<li>Do not discriminate on the basis of caste, religion, gender, disability or background.</li>
<li>Do not accept money or gifts from beneficiaries.</li>
<li>Follow the <a href="child-protection-policy.php">Child Protection Policy</a> at all times.</li>
<li>Keep personal information about beneficiaries confidential.</li>
<li>Do not post photos of beneficiaries on social media without permission from the foundation.</li>
</ul>
<h2>Selection and certificates</h2>
<p>Applications are reviewed by the foundation. Certificates are issued to volunteers and interns who complete their committed engagement, at the discretion of the foundation.</p>
<h2>Ending the engagement</h2>
<p>Either party may end the volunteering engagement at any time. The foundation may end an engagement if this policy is breached.</p>'),

('child-protection-policy', 'Child Protection Policy', 'Our commitment to keeping children safe', 'policy',
 'Child protection and safeguarding policy of Prerit Welfare Foundation.',
 '<p>Prerit Welfare Foundation is committed to the safety and well-being of every child who takes part in our programmes. This policy applies to all staff, volunteers, volunteer teachers, interns, members and visitors.</p>
<h2>Principles</h2>
<ul>
<li>Every child has the right to protection from abuse, neglect and exploitation.</li>
<li>The best interests of the child come first.</li>
<li>All concerns are taken seriously and acted upon.</li>
</ul>
<h2>Code of behaviour</h2>
<ul>
<li>Never be alone with a child in a closed or unobserved place.</li>
<li>Never use physical punishment or humiliating language.</li>
<li>Never take, share or post photographs of children without the foundation''s approval and guardian consent.</li>
<li>Never contact children privately through phone or social media.</li>
</ul>
<h2>Reporting concerns</h2>
<p>Any concern about the safety of a child must be reported immediately to the foundation at <a href="mailto:info@preritwelfarefoundation.org">info@preritwelfarefoundation.org</a> or through our <a href="grievance.php">grievance form</a>. Where required, concerns will be reported to the appropriate authorities, including under the POCSO Act, 2012. In an emergency call the national child helpline <strong>1098</strong>.</p>'),

('grievance', 'Grievance Redressal', 'Raise a concern or complaint with us', 'policy',
 'Grievance redressal mechanism of Prerit Welfare Foundation.',
 '<p>We want to hear from you if something has gone wrong. Donors, volunteers, beneficiaries, partners and members of the public can use this form to raise a grievance.</p>
<h2>How we handle grievances</h2>
<ol>
<li>You receive a reference number as soon as you submit the form.</li>
<li>Your grievance is reviewed by an authorised member of the foundation.</li>
<li>We aim to respond within 15 working days.</li>
</ol>
<p>Concerns about the safety of a child are treated as urgent. Please see our <a href="child-protection-policy.php">Child Protection Policy</a>.</p>');

-- Frequently asked questions (about how the website and processes work)
INSERT INTO faqs (question, answer, category, sort_order) VALUES
('How can I donate to Prerit Welfare Foundation?', 'Click "Donate Now", choose an amount and an optional campaign, fill in your details and complete the payment through our secure payment gateway (UPI, cards, net banking and wallets are supported where enabled by the gateway).', 'donations', 1),
('Will I receive a receipt for my donation?', 'Yes. Once your payment is verified with the payment gateway, a receipt is generated automatically. You can open it from the confirmation page, from the email we send you, or under "My Donations" if you donated while logged in.', 'donations', 2),
('Is my payment information safe?', 'Payments are processed by an RBI-regulated payment gateway. Your card, UPI or bank details are entered on the gateway''s secure checkout and are never stored on our servers.', 'donations', 3),
('How do I become a volunteer?', 'Fill in the volunteer application form on the "Volunteer" page. Our team reviews every application and will contact you. You can track the status using your application number on the "Application Status" page.', 'volunteering', 4),
('Can I teach children as a volunteer?', 'Yes. Apply through the "Volunteer Teacher" page. Volunteer teachers must agree to our Child Protection Policy.', 'volunteering', 5),
('How do I check the status of my application?', 'Go to the "Application Status" page and enter your application number along with the email address or phone number you used to apply. Registered users can also see all their applications under "My Applications".', 'volunteering', 6),
('How can someone in need request support?', 'Use the "Request Help" form. Our team will review the request and may contact the applicant for verification. Support depends on need, eligibility and available resources.', 'support', 7),
('How do I contact the foundation?', 'Use the contact form on the "Contact" page or email info@preritwelfarefoundation.org.', 'general', 8);
