# GIMT SmartCampus — University ERP, Website CMS and Portals

**Global Institute of Management & Technology (GIMT / "Global IMT")** — one PHP + MySQL application that runs:

- the **public website** (server-rendered PHP for SEO and fast first paint, enhanced with React/TypeScript islands;
  Light / Dark / System theme; campaign landing pages; notice board; policy pages; AI admission assistant);
- the **Website CMS** (pages and page builder, menus, home page sections, banners, blog, media, gallery, FAQs,
  testimonials, announcements, SEO, redirects);
- the **admin ERP** at `/admin/` (admissions, students, faculty and staff, academics, timetable, attendance,
  examinations, results, certificates, fees, expenses, library, hostel, transport, placement, alumni, communication,
  reports, users and roles, settings, backups, security);
- the **Student and Faculty portals** at `/portal/`.

## Technology

| Layer | Technology |
|---|---|
| API / backend | Core PHP 8.2 (no framework) — sessions, CSRF, role-based permissions, PDO prepared statements (`app/`, `api/`) |
| Database | MySQL 8 / MariaDB 10.6+ (`database/schema/*.sql`, seeders in `database/seed/`) |
| Admin panel | React 18 + TypeScript + Tailwind CSS SPA (`frontend/src` → `admin/build`) |
| Public website | PHP pages + React islands + Tailwind / CSS3 (`*.php`, `includes/`, `frontend/site` → `assets/site`, `assets/css`) |
| Portals | React 18 + TypeScript + Tailwind SPA (`frontend/portal` → `portal/build`) |
| Print documents | A4 HTML print views with QR verification (`admin/print/`) |

The compiled bundles (`admin/build`, `portal/build`, `assets/site`, `assets/css`) are committed, so a server needs no
Node.js.

## Repository layout

```
/                    public website pages (index.php, about.php, programs.php, program.php, admissions.php,
                     admissions-open.php, apply.php, academics.php, campus.php, placement.php, alumni.php, faculty.php,
                     contact.php, blog.php, post.php, events.php, event.php, notices.php, notice.php, gallery.php,
                     faq.php, search.php, page.php, verify-certificate.php, sitemap.php, robots.php, 404.php, 500.php)
├── admin/           admin SPA shell (index.php), classic entry points (students.php …), build/, print/
├── portal/          Student & Faculty portal shell (index.php), build/
├── api/             JSON API router (index.php) + routes/*.php
├── app/             PHP core (init, db, auth, crud engine, lookups, uploads, mailer, xlsx, components),
│   ├── modules/     CRUD module definitions
│   └── services/    domain logic
├── includes/        website bootstrap and helpers (config.php, components.php, header/navbar/footer, partials/)
├── assets/          css/, js/, site/ (website islands build), images/, uploads/ (user uploads, no script execution)
├── config/          config.php (defaults + GIMT_* environment variables), local.example.php
├── database/        schema/*.sql, seed/*.php
├── frontend/        React + TypeScript sources: src/ (admin), site/ (website islands), portal/ (portals)
├── storage/         backups, cache, logs, private documents, tmp (denied to web visitors)
├── tools/           install.php, seed.php, api.php, router.php, build-css.sh, screenshot.cjs
└── docs/            documentation (below)
```

## Quick start (local development)

Requirements: PHP 8.2 with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`, `gd`, `zip`, `zlib` and the XML
extensions; MySQL 8 / MariaDB 10.6+ (MariaDB 10.4 from XAMPP works for development).

```bash
# 1. Database (empty)
mysql -uroot -e "CREATE DATABASE gimt_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. Configuration: copy, then set the database credentials and for local work
#    'env' => 'dev', 'url' => 'http://127.0.0.1:8000', 'force_https' => false, and a random 'key'
cp config/local.example.php config/local.php

# 3. Schema + core data + demo data   (--fresh would DROP every existing table first)
php tools/install.php --demo

# 4. Run (PHP built-in server, development only)
php -S 127.0.0.1:8000 tools/router.php
# Website http://127.0.0.1:8000/   Admin http://127.0.0.1:8000/admin/   Portals http://127.0.0.1:8000/portal/
```

Rebuild front-end assets only after changing their sources (Node 18+):

```bash
cd frontend && npm ci
npm run build            # admin   -> admin/build
npm run build:site       # website islands -> assets/site
npm run build:portal     # portals -> portal/build
cd .. && bash tools/build-css.sh   # website + print CSS -> assets/css/style.css, print.css
```

Dev servers with hot reload: `npm run dev` (admin, :5173), `npm run dev:site` (website islands, :5174 — set
`app.site_dev_server` in `config/local.php`), `npm run dev:portal` (portals, :5175). Type-checks: `npm run typecheck`,
`npm run typecheck:site`, `npm run typecheck:portal`. API from the command line: `php tools/api.php GET "crud/students?per_page=5"`.
One seeder: `php tools/seed.php 22_fees` (never drops tables).

Production installation, environment variables, cron jobs, HTTPS, backups and go-live steps: **`docs/DEPLOYMENT.md`**.

## Demo logins (demo data only)

| Area | Login | Password |
|---|---|---|
| Admin `/admin/` (Super Admin) | `admin` / `admin@gimt.ac.in` | `Admin@12345` |
| Admin role users | `administrator`, `admissions`, `academics`, `accounts`, `exams`, `library`, `hostel`, `transport`, `placement`, `alumni`, `content`, `staff` (or `<name>@gimt.ac.in`) | `Demo@12345` |
| Faculty Portal | `faculty@gimt.ac.in`, `nandini.gupta5@gimt.ac.in` … | `Demo@12345` |
| Student Portal | `GIMT25BBA0002`, `GIMT23BTAIDS0001` … | `Demo@12345` |

**Change or remove these before any real use.** Production installs should not load demo data (`docs/DEPLOYMENT.md` §2.7).

## Documentation

| Document | Contents |
|---|---|
| [`docs/REPORT.md`](docs/REPORT.md) | Completion report: features, QA, known limitations, what GIMT must provide, demo logins |
| [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md) | Linux / Apache and Windows / XAMPP installation, configuration, cron, HTTPS, backups, go-live, troubleshooting |
| [`docs/PORTAL.md`](docs/PORTAL.md) | Student and Faculty portals |
| [`docs/CMS.md`](docs/CMS.md) | Website CMS content contract |
| [`docs/WEBSITE.md`](docs/WEBSITE.md) | Website platform reference (design system, islands, motion, theme, popups, CSP) |
| [`docs/HOMEPAGE.md`](docs/HOMEPAGE.md) | Homepage sections and data sources |
| [`docs/CHATBOT.md`](docs/CHATBOT.md) | AI admission assistant |
| [`docs/DESIGN.md`](docs/DESIGN.md) | Visual direction |
| [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md) | Client brief and updates, handover status |
| [`CLAUDE.md`](CLAUDE.md) | Developer conventions for this codebase |

## Security (summary)

PDO prepared statements everywhere · password hashing, policy, expiry and forced change · CSRF tokens on every
state-changing request · session regeneration, idle timeout, sign-out of other devices on password change · login
throttling, lockout and IP limits · role and module permissions (view / create / edit / delete / export / import /
approve / publish / manage) checked on every route · output escaping and allow-list HTML sanitising · upload
validation (extension, MIME sniffing, size, image re-encoding, random names, no script execution) · private documents
streamed only through permission-checked endpoints · security headers, nonce CSP on admin and portals · secrets never
sent to the browser · activity log and security checklist (Admin › Security). Details: `docs/REPORT.md` §3.15.
