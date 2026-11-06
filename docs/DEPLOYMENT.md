# GIMT SmartCampus — Deployment Guide

How to install GIMT SmartCampus (public website, admin ERP, Student / Faculty portals and API — one PHP application
with one MySQL database) on (A) a Linux server with Apache and (B) Windows with XAMPP, and how to take it live.

Related: [`REPORT.md`](REPORT.md) (what was delivered, what GIMT must provide), [`PORTAL.md`](PORTAL.md),
[`CHATBOT.md`](CHATBOT.md) §8 (chat streaming on production servers).

> **The installer drops data.** `php tools/install.php --fresh` deletes **every table** in the configured database
> before creating the schema. Run the installer only on a new, empty database — never on a live one. To update a running
> site, follow §12.

---

## 1. Requirements

| Component | Version / notes |
|---|---|
| Web server | Apache 2.4 with `mod_rewrite` (required), `mod_headers` and `mod_deflate` (recommended); `AllowOverride All` for the application folder (the bundled `.htaccess` files do the routing and protect private folders) |
| PHP | **8.2** (developed and tested on PHP 8.2.12; 8.1 is the minimum the security checklist accepts) |
| Database | **MySQL 8.0+ or MariaDB 10.6+** (developed on MariaDB 10.4); InnoDB, `utf8mb4` / `utf8mb4_unicode_ci`. The application sets its own `sql_mode` and time zone per connection |
| HTTPS | A TLS certificate for the production domain |
| Node.js | **Not needed on the server.** Only to rebuild the front-end bundles after source changes (Node 18 or newer, §6) |
| Outbound HTTPS | From the server to the AI providers, CAPTCHA verification, SMTP server and SMS / WhatsApp gateway that GIMT configures |

### PHP extensions

| Extension | Needed for | |
|---|---|---|
| `pdo_mysql` | Database access | required |
| `mbstring` | Unicode text handling everywhere | required |
| `openssl` | HTTPS calls, SMTP over TLS, encryption of stored AI keys | required |
| `curl` | AI chatbot, CAPTCHA verification, SMS / WhatsApp gateways, integration checks, SEO audit crawler | required |
| `fileinfo` | MIME checks of uploaded files | required |
| `gd` | Re-encoding uploaded images (strips hidden content); uploads are accepted without it but not re-encoded | required (security) |
| `zip` | Excel (XLSX) import / export, uploads backups | required |
| `zlib` | Compressed database backups (`.sql.gz`) | required |
| `dom`, `simplexml`, `libxml` (the XML extensions) | Rich-text sanitiser, XLSX import, SEO audit | required |
| `ctype`, `filter`, `json`, `session` | Core PHP (enabled by default) | required |
| `intl` | Internationalised email domains (IDN) in forms | optional |
| `sodium` | Preferred encryption of stored AI keys (falls back to OpenSSL AES-256-GCM) | optional |

Recommended `php.ini` values: `upload_max_filesize` and `post_max_size` at least as large as the limits in
System Settings › Security › File uploads (defaults: 5 MB images / documents, 50 MB videos — e.g. `64M`),
`memory_limit = 256M` (Excel imports up to 64 MB unpacked), `max_execution_time = 120` (backups and restores raise
their own limit), `expose_php = Off`.

The background SEO audit uses `exec` (Linux) or `popen` (Windows) to start a PHP worker; if these functions are
disabled the audit still runs, driven by the open browser page.

---

## 2. Linux: Apache 2.4 + PHP 8.2 + MySQL 8 / MariaDB 10.6+

The commands below use Debian / Ubuntu names; package names differ on other distributions.

### 2.1 Packages

```bash
sudo apt install apache2 mysql-server \
     php8.2 libapache2-mod-php8.2 php8.2-mysql php8.2-mbstring php8.2-curl php8.2-gd php8.2-zip php8.2-xml php8.2-intl
sudo a2enmod rewrite headers deflate
php -m        # check: pdo_mysql mbstring openssl curl fileinfo gd zip zlib dom SimpleXML
```

PHP-FPM (`php8.2-fpm` + `proxy_fcgi`) works as well; see §2.6 for environment variables under FPM.

### 2.2 Upload the code

Copy the project to the server, e.g. `/var/www/gimt`, so that `/var/www/gimt/index.php` is the site root. Do not upload
`frontend/node_modules/`, `tools/.cache/`, `.git/`, a development `config/local.php`, or the contents of `storage/`
and `assets/uploads/` from a development machine (keep their `.htaccess` / `.gitkeep` files). `admin/build/`,
`portal/build/`, `assets/site/` and `assets/css/` **must** be uploaded — they are the compiled front-end.

### 2.3 Apache virtual host

```apache
<VirtualHost *:80>
    ServerName www.gimt.ac.in
    DocumentRoot /var/www/gimt
    <Directory /var/www/gimt>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    ErrorLog ${APACHE_LOG_DIR}/gimt-error.log
    CustomLog ${APACHE_LOG_DIR}/gimt-access.log combined
</VirtualHost>
```

The root `.htaccess` provides pretty URLs (`/about`, `/programs/{slug}`, `/blog/{slug}` …), `/sitemap.xml` and
`/robots.txt`, the API router (`/api/*`), the 404 / 500 pages and denies `app/`, `config/`, `database/`, `storage/`,
`tools/`, `frontend/`, `includes/`, `docs/`, dot-files and `*.sql|md|log|sh|json` files. `admin/.htaccess` and
`portal/.htaccess` route the two single-page apps; `assets/uploads/.htaccess` disables script execution in uploads.
Install at the **root of a domain or sub-domain** (sub-folder installs: §4).

### 2.4 Database

```sql
CREATE DATABASE gimt_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'gimt'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON gimt_erp.* TO 'gimt'@'localhost';
FLUSH PRIVILEGES;
```

The application user needs to create, alter and drop tables in its own database (installer and backup restore).
Give InnoDB a realistic buffer pool (e.g. `innodb_buffer_pool_size = 1G` on a server with 4 GB RAM); attendance and
report screens are slow with the 16 MB default of small installations.

### 2.5 `config/local.php`

```bash
cp config/local.example.php config/local.php
php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"      # 64-character application key
```

Edit `config/local.php`:

| Key | Value |
|---|---|
| `db.host`, `db.port`, `db.name`, `db.user`, `db.pass` | the database created in §2.4 |
| `app.env` | `production` (hides PHP errors, sends real mail). `dev` shows errors and writes every outgoing email to `storage/logs/mail.log` instead of sending it — development only |
| `app.url` | the canonical site URL without a trailing slash, e.g. `https://www.gimt.ac.in` — drives canonical tags, sitemaps, QR codes, email links and the HTTPS redirect host. Required in production (the security checklist fails without it) |
| `app.key` | the 64-character random key. It signs remember-me cookies and the QR codes on receipts, certificates and marksheets, encrypts stored AI keys and derives the cron URLs. **Set it before going live and never change it afterwards** (printed QR codes and stored keys would stop validating) |
| `app.force_https` | `true` on the live site (301 to HTTPS; the session cookie is `Secure` automatically on HTTPS) |
| `app.csp_report_only` | optional; `false` turns off the public website's report-only Content-Security-Policy header (on by default) |
| `app.csp_report_uri` | optional; absolute URL or site path that receives CSP violation reports |

Keep the file readable only by the deploy user and the web server group (`chmod 640`). It is ignored by git and denied
by `.htaccess`.

### 2.6 Environment variables (`GIMT_*`)

Every variable is optional and, when set, wins over `config/local.php` (database / app values) or over the admin
setting (integration values). Integration secrets set this way never touch the database.

| Variable | Overrides |
|---|---|
| `GIMT_DB_HOST`, `GIMT_DB_PORT`, `GIMT_DB_NAME`, `GIMT_DB_USER`, `GIMT_DB_PASS` | database connection |
| `GIMT_ENV` | `app.env` (`production` \| `dev`) |
| `GIMT_URL` | `app.url` |
| `GIMT_APP_KEY` | `app.key` |
| `GIMT_CAPTCHA_SITE_KEY`, `GIMT_CAPTCHA_SECRET` | System Settings › Integrations › Spam protection (CAPTCHA) |
| `GIMT_GA4_ID`, `GIMT_GTM_ID`, `GIMT_META_PIXEL_ID` | System Settings › Integrations › Analytics & tags |
| `GIMT_GSC_VERIFICATION`, `GIMT_BING_VERIFICATION` | Search Console / Bing verification codes (same section) |
| `GIMT_GOOGLE_MAPS_API_KEY` | System Settings › Integrations › Google Maps (Maps Embed API browser key) |
| `GIMT_SENTRY_DSN` | System Settings › Integrations › Error monitoring |
| `GIMT_ANTHROPIC_API_KEY`, `GIMT_OPENAI_API_KEY`, `GIMT_GEMINI_API_KEY` | AI Chatbot › Settings › AI providers |
| `GIMT_CA_BUNDLE` | path to a CA bundle when PHP cannot verify outbound HTTPS certificates |
| `GIMT_CSP_REPORT_ONLY` | `0` / `off` turns off the website's report-only CSP (`app.csp_report_only`) |
| `GIMT_CSP_REPORT_URI` | CSP report endpoint (`app.csp_report_uri`) |

How to set them: Apache + mod_php — `SetEnv GIMT_APP_KEY "…"` inside the `<VirtualHost>`; PHP-FPM — `env[GIMT_APP_KEY] = …`
in the pool file. Command-line tools (`tools/install.php`, `tools/seed.php`) do not see Apache / FPM variables: keep the
database credentials in `config/local.php`, or `export` the variables in the shell before running them.
System Settings › Integrations lists which variables are set (never their values).

### 2.7 Create the database tables

```bash
cd /var/www/gimt
php tools/install.php --admin-email=it@gimt.ac.in --admin-password='Choose-A-Strong-Passw0rd!' --admin-name="IT Administrator"
```

| Option | Effect |
|---|---|
| *(none)* | runs every `database/schema/*.sql` file in natural order, then the **core** seeders `01`–`09` (roles and permissions, settings, the Super Admin, email templates, grading scale, academic sessions 2023-24 … 2027-28, certificate templates, website CMS pages / menus / home sections, policy pages, chatbot, SEO, theme and popup settings) |
| `--demo` | also loads the demo dataset (seeders `10`+): about 1,290 students, faculty and staff, programs, fees and payments, attendance, exams and results, library, hostel, transport, placement, alumni, website content, demo role users and portal accounts. **For demonstration or staging only — never on the production database** |
| `--fresh` | **drops every table in the database first** |
| `--no-seed` | schema only |
| `--admin-email=…`, `--admin-password=…`, `--admin-name=…` | the Super Admin account (username is always `admin`). Without them: `admin@gimt.ac.in` / `Admin@12345` — change it immediately |

The installer is meant for an empty database: run on a database that already has the tables (without `--fresh`) it
stops with an SQL error. There is no web installer.

A production install without `--demo` starts with no programs, students or staff; enter departments, programs,
subjects, fee structures and people in the admin panel (CSV / Excel import is available on the main lists). The website
renders sensibly with empty tables.

### 2.8 File permissions

The web server user (here `www-data`) must be able to write to `storage/` (sub-folders `backups`, `cache`, `logs`,
`private`, `tmp`) and `assets/uploads/`. Everything else can stay read-only.

```bash
sudo chown -R deploy:www-data /var/www/gimt
sudo find /var/www/gimt -type d -exec chmod 755 {} \;  && sudo find /var/www/gimt -type f -exec chmod 644 {} \;
sudo chmod -R g+w /var/www/gimt/storage /var/www/gimt/assets/uploads
sudo chmod 640 /var/www/gimt/config/local.php
```

`storage/cache/site-phone-regions.php` (phone-number metadata cache) and `storage/logs/php-errors.log` are created at
run time; `storage/` must stay writable. `storage/` is denied to web visitors by `.htaccess` and `storage/.htaccess`.

### 2.9 HTTPS

1. Obtain a certificate (e.g. `sudo certbot --apache -d www.gimt.ac.in -d gimt.ac.in`).
2. Set `app.url` to the `https://` address and `app.force_https => true` (§2.5).
3. PHP then redirects HTTP to HTTPS using the configured host, sends `Strict-Transport-Security`, and marks the session
   (`GIMTSESSID`) and remember-me cookies `Secure` (they are always `HttpOnly`, `SameSite=Lax`). Enable HSTS at server
   level too if static files should carry it.
4. Behind a load balancer / CDN that terminates TLS, make sure it sends `X-Forwarded-Proto: https`. Turn on
   System Settings › Security › Login protection › "Trust X-Forwarded-For" only when exactly one trusted proxy sits in
   front of the server (the last `X-Forwarded-For` entry is then used as the client IP for lockouts and logs).

### 2.10 Scheduled tasks (cron)

Two scheduled tasks exist. Both are plain HTTPS URLs protected by a token derived from `app.key`; the exact URL and
command (Linux and Windows) are shown to administrators in the admin panel:

| Task | Where the URL is shown | Suggested schedule | What it does |
|---|---|---|---|
| Automatic database backup | Admin › Backup › Schedule & retention › Server cron job | daily at 02:00 | runs the backup when one is due according to System Settings › Backup (off / daily / weekly / monthly), prunes old backups (retention) |
| Email campaigns and message queue | Admin › Newsletter / Email › Campaigns › Automatic delivery | every 5 minutes | starts scheduled campaigns and delivers queued campaign, notice and event-reminder messages |

```cron
0 2 * * *   curl -fsS "https://www.gimt.ac.in/api/backup/cron?token=<token from Admin › Backup>" > /dev/null
*/5 * * * * curl -fsS "https://www.gimt.ac.in/api/newsletter/cron?token=<token from Admin › Campaigns>" > /dev/null
```

Changing `app.key` changes both tokens — copy the new URLs afterwards. Other housekeeping (chat retention purge,
sitemap cache, visitor statistics) runs automatically with normal traffic and needs no cron.

---

## 3. Windows: XAMPP

Suitable for a local demonstration, training or an on-premise intranet server.

1. **Install XAMPP** with PHP 8.2 (it bundles Apache and MariaDB 10.4). For a real production database prefer MySQL 8 or
   MariaDB 10.6+.
2. **Copy the project** to `C:\xampp\htdocs\gimt`.
3. **PHP extensions** — in `C:\xampp\php\php.ini` make sure these lines are present and not commented out (no leading
   `;`): `extension=curl`, `extension=fileinfo`, `extension=gd`, `extension=mbstring`, `extension=openssl`,
   `extension=pdo_mysql`, `extension=zip`; optionally `extension=intl` and `extension=sodium`. Set
   `upload_max_filesize` / `post_max_size` (e.g. `64M`) and `memory_limit = 256M`. Check with `C:\xampp\php\php.exe -m`.
4. **MariaDB memory** — in `C:\xampp\mysql\bin\my.ini` raise `innodb_buffer_pool_size` (16 MB by default) to e.g. `512M`.
5. **Apache** — XAMPP loads `mod_rewrite` and allows `.htaccess` in `htdocs` by default (check that
   `LoadModule rewrite_module modules/mod_rewrite.so` is not commented out in `C:\xampp\apache\conf\httpd.conf`).
   Serve the site at the root of its own port so no `.htaccess` edits are needed — append to
   `C:\xampp\apache\conf\extra\httpd-vhosts.conf`:

   ```apache
   Listen 8080
   <VirtualHost *:8080>
       DocumentRoot "C:/xampp/htdocs/gimt"
       <Directory "C:/xampp/htdocs/gimt">
           Options -Indexes +FollowSymLinks
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

   Restart Apache from the XAMPP Control Panel; the site is then `http://localhost:8080/`. (To use
   `http://localhost/gimt/` instead, see §4.)
6. **Database** (XAMPP's MariaDB `root` user has no password by default — set one, or create a dedicated user, for
   anything beyond a local demo):

   ```bat
   C:\xampp\mysql\bin\mysql.exe -uroot -e "CREATE DATABASE gimt_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
   ```
7. **Configuration** — copy `config\local.example.php` to `config\local.php` and set the database credentials,
   `app.url` (e.g. `http://localhost:8080`), `app.key` (`C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32));"`),
   `app.env` (`production`, or `dev` to see PHP errors and write emails to `storage\logs\mail.log`) and
   `app.force_https => false` unless the server has a certificate.
8. **Install** (from `C:\xampp\htdocs\gimt`; same options and warnings as §2.7):

   ```bat
   C:\xampp\php\php.exe tools\install.php --demo
   ```
   Omit `--demo` for real use and pass `--admin-email` / `--admin-password`.
9. **Scheduled tasks** — use the Windows Task Scheduler commands shown on the two admin pages listed in §2.10
   (`schtasks /Create … /TR "curl -fsS <url>"`; `curl` is built into Windows 10 and later).
10. Open `http://localhost:8080/` (website), `http://localhost:8080/admin/` (admin) and
    `http://localhost:8080/portal/` (portals).

Development alternative without Apache: `php -S 127.0.0.1:8000 tools/router.php` (PHP's built-in server; the router
mirrors the `.htaccess` rules). For development only.

---

## 4. Installing in a sub-folder

The code computes its base path from `app.url` (or the document root), and the compiled bundles use relative asset
paths, so `https://example.org/gimt/` can work. It needs these edits, and it was **not** part of the final QA (the
tested layout is the root of a domain or virtual host). Search engines only read `/robots.txt` at the domain root, so
the public production website should live at the root of its own domain or sub-domain.

1. `app.url` = `https://example.org/gimt` (with the folder).
2. Root `.htaccess`: `RewriteBase /gimt/`; `ErrorDocument 404 /gimt/404.php` and `ErrorDocument 500 /gimt/500.php`;
   in the "/index.php → /" rule change the target `/` to `/gimt/`.
3. Re-test pretty URLs, `/gimt/admin/`, `/gimt/portal/` and `/gimt/api/…` (see §10).

---

## 5. First sign-in and hardening

1. Sign in at `/admin/login` with the Super Admin from §2.7. Change the password at Admin › Profile › Password & Security
   if the default was used.
2. Open **Admin › Security**. Every checklist item should pass before go-live: default administrator password changed,
   demo account passwords removed, production mode, HTTPS enforced, application key set, site URL configured, script
   execution blocked in uploads, private storage protected, password policy, account lockout, idle timeout, recent and
   automatic backups, PHP version.
3. If the demo data was installed (staging), deactivate or delete the demo users in Users & Roles › Users and the demo
   portal accounts in Portal Access — or better, reinstall production without `--demo`.
4. Fill in System Settings (General, Website, Academic, Email & SMS, Payment, Security, Backup, Analytics, Social,
   Integrations) with GIMT's verified details — the full list is in `REPORT.md` §6.
5. Decide whether student self-activation stays on (Admin › Portal Access › Settings).

---

## 6. Building the front-end assets

The compiled bundles are committed and uploaded with the code, so a normal deployment needs **no build step**:

| Output | Built from | Command (run in `frontend/`, Node 18+) |
|---|---|---|
| `admin/build/` (admin panel) | `frontend/src` | `npm run build` (type-check + Vite build) |
| `assets/site/` (website islands) | `frontend/site` | `npm run build:site` |
| `portal/build/` (Student / Faculty portals) | `frontend/portal` | `npm run build:portal` |
| `assets/css/style.css`, `assets/css/print.css` (website + print CSS) | `assets/css/src/style.css` | `bash tools/build-css.sh` (run from the project root) |

Rebuild only after changing front-end source, on a workstation or CI machine, then upload the outputs:

```bash
cd frontend
npm ci                    # install exact dependency versions from package-lock.json
npm run build             # admin  -> ../admin/build
npm run build:site        # website islands -> ../assets/site
npm run build:portal      # portals -> ../portal/build
cd ..
bash tools/build-css.sh   # website + print CSS (needs bash: Git Bash on Windows)
```

`tools/build-css.sh` uses a standalone Tailwind binary at `tools/bin/tailwindcss` when present, otherwise
`npx --yes tailwindcss@3.4.17` (downloaded from npm on first use). Type-checks without building: `npm run typecheck`,
`npm run typecheck:site`, `npm run typecheck:portal`. On machines with little memory the admin build may need
`NODE_OPTIONS=--max-old-space-size=3072`.

---

## 7. Email delivery

1. System Settings › Email & SMS › Mail delivery (SMTP): driver, host, port, encryption, username, password, from email
   and name; use the test button to send a test message. With `app.env = dev`, mail is written to
   `storage/logs/mail.log` instead of being sent. With the SMTP driver but no host, PHP `mail()` is used.
2. Publish **SPF**, **DKIM** and **DMARC** DNS records for the sending domain (from the mail provider) so that password
   resets, portal credentials, receipts and newsletters reach inboxes.
3. Every message is recorded in Admin › Newsletter / Email › Message Logs (emails containing sign-in details are logged
   without their content).

---

## 8. Backups and restore

- **Admin › Backup:** create a database backup (`storage/backups/gimt-db-*.sql.gz`, pure-PHP dump, also importable with
  the mysql client or phpMyAdmin) or an uploads backup (`gimt-files-*.zip` of `assets/uploads`); download, verify
  (size, SHA-256, archive), delete, and restore — all tables or selected ones, with an optional automatic safety backup
  first. A restore never drops tables: missing tables are created, then the restored tables are emptied and refilled
  in one transaction (rolled back on failure). Type `RESTORE` to confirm.
- **Automatic backups:** set the schedule and retention in System Settings › Backup (or on the Backup page) and add the
  cron job from §2.10. Automatic backups cover the database only.
- **Outside the application:** copy `storage/backups/` off the server regularly, and back up `storage/private/`
  (admission, student and employee documents, expense bills, offer letters) and `config/local.php` with server-level
  tools — they are not included in the in-app uploads ZIP.
- Manual restore of a dump with the client: `gunzip < gimt-db-….sql.gz | mysql -u gimt -p gimt_erp`.

---

## 9. Go-live SEO steps

1. `app.url` = the production `https://` address (canonical URLs, sitemaps and verification links depend on it).
2. System Settings › Integrations › Search engines: switch **off** "Discourage search engines from indexing this website"
   (`seo_block_indexing`; when on, `/robots.txt` serves `Disallow: /`). Keep it on for staging copies.
3. Check `https://www.gimt.ac.in/robots.txt` (rules: Website CMS › SEO Management › Robots & sitemap; empty = recommended defaults that
   block `/admin/`, `/api/`, `/portal/`, `/storage/`, `/search` and filtered / tracking URLs) and
   `https://www.gimt.ac.in/sitemap.xml` (sitemap index with the child sitemaps).
4. Enter the Google Search Console and Bing verification codes (System Settings › Integrations › Analytics & tags, or
   `GIMT_GSC_VERIFICATION` / `GIMT_BING_VERIFICATION`), verify the property in both tools and **submit `/sitemap.xml`**.
5. Enter the GA4 / GTM / Meta Pixel IDs only after the Cookie Policy has been reviewed; the consent banner appears
   automatically once a tag is configured, and tags load only after consent (Google Consent Mode v2).
6. Campaign landing pages (`/admissions-open/...`) stay `noindex` unless setting `landing_indexable` is `1`.
7. Run the crawler in Website CMS › SEO Management › Audit once on the live site and fix reported issues; review titles /
   descriptions and 1200 × 630 share images for key pages.
8. Add redirects from the old website's URLs in Website CMS › Redirects (CSV import supported) and watch the 404 report
   there after launch.

---

## 10. Post-deploy smoke checklist

| Check | Expected |
|---|---|
| `https://…/` and `http://…/` | homepage over HTTPS (HTTP redirects with 301) |
| `/about`, `/programs`, a program page, `/notices`, `/events`, `/blog`, `/contact`, `/apply` | pages load with no console errors |
| `/about.php`, `/About`, `/about/` | 301 to `/about` |
| `/this-page-does-not-exist` | branded 404 page with status 404 |
| `/sitemap.xml`, `/robots.txt` | XML sitemap index; robots rules with the `Sitemap:` line |
| `/config/local.php`, `/storage/logs/php-errors.log`, `/app/init.php`, `/.git/config` | 403 Forbidden |
| `/admin/` | admin sign-in page (not "Admin build not found") |
| Admin › Security | all checklist items pass |
| `/portal/student/login`, `/portal/faculty/login` | portal sign-in pages |
| Submit the contact form and an enquiry | success message; record appears in Admin (Contact Messages / Enquiries); notification raised |
| Forgot password for an admin account | email arrives with a working reset link (`app.url` host) |
| Print a fee receipt and scan its QR code | `/api/fees/verify` shows "Genuine receipt" |
| Admin › Backup › create database backup | completed backup listed |
| Cron URLs (§2.10) | "last run" time shown on the Backup and Campaigns pages |
| Light / dark theme switch, mobile width (390 px) | no horizontal scrolling, readable text |

---

## 11. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| Homepage works but `/about`, `/programs/...` or `/api/...` give Apache 404 | `mod_rewrite` not enabled or `AllowOverride None`. Enable the module and `AllowOverride All` (§2.3); in a sub-folder set `RewriteBase` (§4) |
| `/admin/` shows "Admin build not found" or a blank page | `admin/build/` missing or incomplete — upload it, or build it (§6). Same for `/portal/` (`portal/build/`) |
| Website works but sliders, popups, chat or forms do not enhance | `assets/site/` not uploaded or outdated — upload or run `npm run build:site` |
| Unstyled website or print pages | `assets/css/style.css` / `print.css` missing — upload or run `bash tools/build-css.sh` |
| "Something went wrong" / 500 page | read `storage/logs/php-errors.log` (also System Settings › Integrations › Error monitoring, or Admin › Security › Error log); check database credentials and PHP extensions (§1) |
| Uploads, backups or imports fail | `storage/` or `assets/uploads/` not writable by the web server (§2.8); PHP `upload_max_filesize` / `post_max_size` too small; `zip` / `gd` extension missing |
| Emails not delivered | `app.env` is `dev` (mail goes to `storage/logs/mail.log`); wrong SMTP settings (use the test button); missing SPF / DKIM / DMARC; check Message Logs for the error |
| Password-reset links or QR codes point to the wrong host | `app.url` not set (§2.5) |
| Signed out repeatedly / "session expired" | server clock or idle timeout (System Settings › Security › Sessions); cookies blocked; a proxy not forwarding `X-Forwarded-Proto` while `force_https` is on |
| Everyone shares one IP in login logs / lockouts hit all users | the site is behind a proxy: enable "Trust X-Forwarded-For" (only behind exactly one trusted proxy, §2.9) |
| Chatbot "Test connection" fails with a TLS / certificate error | PHP has no CA bundle — set `GIMT_CA_BUNDLE` or `curl.cainfo` in `php.ini`; allow outbound HTTPS (`CHATBOT.md` §8) |
| Automatic backups or campaigns never run | cron job missing or token outdated after an `app.key` change — copy the current URL from the admin page (§2.10) |
| Attendance / reports pages slow | raise `innodb_buffer_pool_size` (§2.4, §3) |
| `tools/install.php` stops with "table already exists" | the database is not empty — use a new database (or `--fresh`, which deletes everything) |

---

## 12. Updating an existing installation

1. Take a database backup (Admin › Backup) and copy it, `storage/private/` and `config/local.php` off the server.
2. Upload the new code (including the compiled `admin/build`, `portal/build`, `assets/site`, `assets/css`), keeping
   `config/local.php`, `storage/` and `assets/uploads/`.
3. Apply only the **new** `database/schema/NN_*.sql` files of the release with the mysql client, in order
   (`mysql -u gimt -p gimt_erp < database/schema/NN_name.sql`). **Never** run `tools/install.php` on the live database.
4. Seeders: only those documented as live-safe — `php tools/seed.php 09_stage_c_fixes` (idempotent data fixes) and the
   settings seeders that insert missing keys only (`06_visitor_counter`, `07_website_v2`, `09_seo`, `09_theme`,
   `09_enquiry_popup`). Other seeders replace the data they own (e.g. `05_cms_core` rebuilds its CMS pages and menus;
   demo seeders `10`+ replace demo records) and `tools/seed.php` always runs them in demo mode.
5. Run the smoke checklist (§10).
