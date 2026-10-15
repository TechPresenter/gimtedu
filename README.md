# Prerit Welfare Foundation — Website

A complete, responsive NGO website with a secure admin panel, written in **plain PHP, MySQL, HTML, CSS and vanilla JavaScript**.
No frameworks, no Composer, no npm and no build step — upload the files to `public_html/` on any PHP/MySQL host (e.g. Hostinger) and it runs.

*मिलकर बनाये बेहतर कल — Together, let us build a better tomorrow.*

---

## 1. What is included

| Area | Features |
|------|----------|
| **Public website** | Home page with hero slider, Our Work cards, programme pages (Education, Health, Women Empowerment, Youth Empowerment, Rural Development, Environment, Skill Development, Disaster Relief), campaigns with live fundraising progress, events with online registration, gallery with lightbox, blog / news / press / success stories, impact reports, financial transparency, FAQ, contact, grievance redressal, all policy pages, HTML & XML sitemap. |
| **Donations** | Razorpay checkout, **server-side signature + API verification**, **signed webhooks**, automatic receipts (only for confirmed payments), printable receipt with amount in words and optional 80G statement, failure/retry page, refunds, offline (bank/cheque/UPI/cash) donations recorded by finance staff. |
| **Get involved** | Volunteer & internship applications, volunteer-teacher applications (CV upload), staff/job applications (CV upload), membership applications, scholarship applications (document upload), requests for help, application-status lookup by reference number. |
| **Member area** | Register / login / logout, forgot & reset password, dashboard, profile & password change, *My Donations* (with receipts), *My Applications* (with own documents), *My Certificates* (printable). Members only ever see their own records. |
| **Admin panel** (`/admin`) | Dashboard with statistics and chart, campaigns (create / edit / view / delete), donations (search, filters, CSV export, gateway re-check, refunds, notes, offline entries), receipts, volunteers, teachers, staff applications, members & users, help requests, scholarships, events & registrations, gallery uploads, blog, testimonials, FAQs, editable pages & public PDF documents, homepage slider, menu editor, enquiries & grievances, newsletter, reports, admin users, roles & permissions, activity logs. |
| **Security** | PDO prepared statements everywhere, CSRF tokens on every form, server-side validation, output escaping, HTML sanitiser for admin-written content, bcrypt password hashing, login rate-limiting, session hardening, role-based permissions checked on every admin page **and** action, upload validation (type, size, real content, image re-encoding), private documents outside public reach, Content-Security-Policy and other security headers, audit log. |

**Authentic information only.** The site ships with the foundation's real name, logo, address (New Delhi, 110051), email (info@preritwelfarefoundation.org) and the programme descriptions from the existing site. It does **not** invent donations, beneficiaries, registration numbers, phone numbers, team members or impact statistics. All public figures (amount raised, number of donations, volunteers, events) are calculated live from verified database records and are hidden while they are zero. Registration numbers (12A, 80G, PAN, CSR-1, Darpan), phone, WhatsApp, bank details and social links are blank until you enter verified values in **Admin → Settings**.

---

## 2. Folder structure

```
(repository root)
├── README.md            ← this file (do NOT upload)
├── database.sql         ← complete database schema + default data (do NOT upload)
└── public_html/         ← upload the CONTENTS of this folder to your hosting public_html/
    ├── index.php, about.php, … (all public pages)
    ├── process-*.php    ← form handlers
    ├── payment-webhook.php  ← Razorpay webhook endpoint
    ├── config.php, database.php, functions.php, header.php, footer.php,
    │   auth.php, mail.php, validation.php, csrf.php   ← includes (blocked from direct access)
    ├── uploads/         ← logo/, campaigns/, gallery/, blog/ (public images)
    │                      documents/ (PRIVATE), documents/public/ (public PDFs), certificates/ (PRIVATE)
    ├── assets/          ← css/, js/, images/, fonts/
    └── admin/           ← admin pages, includes/, actions/
```

`payment-webhook.php` is the only file added to the requested structure: Razorpay needs a dedicated URL to send signed payment notifications to.

---

## 3. Requirements

* PHP **8.0 or newer** (tested on PHP 8.3; select 8.1+ in hPanel → PHP Configuration) with extensions: `pdo_mysql`, `mbstring`, `curl`, `fileinfo`, `json`, `dom`, `openssl` and (recommended) `gd`
* MySQL 5.7+ or MariaDB 10.3+
* Apache or LiteSpeed with `.htaccess` support (standard on Hostinger and most cPanel hosts)
* An SSL certificate (free on Hostinger) — required for live payments

---

## 4. Installation on Hostinger (or any cPanel / hPanel host)

### Step 1 — Create the database
1. hPanel → **Databases → MySQL Databases** → create a database and a database user with a strong password. Note the database name, user and password (Hostinger names look like `u123456789_pwf`).
2. Open **phpMyAdmin** for that database → **Import** → choose `database.sql` → **Go**.
   (Command line alternative: `mysql -u USER -p DB_NAME < database.sql`)

### Step 2 — Upload the files
1. hPanel → **Files → File Manager** → open `public_html/`.
2. Upload the **contents** of the `public_html/` folder from this project (zip it, upload, then *Extract*). Make sure hidden files such as `.htaccess` are included.
3. Do **not** upload `database.sql` or `README.md`.

### Step 3 — Configure
Edit `public_html/config.php`:

```php
define('APP_ENV', 'production');
define('BASE_URL', 'https://preritwelfarefoundation.org');   // your domain, no trailing slash

define('DB_HOST', 'localhost');
define('DB_NAME', 'u123456789_pwf');
define('DB_USER', 'u123456789_pwfuser');
define('DB_PASS', 'your-database-password');
```

**Recommended (keeps secrets out of the web folder):** instead of editing passwords inside `config.php`, create a file **`pwf-config.php` one folder above `public_html`** (on Hostinger: `/home/u123456789/domains/yourdomain/pwf-config.php`, i.e. next to `public_html`) and put only your secrets there:

```php
<?php
define('BASE_URL', 'https://preritwelfarefoundation.org');
define('DB_NAME', 'u123456789_pwf');
define('DB_USER', 'u123456789_pwfuser');
define('DB_PASS', 'your-database-password');
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_USER', 'info@preritwelfarefoundation.org');
define('SMTP_PASS', 'your-mailbox-password');
define('RAZORPAY_KEY_ID', 'rzp_test_xxxxxxxx');
define('RAZORPAY_KEY_SECRET', 'xxxxxxxxxxxxxxxx');
define('RAZORPAY_WEBHOOK_SECRET', 'a-long-random-string');
```

Values in `pwf-config.php` override the defaults in `config.php`.

### Step 4 — Folder permissions
`uploads/` and its sub-folders must be writable by PHP (normally `755` on Hostinger — already correct after upload). Files should be `644`.

### Step 5 — Enable HTTPS
Activate the free SSL certificate in hPanel, then open `public_html/.htaccess` and **uncomment** the three "Force HTTPS" lines.

### Step 6 — First admin login
Go to `https://yourdomain/admin/` and sign in with:

* **Email:** `admin@preritwelfarefoundation.org`
* **Password:** `Admin@12345`

You will be **forced to choose a new password** before anything else can be used. Then:
1. **Admin → Settings**: check contact details, add phone/WhatsApp (optional), verified registration & 80G details, bank details, social links.
2. **Admin → Admin Users**: create named accounts for staff with the right role; avoid sharing the super-admin login.
3. **Admin → Pages & Documents**: review page texts (e.g. *Our Team* → add management body members) and upload reports/certificates as PDFs.
4. **Admin → Gallery / Campaigns / Events / Blog**: add real photos, campaigns and events.

---

## 5. Payments — Razorpay (test mode first)

1. Create a Razorpay account → Dashboard switched to **Test Mode** → **Account & Settings → API Keys** → generate keys. Put `RAZORPAY_KEY_ID` (`rzp_test_…`) and `RAZORPAY_KEY_SECRET` in `pwf-config.php` / `config.php`.
2. **Webhooks** (Account & Settings → Webhooks → Add):
   * URL: `https://yourdomain/payment-webhook.php`
   * Secret: any long random string — set the **same** value as `RAZORPAY_WEBHOOK_SECRET`
   * Events: `payment.captured`, `payment.failed`, `order.paid`, `refund.processed`
3. Keep "automatic capture" enabled in Razorpay payment settings (default). If a payment arrives as *authorized*, the site captures it automatically during verification.
4. While keys start with `rzp_test_`, every donation page shows a **TEST MODE** banner. Make test payments with Razorpay's test credentials (for UPI use `success@razorpay` / `failure@razorpay`; test cards are listed at https://razorpay.com/docs/payments/payments/test-card-details/).
5. **Going live:** complete Razorpay KYC, generate **live** keys, create the webhook again in Live Mode, replace the keys and webhook secret, and make sure HTTPS is forced.

### How a donation is confirmed (no fake totals possible)
1. The donor fills the form → a **pending** donation and a Razorpay **order** (exact amount) are created on the server.
2. After payment, Razorpay Checkout returns `payment_id`, `order_id`, `signature`. The server checks the **HMAC-SHA256 signature** with the secret key **and** fetches the payment from Razorpay's API to confirm it is `captured`, belongs to this order, and has the exact amount and currency.
3. Only then is the donation marked **success** and a **receipt** issued (number format `PWF/2026-27/000001`) and emailed.
4. The **webhook** independently confirms payments (e.g. if the donor closes the browser), records failures and refunds. Every webhook must carry a valid `X-Razorpay-Signature`.
5. Campaign progress, homepage counters, transparency figures and reports count **only `success` donations**. Refunded donations are excluded and their receipts are marked *cancelled*.
6. Pending payments can be re-checked by finance staff (Donation → *Re-check payment status*), which again asks Razorpay's API.
7. Offline donations (bank transfer, cheque, direct UPI, cash) can be recorded only by admins with the *Donations* permission after the money is received; these are logged in the audit trail.

---

## 6. Email

* With `SMTP_HOST` empty, PHP `mail()` is used.
* For reliable delivery on Hostinger create the mailbox `info@preritwelfarefoundation.org` and set `SMTP_HOST=smtp.hostinger.com`, `SMTP_PORT=465`, `SMTP_ENCRYPTION=ssl`, `SMTP_USER`, `SMTP_PASS`.
* Emails sent: donation receipts, application acknowledgements with reference numbers, status updates (when the admin ticks "email the applicant"), password reset links, welcome emails, event registration confirmations and admin notifications (to *Notification email* in Settings).
* Set `MAIL_ENABLED` to `false` to disable sending (messages are then only written to the PHP error log).

---

## 7. Admin roles & permissions

Permissions are enforced on the server for every admin page and every action (not only by hiding menu items). Denied attempts are written to the activity log.

| Role | Access |
|------|--------|
| Super Admin | Everything, including admin users, roles, settings and logs |
| Manager | Campaigns, donations, applications, members, help requests & scholarships, content, enquiries, reports |
| Finance | Campaigns, donations & receipts, reports |
| Content Editor | Pages, homepage, menus, events, gallery, blog, testimonials, FAQs |
| Application Reviewer | Volunteer/teacher/staff applications, help requests & scholarships, members, enquiries |

Super admins can create additional roles in **Admin → Roles & Permissions**. The last active super admin cannot be deactivated or demoted.

---

## 8. Security notes

* **Private files:** CVs, scholarship and help-request documents (`uploads/documents/`) and certificate PDFs (`uploads/certificates/`) are blocked by `.htaccess` and are only delivered through PHP after checking the logged-in owner or admin permission. Only PDFs placed in `uploads/documents/public/` by admins are public.
* `uploads/` can never execute scripts; uploaded images are re-encoded (removing hidden content) and given random names.
* Include files (`config.php`, `functions.php`, …) and `admin/includes/` return **403** if requested directly.
* Passwords use `password_hash()` (bcrypt). Password-reset tokens are random, stored hashed, valid for 1 hour and single-use.
* Login is rate-limited (5 failures per email / 10 per IP in 15 minutes) for both members and admins; public forms have per-IP limits and a honeypot field.
* Admin sessions expire after 60 minutes of inactivity and are bound to the browser that logged in. The default admin password must be changed at first login.
* If the site is behind Cloudflare, set `TRUSTED_PROXY_HEADER` to `'HTTP_CF_CONNECTING_IP'` so rate limits use the visitor's real IP.
* Keep `APP_ENV` as `production` on the live site (errors are logged, not displayed).
* Back up the database (hPanel → Backups or phpMyAdmin export) and the `uploads/` folder regularly.

---

## 9. Running locally (optional)

```bash
mysql -u root -e "CREATE DATABASE pwf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -u root pwf < database.sql
# create ../pwf-config.php next to public_html with APP_ENV 'development',
# BASE_URL 'http://localhost:8000' and your local DB credentials, then:
cd public_html && php -S localhost:8000
```

(PHP's built-in server ignores `.htaccess`; use Apache/LiteSpeed to test the access rules.)

---

## 10. Testing checklist (performed before delivery)

The project was tested on Apache 2.4 + PHP 8.3 + MariaDB 10.11 with a mock of the Razorpay API in test mode:

* All public and admin pages load without PHP, JavaScript or Content-Security-Policy errors; every internal navigation link resolves.
* Every form saves validated data, rejects invalid data with clear messages, enforces CSRF tokens and honeypots.
* Uploads: real PDFs/images accepted, fake PDFs, PHP files and wrong extensions rejected; private files return 403 when requested directly; `.php` files in `uploads/` are not executed.
* Authentication: registration, login, logout, wrong password, brute-force lockout, open-redirect protection, forgot/reset password (single-use token), profile & password change.
* Member isolation: users see only their own donations, receipts, applications, documents and certificates.
* Payments: order creation, verified checkout, forged signature, payment from another order, under-payment, failed payment & retry, signed webhook (valid / invalid signature, replay, amount mismatch), refund webhook, admin re-check, offline donation, receipts only for confirmed payments, totals from verified donations only.
* Admin: forced password change, all CRUD screens, application review & email notification, certificates, membership approval, CSV exports, reports, HTML sanitising, menu link validation, and role permissions (an editor is denied finance, people, settings, users and logs pages and actions).
* Mobile layouts at 375–390 px: no horizontal scrolling, working mobile menu, forms and admin sidebar.
