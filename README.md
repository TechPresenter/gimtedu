# Prerit Welfare Foundation — Website & Admin CMS (v2)

A premium, fully dynamic NGO website with an admin CMS, written in **plain PHP 8, MySQL, HTML, CSS and vanilla JavaScript**.
No frameworks, no Composer, no npm and no build step — upload the files to `public_html/` on any PHP/MySQL host (e.g. Hostinger) and it runs.

*मिलकर बनाये बेहतर कल — Together, let us build a better tomorrow.*

> **Authentic information only.** The site ships with the foundation's real name, logo, address (New Delhi, 110051), email and descriptions of its areas of work. It does **not** contain invented donations, beneficiaries, statistics, testimonials, partners, awards or registration numbers. Every public figure (amount raised, donations, volunteers, events) is calculated live from **verified** records and hidden while it is zero. Registration details (PAN, 12A, 80G, CSR-1, Darpan, FCRA), phone, WhatsApp, bank details and social links stay hidden until you enter verified values in **Admin → Settings**. The five relief/education/food campaigns are included as **drafts** — review, add real details and publish them when appropriate.

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

### Step 2 — Create a NEW database
1. hPanel → **Databases → MySQL Databases** → create database + user. Use a password with **letters and numbers only** (avoids copy/paste and quoting problems). Note the full names (e.g. `u123456789_prerit`).
2. **phpMyAdmin** → select that database → **Import** → choose `database.sql` → **Go**. You should see 51 tables.

### Step 3 — Upload the website
1. Zip the **contents** of `public_html/` (or use the ready package), upload to hPanel **File Manager → public_html**, then **Extract**. Make sure `.htaccess` files are included (File Manager → Settings → show hidden files).
2. Folder permissions: folders `755`, files `644`; `uploads/` and its sub-folders must be writable (default on Hostinger).

### Step 4 — Private configuration
1. Copy `pwf-config.sample.php` to **`pwf-config.php`** and fill in `BASE_URL`, `DB_NAME`, `DB_USER`, `DB_PASS`, a long random **`INSTALL_KEY`**, and (later) SMTP and Razorpay values.
2. Upload it **one folder above `public_html`** (e.g. `/home/u123456789/domains/preritwelfarefoundation.org/pwf-config.php`). It can never be downloaded from the web there.
   *If your host does not allow that, put the same `define(...)` lines at the top of `public_html/config.php`.*

### Step 5 — Run the installer
1. Open `https://YOUR-DOMAIN/install.php` — it checks PHP, extensions, folder permissions and the database.
2. Enter the **INSTALL_KEY** and create the **first administrator** (name, email, a strong 12+ character password). There is **no default admin account** anywhere.
3. The installer locks itself. Then set `INSTALL_KEY` back to `''` and delete `install.php` (Admin → Security Centre reminds you).

### Step 6 — Enable HTTPS
Activate SSL in hPanel, then in `public_html/.htaccess` uncomment the three **Force HTTPS** lines. Keep `BASE_URL` on `https://`.

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
2. **Webhooks** → URL `https://YOUR-DOMAIN/payment-webhook.php`, a long random secret (same value as `RAZORPAY_WEBHOOK_SECRET`), events `payment.captured`, `payment.failed`, `order.paid`, `refund.processed` (optional `refund.created`).
3. Test with Razorpay's test UPI/cards (a TEST MODE banner is shown). Then complete KYC, switch to **live keys** and recreate the webhook in Live Mode.
4. Without keys the donate page honestly shows bank/UPI details (if set) and a contact link — no fake "pay" button.

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
| Volunteer Teacher · Registered Volunteer · Member · Donor · Beneficiary Applicant | Member dashboard only (own data) |

Public registration can never create staff roles. Administrators can create custom roles and edit the permission matrix (Users & Roles → Roles & Permissions).

---

## 8. Analytics, SEO & marketing
* **Built-in analytics** (Admin → Website Analytics): page views, unique visitors, top pages, referrers, UTM sources, devices — no IP addresses stored, Do-Not-Track respected, staff & bots excluded.
* **Marketing** (Admin → Marketing): conversions (donations, applications, sign-ups) by source/campaign and a **UTM link builder** for WhatsApp/social campaigns.
* **Google Analytics 4** (optional): add the measurement ID in Settings → SEO & analytics; it loads only after the visitor accepts the consent banner.
* **Search Console**: paste the verification code in Settings → SEO, then submit `https://YOUR-DOMAIN/sitemap.xml`.
* Every page has its own title, description, canonical, Open Graph/Twitter tags and structured data (NGO, breadcrumbs, articles, events, FAQs). Admin → SEO Audit lists anything missing.

---

## 9. Maintenance
* **Backups:** Admin → Backup & Data Retention → download a database backup; download `uploads/` via File Manager. Hostinger also keeps automatic backups.
* **Data retention:** the same page removes old logs/analytics and anonymises old rejected applications according to Settings (donation & receipt records are never deleted).
* **Security Centre:** health checks (HTTPS, debug off, installer removed, payment & mail config, 2-step login), failed sign-ins, staff sessions.
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
