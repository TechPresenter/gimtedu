# GIMT SmartCampus — Completion Report (Handover)

**Global Institute of Management & Technology (GIMT)** · ERP + Website CMS + Student / Faculty Portals  
**Date:** 18 Oct 2026 · **Academic session:** 2026–27 · **Branch:** `main` (this report), dev branch `claude/upbeat-archimedes-xgwxtz`

Related: [`REQUIREMENTS.md`](REQUIREMENTS.md) (brief + handover status), [`DEPLOYMENT.md`](DEPLOYMENT.md) (install / go-live), [`PORTAL.md`](PORTAL.md), [`CHATBOT.md`](CHATBOT.md), [`CMS.md`](CMS.md), [`WEBSITE.md`](WEBSITE.md), [`HOMEPAGE.md`](HOMEPAGE.md).

---

## 1. What was delivered

One PHP + MySQL application with one database that runs the public website, the Website CMS, the admin ERP and the two portals. All features work against the real backend — no static mockups, no dead buttons.

| Area | What exists |
|---|---|
| **Admin ERP** | Dashboard (KPIs, charts, tables, quick actions) · Admissions (pipeline, follow-ups, documents, offer letter) · Students & Faculty/Staff (profiles, tabs, ID cards) · Academics (sessions, departments, programs, courses, subjects, sections, credits, faculty assignments) · Timetable (conflict checks, print) · Attendance (student + faculty, reports, low-attendance alerts) · Fees & Accounts (heads, structures, collection, discounts, scholarships, receipts, invoices, refunds — cash/bank/UPI) · Examination / Results / Marksheet / Certificates (QR verification) · Library · Hostel · Transport · Placement (drives, offers, recruiters) · Alumni · Notices & Communication (audience, channels) · Events · Newsletter/Email · Enquiries · Contact Messages · Feedback & Complaints · Blog/News · Gallery/Media · Testimonials · FAQs · Announcements · Reports & Analytics · Users & Roles & Permissions · Activity Logs · Settings · Backup · Security checklist |
| **Website CMS** | Pages + page builder (14 block types), menus (header / footer / legal / mobile), home sections, banners (home_hero / page_header / popup / strip), announcements (topbar / ticker / popup / banner), blog, media library, gallery, FAQs, testimonials, sitemap + robots + redirects + SEO audit, page-view modes |
| **Public website** | Design v3 (navy `#0B2A5B` + orange-red `#F05A28`/`#D03E16` + warm grey, Plus Jakarta Sans / Inter), PHP SSR + React islands + CSS3 motion, Light / Dark / System theme, Grid / List / Compact / Detailed views, campaign landing pages (`/admissions-open`), enquiry + application forms with international phone / E.164 / CAPTCHA / honeypot / `_ft` token / rate limits, consent-aware GA4/GTM/Pixel (Consent Mode v2) |
| **Portals** | Student portal (dashboard, timetable, attendance, exams/admit card, results/marksheet, fees/receipts, certificates, library, hostel, transport, placements, grievances, notices, documents, profile, password) · Faculty portal (timetable, classes, attendance marking, marks entry, leave, documents, payroll reference, notices) · `/admin/portal-access` for admins |
| **Chatbot** | "GIMT Admission Assistant" — rule-based + Anthropic / OpenAI / Gemini, knowledge base from live ERP/CMS data, lead capture into Enquiries, streaming, rate limits |
| **Print** | A4 print views with letterhead + QR verification (receipt, invoice, marksheet, certificate, admit card, timetable, ID card) — `assets/css/print.css` |
| **Infra** | Core PHP API, PDO prepared statements, CSRF, RBAC, activity log, notifications, backups, `GIMT_*` env overrides, pretty URLs, 404/500/410, visitor counter |

---

## 2. Fresh build — 18 Oct 2026

| Output | Built from | Command |
|---|---|---|
| `admin/build/` | `frontend/src` | `cd frontend && npx vite build` |
| `assets/site/` | `frontend/site` | `cd frontend && npx vite build -c vite.site.config.ts` |
| `portal/build/` | `frontend/portal` | `cd frontend && npx vite build -c vite.portal.config.ts` |
| `assets/css/style.css` + `print.css` | `assets/css/src/style.css` | `bash tools/build-css.sh` |

Reason: compiled bundles were dated **13 Oct** while source changed until **18 Oct**. The server now requires a one-time form token `_ft` (`GET /api/public/form-token`) on every public POST; the stale bundles never sent it, so enquiry/contact/newsletter/event/apply submits returned **419 "This form has expired"** and no row was saved. The 18 Oct rebuild embeds the token flow (`frontend/site/forms/security.ts` + `EnquiryForm` / `ApplyWizard` / vanilla `data-ajax-form`s via `site_secure_form_*`).

Type-check: `npm run typecheck` / `typecheck:site` / `typecheck:portal` — clean. `php -l` on changed PHP files — clean. Zero console errors on sampled pages.

---

## 3. Fixes verified in this handover

| Fix | How verified |
|---|---|
| `?param[]=x` / array query params no longer throw 500 (scalar guard in `includes/config.php`) | 8 public pages with `?q[]=x` → 200 |
| Consent is checked server-side, not only in the browser | POST without `consent=1` → 422 |
| Homepage `view-transition` `InvalidStateError` gone | no console error on navigation |
| Theme — Appearance settings visible in Admin › System Settings › Website | settings page renders |
| Chatbot: phone country saved correctly, FAQ-sourced enquiries get `source=FAQ` | DB rows checked |
| Header menu no longer wraps on any desktop width (≥1280 px compact rules) | `tools/.cache/shots/website/header.cjs` |
| Global search shows company logos (`is_recruiter` wordmark/logo) | search API returns `logo` |
| Portal: expired password shows the correct message | portal login flow |
| `php -l` + `tsc -b --noEmit` (admin, site, portal) clean | CI |

Small polish items remain in the tracker (e.g. `/notices` font-load layout shift, a few contrast tweaks) — not verified here.

---

## 4. Repository state at handover

- **Before this push:** ~326 modified, ~130 new, ~97 deleted `portal/build` old chunks (hashed names) — all only on this laptop, no GitHub backup.
- **This handover commit:** all of the above + the 18 Oct rebuilds + this `REPORT.md` — pushed to `origin/main` (and `origin/claude/upbeat-archimedes-xgwxtz`).
- **Production ZIP + DB dump** (no demo data): see §8.

---

## 5. Not in this release (as agreed)

| Item | Current state |
|---|---|
| **Two-factor authentication** | Setting exists, UI shows "Coming soon" |
| **Online fee payment from the portals** | Portals show bank/UPI details + receipts; no gateway checkout |
| **Enforcing CSP on the public website** | Report-Only header (`app.csp_report_only`); admin + portals enforce a nonce CSP |

See `REQUIREMENTS.md` §Status at handover and `WEBSITE.md` §6 (CSP).

---

## 6. What GIMT must provide before go-live

### 6.1 Content (site shows placeholders until filled)

| Item | Where it appears | How to fill |
|---|---|---|
| **Leadership — Chairman, Director, Dean** names, photos, bios | `/about` + `/leadership` (6 "(placeholder)" cards) | CMS › Pages › `leadership` (person cards) |
| **16 policy pages — [To be confirmed by GIMT: …]** | `/policies` hub + each policy page (highlighted notes) | CMS › Pages — replace every highlighted note with the verified text: Privacy Policy, Terms & Conditions, Disclaimer, Cookie Policy, Refund & Cancellation, Admission Policy, Scholarship Policy, Fee Policy, Examination Policy, Code of Conduct, Anti-Ragging, Grievance Redressal, Internal Complaints (POSH), Equal Opportunity, Accessibility, Mandatory Disclosure |
| **Affiliation / approvals — wording + address** | Website header/footer, CMS `affiliation` / `approvals_list`, chatbot answers, prints | System Settings › General / Website › Additional settings |
| **Real recruiter list** | `/placement`, homepage marquee, landing pages | Placement › Companies (`is_recruiter=1`, logo `assets/images/recruiters/`) |
| **Enquiry popup photo** | Homepage enquiry popup (left panel) | System Settings › Website › Enquiry popup — upload a high-res transparent PNG (~600×700 px) |
| **1200×630 share images** | OG/Twitter cards for key pages | CMS › SEO for each route/page (`og_image`); also `site_header(['image' => …])` |

### 6.2 Accounts & keys (all empty in this build)

| Item | Where to set (admin or env) | Without it |
|---|---|---|
| **Domain + hosting + TLS** | `config/local.php` `app.url` / `GIMT_URL`, `force_https=true` | canonical/sitemap/QR/email links wrong, no HTTPS |
| **SMTP + SPF / DKIM / DMARC** | System Settings › Email & SMS | password resets, portal credentials, receipts, newsletters not delivered |
| **CAPTCHA keys** | System Settings › Integrations or `GIMT_CAPTCHA_*` | forms fall back to honeypot + rate limits only |
| **Bank & UPI details** | System Settings › Payment | portals hide the details |
| **SMS / WhatsApp gateway API** | System Settings › Email & SMS | no SMS/WhatsApp delivery |
| **AI chatbot key** (Anthropic / OpenAI / Gemini — any one) | AI Chatbot › Settings or `GIMT_*_API_KEY` | rule-based answers only |
| **GA4 / GTM / Search Console verification** | System Settings › Integrations or `GIMT_GA4_ID` / `GIMT_GTM_ID` / `GIMT_GSC_VERIFICATION` | no analytics / no Search Console |
| **Google Maps key** (optional) | `GIMT_GOOGLE_MAPS_API_KEY` | maps embed disabled |
| **Sentry DSN** (optional) | `GIMT_SENTRY_DSN` | no error monitoring |

Full variable list: `DEPLOYMENT.md` §2.6.

---

## 7. Production setup (checklist)

Run on a **new, empty** database — never on the live one with `--fresh` later.

1. **Database (no demo data):** `php tools/install.php --admin-email=it@gimt.ac.in --admin-password='…strong…' --admin-name="IT Administrator"` — no `--demo` (demo = ~1,290 students, faculty, fees, attendance, etc. — staging only). Existing local DB keeps demo data for testing.
2. **Config:** `cp config/local.example.php config/local.php` → set `db.*`, `app.env = production`, `app.url = https://www.gimt.ac.in` (no trailing slash), `app.force_https = true`, `app.key =` 64-char random (`php -r "echo bin2hex(random_bytes(32));"` — never change it afterwards; QR codes + encrypted AI keys depend on it).
3. **Remove demo passwords:** change the Super Admin password (Admin › Profile) and delete/disable demo users (`Demo@12345`) and demo portal accounts — or reinstall without `--demo`. Admin › Security must show all green.
4. **Fill §6 content + keys**, then `DEPLOYMENT.md` §9 (robots/sitemap, verification, consent) and §10 (smoke checklist).
5. **Cron:** add the two URLs shown in Admin › Backup and Admin › Campaigns (`DEPLOYMENT.md` §2.10).

`DEPLOYMENT.md` §5 (first sign-in & hardening) and §12 (updating an existing install) are the step-by-step.

---

## 8. Artefacts

| Artefact | Notes |
|---|---|
| **GitHub** | `TechPresenter/gimtedu` — `main` + `claude/upbeat-archimedes-xgwxtz` at this commit |
| **Production ZIP** | code + `admin/build` + `portal/build` + `assets/site` + `assets/css` — without `frontend/node_modules`, `tools/.cache`, `.git`, `config/local.php`, `storage/*`, `assets/uploads/*` (their `.htaccess`/`.gitkeep` kept) |
| **Production DB dump** | `gimt_erp` schema + core seeders `01`–`09` only — gzipped SQL, no demo rows |

---

## 9. Demo logins (demo data only)

> **Demo only — password `Demo@12345` for every account below, Super Admin `Admin@12345`.** Never load demo data on production; remove or reset these accounts on any internet-reachable server.

| Area | Login | Notes |
|---|---|---|
| Admin | `admin` / `admin@gimt.ac.in` | Super Admin |
| Admin roles | `administrator`, `admissions`, `academics`, `accounts`, `exams`, `library`, `hostel`, `transport`, `placement`, `alumni`, `content`, `staff` (or `<name>@gimt.ac.in`) | one per role |
| Student portal | `GIMT25BBA0002` (Navya Nair), `GIMT23BTAIDS0001` (Manish Mehta), `GIMT26BBA0029` (Vivek Sen), … (~1,250 active) | `Demo@12345` |
| Student activation demo | `GIMTX001249`, DOB `17/03/2006`, email `kriti.verma1249@gmail.com` | try **Activate account** |
| Faculty portal | `faculty@gimt.ac.in`, `nandini.gupta5@gimt.ac.in`, `mansi.yadav1@gimt.ac.in`, `gaurav.reddy6@gimt.ac.in` … (~10) | `Demo@12345` |

---

## 10. QA notes

- `php -l` on changed PHP files — no syntax errors.
- `tsc -b --noEmit` (admin), `tsc -p tsconfig.site.json`, `tsc -p tsconfig.portal.json` — clean.
- Public pages handle array query params as scalars (no 500); sitemap/robots/canonical/status codes, CSP report-only, consent defaults, theme/view-mode persistence, forms (validation, 419 on stale `_ft`, 429 throttling, honeypot) exercised.
- Remaining polish items in the tracker were not re-verified here.

---

*Generated for the 18 Oct 2026 handover. Update §6–§8 as GIMT fills content/keys and cut a new production ZIP/DB dump afterwards.*
