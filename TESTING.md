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
