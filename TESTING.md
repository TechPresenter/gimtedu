# v3 final release — one ZIP + one SQL (test results)

**Date:** 16 October 2026 · **Package tested:** `prerit-website.zip` + `prerit-database.sql` built 15:21 UTC (sha256 in the release folder's `SHA256SUMS.txt`; a rebuild creates new `INSTALL_KEY`/`APP_KEY` values and therefore a different zip). **Environment:** Apache 2.4 with `.htaccess`, PHP 8.3, MariaDB 10.11 with Hostinger's connection collation emulated (`collation_connection = utf8mb4_unicode_ci` for non-root users); every import and every site ran as a non-root database user. Not available here: Hostinger File Manager / phpMyAdmin, LiteSpeed, a MySQL 8 server, a PHP 7.4 interpreter.

**Fixed in this round:** "Our offices" block (migration 875) was skipped when migrations 700 and 875 ran in the same request (stale settings cache) → the runner now reloads settings before each migration, and new migration 879 adds the block once where it is missing; archive files (`.zip`, `.tar`, `.gz` …) in `public_html` are no longer downloadable; a missing file under `/.well-known/` on http is no longer redirected to `/index.php`; Admin → SEO → Sitemaps no longer links empty sitemap files; clearer Security Centre text for `pwf-config.php`; `config.php` header now says not to edit it; owner guides (README, DEPLOY-HINDI) corrected.

| Test | Result |
|---|---|
| ZIP layout | ✅ 349 files directly at the root (no wrapper folder, no nested zip, no `.git`/`.md`/`.sql`); list = repository working tree + generated `pwf-config.php` (32-character `INSTALL_KEY`, 48-character `APP_KEY`) |
| SQL import (new empty database, non-root user) | ✅ exit 0, no warnings; 71 tables, 58 migrations recorded, 0 users; all 71 tables identical to the build database; Hindi text intact. phpMyAdmin-style import (464 statements one by one): 0 errors, 0 warnings, largest statement 65,494 bytes |
| SQL file content | ✅ no `DROP`, `DELIMITER`, `DEFINER`, `SET GLOBAL`, triggers, procedures or views; only `utf8mb4_unicode_ci`; the only email address is info@preritwelfarefoundation.org |
| **Safety:** SQL imported into a database that already has the site | ✅ stops at line 30 "Table 'website_settings' already exists"; installed database (71 tables) and live-like v2 database (51 tables): every table checksum unchanged |
| **Fresh install** (owner's 4 steps; `BASE_URL` = test address) | ✅ before the config edit: "We will be back shortly" (503); `install.php` 14/14 checks green, wrong key and a key with one changed character refused, weak password refused, correct key → "Installation complete", `install.lock`; after deleting `install.php`: site 404 page. First requests ran no migration (58, version `879:58:e1590650b9:r2`) |
| Fresh install — admin | ✅ Dashboard without red notice; Security Centre: Database up to date, Encryption key (APP_KEY), Installer removed: OK. "Our offices" shown on /contact/ and /ngo-details/ |
| Fresh install — crawls | ✅ public 378 URLs, 0 issues · admin 2,469 URLs, 0 issues (no 5xx, no PHP errors). Error log: only the 3 lines caused by deliberate probes (empty folder, database before the config edit, `install.lock`) |
| Fresh install — sitemaps, browser | ✅ `sitemap.xml` index with 5 files, 114 URLs, all on `BASE_URL`, all 200; robots.txt points to it. Programmes, events, donate, volunteer-teacher terms pop-up, help panel: no CSP violations, broken images or horizontal overflow |
| Fresh install — protection | ✅ 18 private paths (config files, includes, templates, uploads/documents, `.git`, `.env`, `.sql`, `.md`, `install.lock`) → 403 with no content; a `.zip` left in `public_html` → 403 |
| **Live upgrade A** (live-like v2 data, 3 donations, owner edits) → original Upgrade 1 | ✅ reproduces the live error exactly: `migration_100_v2_final_catchup … 1267 Illegal mix of collations (utf8mb4_unicode_ci,COERCIBLE) and (utf8mb4_general_ci,COERCIBLE)` |
| A → final ZIP per Path 2 (extract, delete `install.php`, `pwf-config.php` and the zip, no SQL, open the home page) | ✅ 200; 58 migrations, names identical to the SQL package; failure cleared; 0 error-log lines; existing administrator signs in; Dashboard no red notice; Security Centre "Database up to date" (47/47 checks); donor receipt by link, member area and admin donation pages work |
| A — data | ✅ 41 tables unchanged over their v2 columns (users, user roles, donation receipts, all application tables, members, newsletter, enquiries, FAQs, campaigns, events, team …). Intended changes only: donations — 1 cell (an offline donation's gateway razorpay→offline; amounts, status and receipts untouched); 91 new settings, 0 existing values changed; 13 new pages and 84 search titles/descriptions filled only where still the original text (admin-edited contact description kept); 122 new sections (incl. "Our offices" on 2 pages); 8 menu items; `/events-page/` → `/events/` links and redirects; 6 permissions; 20 new tables. Uploaded files and `../pwf-config.php` byte-identical |
| A — crawls | ✅ public 378 URLs, 0 issues · admin 2,539 URLs, no 5xx, no PHP/SQL errors; one 404: `/donation-receipt/` without a reference, listed in Website Analytics after the test opened a receipt (intentional, as in Upgrade 1) |
| B: Upgrade 1.1 + hotfix first, then final ZIP | ✅ 32 migrations (100–701), then 26 (634, 800–879); same 58 names; "Our offices" on 2 pages, no duplicates; admin checks pass |
| C: `install.php`, `public_html/pwf-config.php` and the zip left on the server | ✅ site runs from `../pwf-config.php`; 15 attempts to read config files → 403/404, nothing leaked; `install.php` → 403 "already installed", POSTs create nothing (29 checks); zip → 403; Security Centre recommends deleting both files |
| E: 10 simultaneous first requests after extraction | ✅ 9 × 200 + 1 × 301, no 503, no errors; migrations ran once (58 distinct, one "database_upgraded"); same row counts as A |
| F: https / www redirects | ✅ http apex and www (also `:80`, upper case) → one 301 to `https://preritwelfarefoundation.org` with path and query; `X-Forwarded-Proto: https` → 200, no loop; other hosts never redirected; `/.well-known/acme-challenge/` file 200 on every host, missing file 404. Real TLS (temporary second Apache) was tested on the previous build of the same rules, not repeated |
| Syntax | ✅ `php -l` 164 files, `node --check` 19 files: 0 errors; PHP 7.4 token scan of the files changed in this round: 0 issues |
| Not run | Real MySQL 8 import (DDL read only), Hostinger File Manager / phpMyAdmin, LiteSpeed, PHP 7.4 runtime, real payment / AI providers |

---

# Test report — Prerit Welfare Foundation website v3 (Upgrade 1)

**Date:** 16 October 2026 · **Stack tested:** Apache 2.4, PHP 8.3 (code checked for PHP 7.4 syntax by a token scanner — no 7.4 interpreter was available), MariaDB, Chromium (Playwright), local mocks of the Razorpay, Cashfree, OpenAI and geo-IP APIs. **Real Razorpay/Cashfree/OpenAI endpoints could not be reached from the test environment** — do one small real payment, one sandbox Cashfree payment + refund and one chatbot question after deploying.

## 1. Upgrade of a live-like site (the owner's procedure)

| Check | Result |
|---|---|
| v2 production copy with live-like data (₹500 verified donation + receipt, pending and offline donations, member, volunteer + teacher applications, event registration, newsletter, enquiry) and owner edits (section text, meta description, hero title, phone, FAQ) | prepared |
| New files copied over the old ones (no delete, no `install.php`, no uploads), `pwf-config.php` unchanged (no `APP_KEY`) | ✅ first request ran all migrations in ≈1 s; applied list = code's migration list |
| User data | ✅ 40/51 v2 tables byte-identical; the other 11 changed only by intended migrations; donation, receipt, sessions, owner edits preserved |
| 10 parallel first requests | ✅ one upgrade (database lock), no duplicate rows; a request that cannot get the lock in 10 s gets a 503 "try again" page instead of a half-upgraded site |
| Site installed from the earlier v2 package | ✅ converges to the final v2 state (catch-up migration) |
| Failing migration | ✅ retried at most every 10 minutes, one log line per try, shown to administrators (Dashboard, Security Centre) |
| Fresh install (database.sql + installer over HTTP) | ✅ wrong key refused, admin created, migrations ran; public crawl 373 URLs / admin 2,116 URLs, 0 issues |
| Crawl of the upgraded copy | ✅ public 373 URLs, 0 issues · admin 2,195 URLs, 1 intentional 404 (receipt page without reference) |

## 2. New modules — independent adversarial QA (issues found → fixed)

| Module | Builder tests | QA findings fixed |
|---|---|---|
| Payment gateways (Razorpay admin keys, Cashfree order/verify/webhook/refund) | all pass against mocks; Razorpay regression with constants and admin keys | 7 (incl. a Cashfree order-renewal race, 2-letter names, filter crash) |
| AI assistant (OpenAI) | all pass against a mock incl. XSS sanitising, CSRF, limits, fallback | 11 |
| Deep analytics | traffic simulation, forged/replayed beacons, consent, DNT, staff exclusion, exports | 9 |
| Mobile menu strip + hero images | layout checks 390–1920 px, CLS, uploads | 9 |
| Programs & Events | 74/74 public + 114/114 admin checks; 20 parallel registrations for 5 seats → exactly 5 | 10 |
| Authorization letters + teacher terms | print layout on A4, masking, permissions, verify page | 8 (incl. full Aadhaar entered in free text) |
| Release check (upgrade path, regression, review of integration code) | 33 findings | 28 fixed, 1 not a bug, 1 owner decision (minimum PHP version stays 8.0 for the installer) |

## 3. Final regression

* `php -l` on every PHP file, `node --check` on every JS file, PHP 7.4 token scan of changed files: 0 issues.
* Crawls of the development site: public 378 URLs, 0 issues; admin 2,670 URLs (only 404s for deleted test events still referenced by analytics rows).
* axe-core (accessibility) on 13 public and 9 admin pages at 390 px and 1366 px: 0 serious/critical issues.
* Header/top bar at 1280–1920 px (signed in and out): one row, no overlap; mobile strip and menu: no horizontal overflow, no console errors.

## 4. To check on the live site after deploying

1. Open the home page once, then Admin → Audit Log shows "database_upgraded"; Dashboard shows "Database up to date".
2. Razorpay: regenerate the Key Secret, enter keys in Payment Gateways, **Test connection**, then one small real donation → receipt email → refund test if wanted.
3. Cashfree (optional): sandbox keys → one sandbox payment and refund → then production.
4. AI assistant (optional): add the OpenAI key + budget limit, ask a few questions in English and Hindi.
5. Upload the seal (Authorization Letters → Settings), print one test letter and compare it with the original letterhead.

---

# Earlier test report — website v2

**Date:** 15 October 2026 · **Stack tested:** Apache 2.4 (mod_rewrite, .htaccess) · PHP 8.3 · MariaDB · Chromium (Playwright) · local Razorpay API mock (test mode)

Two environments were used:

| Environment | Purpose |
|---|---|
| Development site (`APP_ENV=development`) | Module development and automated suites, Razorpay mock for payments |
| Fresh production-mode install (`APP_ENV=production`, no payment keys) | Exactly what a new Hostinger install looks like: `database.sql` imported into an empty database, files copied, `install.php` run |

---

## 1. Fresh installation (final build)

| Check | Result |
|---|---|
| Import `database.sql` into an empty database | ✅ 51 tables, 70 pages, 435 content sections, 11 programmes, 5 draft campaigns, 30 FAQs, 77 awareness days; **0 users** (no default admin) |
| `install.php` with wrong / right INSTALL_KEY | ✅ wrong key refused (rate limited); right key creates the first administrator; installer then answers **403** and reveals no server details |
| Re-import over an existing database | ✅ `database.sql` drops and recreates all tables cleanly |
| All section types used by the content exist | ✅ 43 section types, all with editor schemas |
| Crawl of every public page (sitemap + links) | ✅ 270 URLs, **0 issues** (status, PHP errors, `#` links, title, meta description, canonical, single `<h1>`, valid JSON-LD, image `alt`, CSRF on forms, input labels) |
| Crawl of the admin panel (signed in) | ✅ 500–600 URLs, **0 issues** |
| No payment keys | ✅ donate page honestly offers bank/UPI details (when set) or contact; no fake pay button; no amount chips elsewhere |

## 2. Module test suites (written by each module's builder)

| Area | Suite | Result |
|---|---|---|
| Site management (pages, sections, menus, settings, widgets, redirects, media, SEO audit) | Playwright e2e (site, pages, no-JS, logo, grievance settings) | ✅ 59/59 and 35/35 (+ no-JS editing) |
| Programmes & schemes | HTTP + Playwright checks | ✅ all checks; scheme publish blocked without official https source + check date |
| Fundraising & payments | e2e (97 checks) + edge cases (15) against the Razorpay mock | ✅ all, after updating the suite for the new donor declaration and four-eyes expense approval |
| Recruitment & support | public forms (47) + admin workflow (74) | ✅ 47/47, 74/74 |
| Accounts & member dashboard | registration, login (email / mobile), lockout, remember-me rotation & theft detection, reset/verify tokens, 12-page dashboard data isolation | ✅ all |
| Media & events | blog drafts/scheduling, consent rules, event registration & capacity, `.ics`, calendar, gallery, video facade, JSON-LD | ✅ all |
| Home & insights | dashboard/analytics/reports/exports with temporary data, backup → re-import round trip, retention clean-up | ✅ all (22 CSV exports; backup re-import byte-identical) |
| Information & policies | contact/grievance/fraud/feedback/newsletter forms, admin CRUD, consent enforcement, newsletter batches | ✅ ~115 checks |

## 3. Independent verification round (9 reviewers + adversarial confirmation)

Nine independent reviewers examined the integrated site. Every finding was re-checked by separate skeptic agents (three for high/critical) before any change was made.

| Dimension | Findings confirmed | Notes |
|---|---|---|
| Security – access control | 6 (0 critical/high) | e.g. staff-account takeover by lower-privileged staff, unverified-email linking, staff password length |
| Security – injection, XSS, CSRF, uploads | 4 (0 critical/high) | open redirect via encoded characters, PATH_INFO edge case, GET unsubscribe, installer info disclosure. **No SQL injection, stored/reflected XSS or CSRF gaps found** across ~1,350 crawled pages and all forms |
| Payments & financial integrity | 9 | partial refunds, four-eyes expense approval, offline references, 80G cash limit wording, fallback copy |
| Content authenticity & compliance | 18 | FCRA donor declaration, 80G/Form 10BE wording, age/guardian consent, cookie consent withdrawal, retention promises, wording consistency |
| User journeys | 3 | notification messages, setup checklist with mail disabled |
| Visual QA – public | 19 | mobile drawer submenus, header spacing, contrast on hero, CTA duplication, orphan grid items |
| Visual QA – admin | 19 | flash visibility, mobile sidebar, charts, media cards, filters |
| SEO & performance | 12 | 1200×630 JPEG share image (was SVG), image thumbnails/srcset (640/1080 px), logo 115 KB → 21 KB, sitemap rules, structured address |
| Accessibility (WCAG 2.2 AA) | 16 | keyboard focus in mobile drawer, Escape for menus, accessible names, `lang="hi"`, error messages tied to fields, contrast, focus return, target sizes |

**106 confirmed findings → 103 fixed, 3 partly fixed** (logo kept at 320 px but compressed; video thumbnails still load from YouTube's image server — wording updated; application-status reference list styled via CSS only).

Final regression after the fixes: PHP lint (132 files) and JS syntax clean; crawls clean on both sites; module suites pass; screenshots at 390 px and 1366 px show no horizontal overflow and no console errors.

Additional fixes after the regression: guardian consent on *Request help* is required only when the person is under 18; accounts with two-step login get **no** member or admin access and no remember-me cookie until the 6-digit code is entered; Hindi text in CMS sections is tagged `lang="hi"`; grievance settings have defaults.

## 4. Security summary

* PDO prepared statements everywhere; whitelisted identifiers only.
* Output escaping + HTML sanitiser for rich text; strict Content-Security-Policy without inline scripts; security headers on every response.
* CSRF tokens on every POST; no state change on GET (logout, unsubscribe and deletes are POST).
* Passwords hashed; members 8+ characters, staff 12+; remember-me tokens hashed, rotated, revoked on theft detection; sessions invalidated on password/email change.
* Two-step login (TOTP, verified against RFC 6238 test vectors) for staff; inactivity timeout; password sign-in required for admin.
* Server-side permissions on all 57 admin pages and actions, tested with every staff role, members and anonymous users; members see only their own data.
* Uploads: type/size/content checks, image re-encoding, random names, no script execution; private documents blocked over HTTP and served only after permission checks.
* Payments: amounts fixed on the server, HMAC signature + API verification, signed webhooks with replay protection, idempotent confirmation under concurrency, refunds reflected in all totals.
* Rate limits on sign-in, registration, password reset, forms and look-ups; honeypots; generic messages against account enumeration.
* First-party analytics without IP storage; Google Analytics only after consent (withdrawable).

## 5. Things that can only be tested on the live site

1. **Live Razorpay** — after merchant approval: one small real payment in test mode then live mode, webhook delivery (`/payment-webhook.php`), refund.
2. **Email delivery** — set SMTP in `pwf-config.php`, use Admin → Settings → Email → *Send test email*; check spam folders.
3. **HTTPS** — enable SSL, uncomment the Force-HTTPS lines in `.htaccess`, check Admin → Security Centre (all green).
4. **Real content** — logo, registration details, team, photos (with consent), documents, campaign details; then publish the draft campaigns.

## 6. Known limitations

* Newsletter mailings are sent in batches of 40 per click (shared hosting friendly), not by a background job.
* Data-retention clean-up runs automatically once a day when a staff member opens the admin dashboard, and on demand from Admin → Backup & Data Retention (there is no server cron job).
* The awareness calendar contains observances until December 2027; add later years in Admin → Events.
* The welfare-schemes directory is intentionally empty until staff add schemes verified against official sources.
* Uploaded files are not included in the in-app database backup; download `uploads/` with the hosting File Manager.
