# Prerit Welfare Foundation — Website & Admin CMS (v3)

A premium, fully dynamic NGO website with an admin CMS, written in **plain PHP 8, MySQL, HTML, CSS and vanilla JavaScript**.
No frameworks, no Composer, no npm and no build step — upload the files to `public_html/` on any PHP/MySQL host (e.g. Hostinger) and it runs.

*मिलकर बनाये बेहतर कल — Together, let us build a better tomorrow.*

> **Authentic information only.** The site ships with the foundation's real name, logo, address (New Delhi, 110051), email, the registration details the foundation provided (CIN U88900BR2025NPL079155, NGO ID BR/2025/0847299, registered with the Ministry of Corporate Affairs, MSME Udyam UDYAM-BR-37-0067136) and descriptions of its areas of work. It does **not** contain invented donations, beneficiaries, statistics, testimonials, partners, awards or registration numbers. Every public figure (amount raised, donations, volunteers, events) is calculated live from **verified** records and hidden while it is zero. Registration details (PAN, 12A, 80G, CSR-1, Darpan, FCRA), phone, WhatsApp, bank details and social links stay hidden until you enter verified values in **Admin → Settings**. The five relief/education/food campaigns are included as **drafts** — review, add real details and publish them when appropriate.

---

## Quick start — one ZIP + one SQL

The release consists of two files:

* **`prerit-website.zip`** — the whole website. All files are directly at the root of the zip (no nested zips, no extra folder), including `.htaccess`, `install.php` and a ready **`pwf-config.php`** whose `INSTALL_KEY` and `APP_KEY` are already filled with unique random values.
* **`prerit-database.sql`** — the complete database with every update already applied (pages, menus, settings, programme categories, SEO data). It contains **no** user accounts, passwords, donations, applications, payment or AI keys. It never drops anything: imported into a database that already has the website's tables, it stops at the first table ("Table 'website_settings' already exists") and changes nothing.

**Path 1 — new installation (empty database)**
1. **Upload and extract** `prerit-website.zip` in `public_html` (hPanel → File Manager → Upload → right-click → Extract). `index.php`, `install.php`, `pwf-config.php`, `admin/`, `assets/` must sit directly in `public_html`. Delete the zip.
2. **Create a database** (hPanel → Databases → Management) and **import** `prerit-database.sql` in phpMyAdmin (Import → Go).
3. **Edit `public_html/pwf-config.php`**: `DB_NAME`, `DB_USER`, `DB_PASS` (full names with the `u123…_` prefix). Change `BASE_URL` only for a different domain (https, no trailing slash). Leave `INSTALL_KEY` and `APP_KEY` as they are — never change `APP_KEY` later. The file is blocked from the web by `.htaccess`; moving it one folder above `public_html` is optional and even safer (that location is read first).
4. **Domain and go live**: point the domain to the hosting, activate SSL (hPanel → Security → SSL; the `.htaccess` already redirects http/www to `https://preritwelfarefoundation.org`), open `https://YOUR-DOMAIN/install.php`, enter the `INSTALL_KEY` from `pwf-config.php`, create the first administrator, then **delete `install.php`**.

**Path 2 — update the site that is already live (keep all data)**
1. Back up files and database (hPanel → Backups; Admin → Backup & Data Retention).
2. Upload `prerit-website.zip` into `public_html` and extract it, replacing files. Do not delete `uploads/`.
3. Delete `public_html/install.php` and `public_html/pwf-config.php` (the live settings stay in the `pwf-config.php` one folder above `public_html`).
4. **Do not import the SQL.** Open the home page once: the database updates itself (Audit Log → "database_upgraded"; Security Centre → "Database up to date"). This also clears the earlier "Illegal mix of collations" update error.

Then: regenerate the Razorpay Key Secret (it was shared in a chat) and enter the keys in Admin → Fundraising → Payment Gateways; turn on two-step login; upload the seal; submit `sitemap.xml` in Google Search Console. Step-by-step in Hindi: **DEPLOY-HINDI.md**.

---

## 0. What's new in v3

| Area | What changed | Where in the admin |
|---|---|---|
| **Data-safe upgrades** | `includes/migrations.php`: numbered migrations run once, automatically, on the first request after new files are uploaded (per-database lock; idempotent; never delete or overwrite admin-entered data). Sites installed from the earlier v2 package are brought to the final v2 state too. If an update fails it is retried every 10 minutes and shown to administrators. | Audit Log → "database_upgraded"; Security Centre → "Database up to date" |
| **Encrypted secrets** | Payment and AI keys entered in the admin are stored encrypted (libsodium, or AES-256-GCM) with `APP_KEY` from `pwf-config.php`. Without `APP_KEY` the key is derived from the database login: changing the database password then makes the saved keys unreadable (enter them again), and a database backup is only as safe as that password - Payment Gateways, AI Assistant and the Security Centre warn about this. A constant in `pwf-config.php` always wins. Secrets are never shown back. | Security Centre → "Encryption key" |
| **Payment gateways** | Razorpay keys from the admin panel (password confirmation, test connection, webhook guide) + full **Cashfree** integration (order → SDK v3 checkout → server verification, signed webhook `cashfree-webhook.php`, refunds). Donors choose when both are on. | Fundraising → Payment Gateways |
| **AI assistant** | OpenAI-powered website guide (server-side proxy, knowledge from the site's own pages, programmes, events, FAQs and contacts; safe HTML; rate limits, daily cap; FAQ fallback; optional conversation log with retention). Off until a key is added. | Communication → AI Assistant |
| **Deep analytics** | First-party sessions, engaged time, scroll depth, clicks, downloads, forms, channels, devices, approximate location (Cloudflare headers / ipinfo / ipapi, no IP stored), real-time view, funnels, journeys, CSV exports; return visits only with consent. | Insights → Deep Analytics & Visitors |
| **Programs & Events** | `/programs/` hub with 10 categories (AI, Robotics, ML & Data Science, Digital Marketing, Cyber Security, Career & Entrepreneurship, Fitness & Yoga, Music & Arts, Hackathons, Events & Seminars), event pages at `/events/<slug>/` and `/hackathons/<slug>/`, registrations with deadlines, seat limits, waiting list, private joining links, speakers, partners, resources, reports with verified outcomes, interest list, partnership enquiries. No events or figures are seeded. | Content → Programme Categories, Events & Calendar, Speakers & Trainers, Partners & Sponsors |
| **Authorization letters** | Volunteer-teacher authorization letters in the foundation's letter layout (letterhead, logo watermark, seal, school verification box), masked ID numbers only, reference numbers, public verification at `/verify-letter/`. | People → Authorization Letters |
| **Pages** | Home "Our impact" section hidden; mega-menu tagline column removed; volunteer-teacher page shows only the form behind a Terms & Conditions pop-up; donate page shows only the form with the foundation seal; top bar "Programs & Events" dropdown; mobile swipeable menu strip; hero slider right-side images editable with preview; footer shows CIN, NGO ID, MCA registration, MSME and focus areas. | Pages, Settings, Dashboard hero card |

**Installing or upgrading:** see *Quick start* above.

---

## 1. What is included

| Area | Highlights |
|---|---|
| **Design** | Brand colours (#146B43 forest green, #2CA65A green, #F4C900 golden yellow, #176A91 blue), sticky glass header, topbar with **Apply** links (Volunteer, Volunteer Teacher, Job Apply, Internship), horizontal logo lockup (or upload your own horizontal logo), mega menus, breadcrumbs, 3D buttons, scroll-reveal / tilt / parallax animations (automatically off for *reduced motion*), floating WhatsApp / call / help buttons, back-to-top, toasts, modals, countdowns for real end dates, skeleton loaders. Fully responsive (tested 360–1366 px). |
| **Clean URLs** | `/about/`, `/programs/healthcare/`, `/campaign/bihar-flood-relief/`, `/event-details/<slug>/`, `/blog/<slug>/`, `/dashboard/donations/` … Old `.php` and common WordPress addresses answer with **301 redirects** (editable in Admin → Redirects). |
| **Pages (CMS)** | Every page is stored in the database and built from editable **sections** (text, image+text, cards, steps, FAQs, numbers, CTA, video, timeline, partner logos and module lists such as campaigns, events, programmes, team, documents, testimonials). Create, edit, reorder, hide, duplicate, preview drafts, SEO fields per page, editor attribution and timestamps. |
| **Programmes** | 11 programmes (Education & Literacy, Healthcare, Women Empowerment, Youth Empowerment, Rural Development, Environment Protection, Skill Development, Digital Learning, Scholarship & Educational Support, Food Distribution, Disaster Relief) with detailed pages, related campaigns/events/stories, and a **Welfare Schemes** directory (published only with an official source link and "last checked" date). |
| **Fundraising** | Campaign list & detail pages with **verified** progress, updates, utilisation (approved expenses), share buttons with correct preview; donation form with Razorpay (server-side verification + signed webhooks), receipts only for confirmed payments, refunds, offline donations, fundraiser proposals from members, Financial Transparency & Utilisation reports. |
| **Get involved** | Volunteer, Volunteer Teacher (CV), Staff / Job (CV), Internship (CV), Membership, Scholarship, Request Help — reference numbers, acknowledgement emails, application-status tracking with timeline. |
| **Accounts** | Split-screen login (email **or** mobile, password toggle, remember-me), registration with account type, email verification, forgot/reset password (expiring single-use tokens), anti-enumeration, rate limiting. **Member dashboard**: overview, profile, applications, tasks, events, donations, receipts, certificates, notifications, password, privacy settings (download my data, deletion request), help. |
| **Media & events** | Blog / news / press, success stories (consent required), events with registration, capacity and calendar files, **Awareness Calendar** (68 national & international observances 2026–27), photo gallery with lightbox and video gallery. |
| **Information** | Team & management body, NGO details, certificates & reports (PDF), FAQs (30), contact, feedback, **grievance redressal**, **report fraud**, newsletter with consent & unsubscribe, full Privacy / Terms / Donation & Refund / Volunteer / Child Protection policies, HTML & XML sitemap, custom 404. |
| **Admin panel** `/admin/` | Dashboard with date filters, pages & sections, menus (header, topbar, footer columns), media library, settings, widgets & alerts, redirects & 404 log, SEO audit, programmes, schemes, blog, stories, events, gallery, team, documents, testimonials, FAQs, campaigns, proposals, donations, receipts, expenses, recruitment workflow (Submitted → Under Review → Shortlisted → Interview → Selected → Onboarding → Active / Rejected / Withdrawn), tasks, certificates, memberships, help requests, scholarships, users & roles, data requests, enquiries, feedback, newsletter sending, **website analytics**, **marketing & UTM link builder**, reports & CSV exports, **security centre**, audit log, **database backup & data retention**. |
| **Security** | PDO prepared statements, CSRF on every form, server-side validation & output escaping, HTML sanitiser, password hashing, login & form rate limits, session hardening, remember-me tokens (hashed, rotating), **two-step login (authenticator app) for staff**, server-side role permissions on every admin page & action, private uploads, upload re-encoding, Content-Security-Policy and security headers, audit log, privacy-friendly first-party analytics (no IPs stored; Google Analytics only after consent). |
| **Honest alerts** | A bilingual **Fraud Alert** pop-up (with reporting steps, 1930 and cybercrime.gov.in) that appears **only when an administrator enables it** after a verified incident. A security notice appears after sign-in **only** when real failed sign-in attempts were recorded on that account. |

---

## 2. Folder structure

```
(repository root)
├── README.md               ← this guide (do not upload)
├── TESTING.md              ← test report (do not upload)
├── database.sql            ← schema + default content (import once; do not upload)
├── pwf-config.sample.php   ← template for your PRIVATE config (copy → pwf-config.php)
└── public_html/            ← upload the CONTENTS of this folder to your hosting public_html/
    ├── index.php           ← front controller (all clean URLs)
    ├── install.php         ← one-time installer (creates the first administrator)
    ├── payment-webhook.php ← Razorpay webhook endpoint
    ├── .htaccess           ← rewrites, protection, caching
    ├── config.php, database.php, functions.php, auth.php, csrf.php, validation.php, mail.php,
    │   header.php, footer.php                 ← core (blocked from direct access)
    ├── includes/           ← feature modules (cms, ui, widgets, programs, campaigns, recruitment, …) – blocked
    ├── templates/          ← page templates – blocked
    ├── admin/              ← admin panel (+ admin/includes/)
    ├── assets/             ← css/ (style, enhance, responsive, modules/), js/, images/, fonts/
    └── uploads/            ← public images & PDFs; documents/ and certificates/ are PRIVATE
```

---

## 3. Requirements

* PHP **8.0+** (8.2/8.3 recommended) with `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `json`, `dom`, `openssl` and (recommended) `gd`
* MySQL 5.7+ / MariaDB 10.3+
* Apache or LiteSpeed with `.htaccess` (`mod_rewrite`) — standard on Hostinger
* SSL certificate (free on Hostinger) — required before going live with payments

---

## 4. Installation on Hostinger (replacing the old WordPress site)

### Step 1 — Back up and remove the old WordPress site
1. hPanel → **Websites → Manage → Backups**: download a full backup (files + database) first.
2. hPanel → **Files → File Manager → public_html**: delete the WordPress files (`wp-admin/`, `wp-content/`, `wp-includes/`, `wp-*.php`, `index.php`, `.htaccess`, `license.txt`, `readme.html`, `xmlrpc.php`). Keep `.well-known/` if present.
3. (Optional) the old WordPress database can stay until you are happy with the new site; then delete it in hPanel → Databases.

### Steps 2–6 — Database, upload, configuration, installer, HTTPS
Follow **Quick start → Path 1** at the top of this guide (one ZIP, one SQL import, three values in `pwf-config.php`, then `install.php`). There is **no default admin account** anywhere: the installer creates the first administrator with your own password, locks itself, and Admin → Security Centre reminds you to delete `install.php`.

Developers building from the repository instead: `database.sql` is the base schema (the website brings it up to date on the first request), `pwf-config.sample.php` is the configuration template, and the contents of `public_html/` are the website files.

### Step 7 — First steps in the admin panel (`/admin/`)
1. **My Profile → Two-step login**: turn it on (any authenticator app).
2. **Settings → General & branding**: upload the **horizontal logo** (PNG ~600×200) — it replaces the built-in emblem + wordmark in header and footer; add favicon.
3. **Settings → Contact**: phone, WhatsApp (enables the WhatsApp button), office hours, Google Maps embed link, grievance officer details.
4. **Settings → NGO registration**: enter **only verified** registration numbers (blank values are never shown).
5. **Settings → Donations & bank**: bank/UPI details for offline giving (shown only if filled).
6. **Users & Roles**: create named staff accounts with the right role (Content Editor, Campaign Manager, Donation Manager, Volunteer Coordinator); never share the administrator login.
7. **Team & Management, Certificates & Reports, Gallery, Testimonials (with consent)**: add real data.
8. **Campaigns**: review the five draft campaigns, add images, targets and dates, then publish.
9. **Pages & Sections / Menus / Widgets**: adjust any text, menus, footer columns, announcement bar.

### If you see "We will be back shortly"
The site cannot connect to the database: check `DB_NAME`, `DB_USER` (both with the `u123…_` prefix) and `DB_PASS` in `pwf-config.php`; reset the database user's password in hPanel if unsure. `install.php` shows the exact database error.

---

## 5. Payments — Razorpay (only after merchant approval)

1. Razorpay Dashboard → **Test Mode** → API Keys → put `RAZORPAY_KEY_ID` (`rzp_test_…`) and `RAZORPAY_KEY_SECRET` in `pwf-config.php`.
2. **Webhooks** → URL `https://YOUR-DOMAIN/payment-webhook.php`, a long random secret (same value as `RAZORPAY_WEBHOOK_SECRET`), events `payment.captured`, `payment.authorized`, `payment.failed`, `order.paid`, `refund.processed` (optional `refund.created`). Automatic capture is recommended (Razorpay Dashboard → payment capture settings); the site also captures an authorised payment of the exact amount itself at checkout, from the webhook and from the admin "Re-check payment status" button.
3. Test with Razorpay's test UPI/cards (a TEST MODE banner is shown). Then complete KYC, switch to **live keys** and recreate the webhook in Live Mode.
4. Without keys the donate page honestly shows bank/UPI details (if set) and a contact link — no fake "pay" button. Page sections can be limited with the section option **Show only when** (online donations available / not available / bank details set); the donate page's "How online donations are verified" steps use it, and donation boxes on other pages switch to "See bank transfer details" or "Ask how to donate".
5. **Refunds:** a partial refund made at Razorpay lowers the donation to the net amount kept (public totals and the receipt follow) and the revised receipt is emailed; a full refund marks the donation refunded and cancels its receipt. Admin → donation → "Check refunds with Razorpay" applies a refund whose notification was missed.
6. **Offline donations** (Admin → Donations → Record an offline donation): only money already in the foundation's bank account (cash only after it is deposited). The transaction / cheque reference is stored separately, printed on the receipt and in both CSV exports, and the same reference cannot be recorded twice. The donor's name is listed publicly only when the donor has agreed. Cash of ₹2,00,000 or more is refused (Section 269ST) and receipts for cash above ₹2,000 state that 80G does not apply.
7. **Donor declaration:** while no FCRA registration is entered in Settings, the donate form asks each donor to confirm that they are an Indian citizen giving their own funds from an Indian account, card or UPI.

**How a donation is confirmed:** the server creates a pending donation and a Razorpay order for the exact amount → after checkout it verifies the HMAC signature **and** fetches the payment from Razorpay (order, amount, currency, captured) → only then marks it *success*, issues receipt `PWF/2026-27/000001` and emails it. The signed webhook confirms independently (duplicate events are ignored). All public totals count **only verified** donations; refunds cancel the receipt.

---

## 6. Email
Create the mailbox `info@preritwelfarefoundation.org` in hPanel and set `SMTP_HOST=smtp.hostinger.com`, `SMTP_PORT=465`, `SMTP_ENCRYPTION=ssl`, `SMTP_USER`, `SMTP_PASS` in `pwf-config.php`. With `SMTP_HOST` empty, PHP `mail()` is used. Admin → Settings → Email has a "send test email" button.

---

## 7. Roles & permissions (server-side)

| Role | Typical access |
|---|---|
| Administrator | Everything (users, roles, settings, security, backups) |
| Content Editor | Pages & sections, menus, media, redirects, programmes, schemes, blog, stories, events, gallery, team, documents, testimonials, FAQs, analytics |
| Campaign Manager | Campaigns, updates, proposals, expenses, donation view, reports & exports |
| Donation Manager | Donations, reconciliation, refunds, receipts, expenses, reports & exports |
| Volunteer Coordinator | Recruitment applications, interviews, onboarding, tasks, certificates, events |
| Volunteer Teacher · Registered Volunteer · Member · Donor · Beneficiary Applicant · Supporter | Member dashboard only (own data) |

Public registration can never create staff roles. Visitors who register choose Supporter, Donor or Beneficiary Applicant; **Member** and **Registered Volunteer** are added only when a membership or volunteer application is approved. Expenses always need a second person: whoever recorded or last edited an expense cannot approve it (administrators included). A non-administrator can change another staff account only when they hold every permission that account has. Administrators can create custom roles and edit the permission matrix (Users & Roles → Roles & Permissions).

---

## 8. Analytics, SEO & marketing
* **Built-in analytics** (Admin → Website Analytics): page views, unique visitors, top pages, referrers, UTM sources, devices — no IP addresses stored, Do-Not-Track respected, staff & bots excluded.
* **Marketing** (Admin → Marketing): conversions (donations, applications, sign-ups) by source/campaign and a **UTM link builder** for WhatsApp/social campaigns.
* **Google Analytics 4** (optional): add the measurement ID in Settings → SEO & analytics; it loads only after the visitor accepts the consent banner, and the footer "Cookie settings" link lets visitors change that choice at any time (Google Analytics cookies are removed when they choose "No, thanks").
* **Search Console**: paste the verification code in Settings → SEO, then submit `https://YOUR-DOMAIN/sitemap.xml`.
* Every page has its own title, description, canonical, Open Graph/Twitter tags and structured data (NGO, breadcrumbs, articles, events, FAQs). Admin → SEO Audit lists anything missing.

---

## 9. Maintenance
* **Backups:** Admin → Backup & Data Retention → download a database backup; download `uploads/` via File Manager. Hostinger also keeps automatic backups.
* **Data retention:** the same page removes old logs/analytics, old resolved enquiries and feedback, anonymises old rejected applications and removes names / IP addresses of unsubscribed newsletter records after 30 days, according to Settings (donation & receipt records are never deleted). The clean-up also runs automatically once a day when the admin dashboard is opened.
* **Images:** public photos get smaller copies (640 / 1080 px) on upload, used with `srcset` on cards and galleries. For photos uploaded earlier, use Backup & Data Retention → "Create missing resized image copies".
* **Upgrading an existing database:** no manual SQL is needed any more — uploading the new files runs the pending migrations automatically (section 0). The manual statements below are kept only for reference (they are part of `migration_100_v2_final_catchup`):
  `ALTER TABLE donations ADD COLUMN offline_reference VARCHAR(100) DEFAULT NULL AFTER gateway_method, ADD KEY idx_donations_offline_ref (offline_reference);`
  `UPDATE roles SET allow_self_register = 0 WHERE slug IN ('member','registered_volunteer');`
  `INSERT IGNORE INTO roles (id,name,slug,description,is_staff,is_system,allow_self_register,sort_order) VALUES (11,'Supporter','supporter','Website account to follow your donations, applications, events and certificates.',0,1,1,6);`
  Accounts that registered as "Member" earlier without an approved membership can be listed with
  `SELECT u.id, u.email FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id AND r.slug = 'member' WHERE NOT EXISTS (SELECT 1 FROM members m WHERE m.user_id = u.id AND m.status = 'approved');`
* **Security Centre:** health checks (HTTPS, debug off, installer removed, payment & mail config - including a warning when `MAIL_ENABLED` is false, 2-step login), failed sign-ins, staff sessions. Staff passwords need at least 12 characters wherever they are set.
* Keep `APP_ENV` = `production` on the live site.

---

## 10. Local development
```bash
mysql -u root -e "CREATE DATABASE pwf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root pwf < database.sql
# pwf-config.php next to public_html: APP_ENV 'development', BASE_URL 'http://localhost:8080', DB credentials, INSTALL_KEY
# Serve public_html with Apache (AllowOverride All) on port 8080, then open /install.php
```

See **TESTING.md** for the full test report.
