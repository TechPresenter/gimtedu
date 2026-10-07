# GIMT SmartCampus — University ERP + Website CMS

**Global Institute of Management & Technology (Global IMT)** — a production-oriented campus ERP (admissions, students,
faculty, academics, attendance, fees, examinations, results, certificates, library, hostel, transport, placement,
alumni, communication, reports) and a CMS-driven public website.

## Architecture

| Layer | Technology |
|-------|-----------|
| Admin panel (`/admin`) | React 18 + TypeScript + Tailwind CSS (Vite build committed in `admin/build`, no Node needed in production) |
| API (`/api`) | Core PHP 8.1+ JSON API — sessions, CSRF, RBAC, PDO prepared statements |
| Database | MySQL 8 / MariaDB 10.6+ (`database/schema/*.sql`) |
| Public website | Server-rendered PHP + Tailwind CSS (SEO friendly) |

```
/                      public website pages (index.php, about.php, programs.php, admissions.php, academics.php,
                       campus.php, placement.php, alumni.php, faculty.php, contact.php, blog.php, faq.php ...)
├── assets/            css/style.css (compiled), js/main.js, images/, fonts/, uploads/ (user uploads, no script execution)
├── includes/          config.php (bootstrap + helpers), header.php, navbar.php, footer.php
├── admin/             index.php (SPA shell) + students.php, faculty.php, admissions.php ... entry points, build/ (compiled SPA), print/
├── api/               index.php (router) + routes/*.php
├── app/               PHP core: init, db, auth (sessions/RBAC), crud engine, lookups, uploads, mailer, xlsx, components
│   └── modules/       CRUD module definitions (fields, columns, filters, permissions)
├── database/          schema/*.sql (12 files, ~115 tables) + seed/*.php (core + demo data)
├── frontend/          React + TypeScript source of the admin panel
├── storage/           logs, backups, private documents (web access denied)
└── tools/             install.php (CLI installer), router.php (dev server), build-css.sh, screenshot.cjs
```

## Quick start (development)

```bash
# 1. Database
mysql -uroot -e "CREATE DATABASE gimt_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
cp config/local.example.php config/local.php        # set DB credentials
php tools/install.php --fresh --demo                  # schema + demo data

# 2. Run
php -S 127.0.0.1:8000 tools/router.php
# Website: http://127.0.0.1:8000/      Admin: http://127.0.0.1:8000/admin/

# 3. (optional) Rebuild assets after changing source
./tools/build-css.sh                                   # website CSS -> assets/css/style.css
cd frontend && npm install && npm run build            # admin SPA  -> admin/build
```

Demo login: `admin` / `Admin@12345` (Super Admin). Role demo users (`admissions@gimt.ac.in`, `accounts@gimt.ac.in`,
`exams@gimt.ac.in`, `faculty@gimt.ac.in` …) use `Demo@12345`. **Change these immediately on a real installation.**

Apache: the bundled `.htaccess` files provide pretty URLs, SPA routing and folder protection (`mod_rewrite` required).

## Security

PDO prepared statements everywhere · bcrypt/argon password hashing · CSRF tokens on every state-changing request ·
session regeneration, idle timeout and UA binding · login throttling, account lockout, IP blocking · remember-me with
rotating selector/validator tokens · role & module-level permissions (view/create/edit/delete/export/import/approve/
publish/manage) · output escaping + HTML sanitising · upload validation (extension, MIME sniffing, size, image re-encode,
random names, no script execution) · private documents served only through authenticated endpoints · audit trail.

## Status

Foundation complete: database schema + seeds, PHP API core (auth, RBAC, generic CRUD engine with server-side
pagination/search/filters/sort, CSV/Excel/PDF export, CSV/Excel import), admin shell (sidebar, top bar, global search,
notifications, academic session switcher, dark mode, session-timeout warning), reusable UI kit, login/forgot/reset
password, public website layout (header, mega menu, footer). Module screens are being built on top of this foundation.
