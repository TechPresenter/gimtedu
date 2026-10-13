# GIMT SmartCampus — Product Requirements (original brief)

Source: the client's brief for **GIMT SmartCampus Admin** (University / Institute ERP + Website CMS) for
**GLOBAL INSTITUTE OF MANAGEMENT & TECHNOLOGY (GIMT / "Global IMT")**. Later client decision: the admin is a
**React + TypeScript + Tailwind SPA backed by the Core PHP/MySQL JSON API**; the public website stays server-rendered
PHP + Tailwind (SEO). Everything else below still applies.

Design references (mockups supplied by the client) are described in `docs/DESIGN.md`.

The admin should feel like a premium modern university management platform (inspired by leading education platforms
such as Online Manipal, but DO NOT copy their UI, branding, assets, layouts or content). Professional enough for
real-world institutional use. Modular, scalable, production-ready, everything connected through one database.

---------------------------------------------------------------------------------------------------------------
## 1. Core objective — centralized admin for
Website · Students · Faculty & Staff · Admissions · Academics · Courses/Programs · Timetable · Attendance ·
Fees & Accounts · Examination · Results · Marksheets · Certificates · Notices & Communication · Library · Hostel ·
Transport · Placement · Alumni · Enquiries · Events · Newsletter · Reports & Analytics · Users & Roles · Permissions ·
System Settings · Security · Backups.

## 2. Design direction
Modern, clean, professional, high-end SaaS, education focused, responsive (desktop-first, fully mobile responsive),
light theme by default, navy blue primary branding, blue/cyan/green accents, white cards, soft shadows, rounded
corners, excellent spacing, clear typography, professional charts, minimal clutter. Brand: GLOBAL IMT — GLOBAL
INSTITUTE OF MANAGEMENT & TECHNOLOGY; use the GIMT logo throughout. Must NOT look like a generic AI-generated
dashboard — it should look like a professionally designed commercial university ERP.

## 3/4. Technology & URLs
Core PHP + MySQL backend, no Laravel/CodeIgniter/Composer. (Admin UI = React/TS/Tailwind SPA per client update.)
Admin lives under `/admin/` — classic URLs such as `/admin/students.php`, `/admin/fees.php` … must keep working
(they map onto SPA routes).

## 5. Admin login & security
Email/username + password login, remember me, forgot/reset password, session auth + timeout, logout, login attempt
protection, CSRF, XSS & SQL-injection prevention, password hashing, role-based auth, permission checks, login activity
logs, IP logging, last-login tracking, failed-login tracking. Dashboard never accessible without auth.

## 6. Dashboard
Header: global search, academic session selector, notifications, messages, quick add, admin profile, logout.
KPI cards: Total Students, Active Students, Faculty, Staff, New Admissions, Pending Fees, Today's Attendance,
Upcoming Exams, Placement Students, Alumni, Library Books, Active Transport Vehicles.
Charts: Student Growth, Admission Statistics, Attendance Trend, Fee Collection, Examination Performance, Placement
Statistics, Department-wise Students, Gender Distribution, Course Distribution.
Tables: Recent Admissions, Recent Enquiries, Today's Attendance, Upcoming Exams, Recent Payments, Recent Notices,
Recent Activities.
Quick actions: Add Student, Add Faculty, Add Admission, Create Notice, Create Exam, Collect Fee, Add Course, Create
Timetable, Add Event.

## 7. Sidebar
MAIN: Dashboard · ACADEMIC: Admissions, Students, Faculty & Staff, Academics, Courses/Programs, Departments, Subjects,
Timetable, Attendance, Examination, Results, Marksheet, Certificates · FINANCE: Fees & Accounts, Fee Collection,
Expenses, Invoices, Payment Reports · CAMPUS: Library, Hostel, Transport · CAREER: Placement, Alumni ·
COMMUNICATION: Notices & Circulars, Newsletter/Email, Events, Enquiries, Contact Messages, Feedback & Complaints ·
WEBSITE CMS: Pages, Menus, Home Page Sections, Banners/Sliders, Blog/News, Gallery/Media, FAQs, Testimonials,
Announcements, SEO Management · SYSTEM: Reports & Analytics, Users, Roles & Permissions, Activity Logs, Settings,
Backup, Security. Collapsed/expanded modes, active menu, submenus, icons, tooltips, responsive mobile drawer.

## 8. Student management
Fields: Student ID, Admission Number, Roll Number, Name, Profile Photo, Gender, DOB, Blood Group, Mobile, Email,
WhatsApp, Address, City, State, Country, Pincode, Father Name, Mother Name, Guardian, Emergency Contact, Course,
Program, Department, Semester, Section, Batch, Admission Date, Academic Session, Status.
Profile page tabs: Overview, Personal Details, Academic Details, Attendance, Fees, Examination, Results, Documents,
Certificates, Library, Hostel, Transport, Placement, Communication, Activity History.
Actions: Edit, View, Print, Download, Export, Generate ID Card, Generate Certificate, Deactivate.
Bulk: Import Excel/CSV, Export Excel/CSV, Bulk status update.

## 9. Faculty & staff
Fields: Employee ID, Name, Photo, Designation, Department, Qualification, Specialization, Email, Phone, Joining Date,
Employment Type, Salary, Status. Features: faculty profile, faculty attendance, assigned subjects, timetable, leave,
documents, payroll reference, activity logs.

## 10. Admission management
Pipeline: Enquiry → Application → Document Verification → Entrance/Interview → Approval → Fee Payment → Admission
Confirmed. Features: admission form, application number, applicant profile, document upload, application status,
counselling notes, follow-up, admission fee, offer letter, admission receipt, convert applicant to student.
Dashboard: new, pending, approved, rejected, converted, admission source, course-wise admission.

## 11. Academics
Manage academic sessions, departments, courses, programs, degrees, semesters, subjects, sections, batches, credits,
faculty assignments. Relationship: Department → Program → Course → Semester → Subject → Faculty → Students.

## 12. Timetable
Weekly / class / faculty timetable, room allocation, subject, faculty, time slot, semester, section, classroom.
Prevent faculty, room and class conflicts. Views: daily, weekly, faculty, student, room. Print timetable.

## 13. Attendance
Dashboard. Student & faculty attendance; daily, monthly, subject-wise, semester-wise; late/absent/present/leave.
Methods: manual, biometric-integration ready, QR-based ready. Reports: student attendance %, defaulters, department
report, subject report. Automatic alerts for low attendance.

## 14. Fees & accounts
Fee structure heads: Admission, Tuition, Examination, Hostel, Transport, Library, Miscellaneous. Features: student
fee profile, fee assignment, installments, discounts, scholarships, late fees, payment history, pending payments,
receipts, refunds. Payment modes: cash, bank, UPI, online gateway. Dashboard: total collection, pending, overdue,
today's collection, monthly collection. Generate payment receipt, invoice, fee statement.

## 15. Examination
Exam types, schedules, subjects, exam rooms, invigilators, student eligibility, exam attendance, marks entry.
Workflow: Create Exam → Assign Subjects → Schedule → Allocate Students → Enter Marks → Verify → Publish Result.

## 16. Results & marksheet
Marks entry: internal, external, practical, total, grade, grade point, SGPA, CGPA, result status
(PASS / FAIL / BACKLOG / ABSENT / WITHHELD). Professional marksheet PDF with GIMT logo, institute name, student
details, course, semester, subject-wise marks, grade, SGPA, CGPA, result, controller signature.

## 17. Certificates
Bonafide, Transfer, Character, Course Completion, Internship, Migration, Provisional. Dynamic templates, unique
certificate number, QR verification at `/verify-certificate/{certificate_number}`.

## 18. Notices & communication
Notice fields: title, category, audience, description, attachment, publish date, expiry date, status. Audience: all,
students, faculty, staff, parents, alumni, department, course, semester. Channels: email, SMS-ready, WhatsApp-ready.

## 19. Library
Books, authors, categories, publishers, book copies, ISBN, rack, members, issue, return, fine, lost books.
Dashboard: total books, issued, available, overdue, fine collection.

## 20. Hostel
Hostels, buildings, floors, rooms, beds, students, room allocation, hostel fees, complaints, visitors, warden.
Dashboard: total rooms, occupied, available, students, pending fees, complaints.

## 21. Transport
Vehicles, vehicle number, driver, routes, stops, students, transport fees, fuel, maintenance, complaints.
Future-ready: GPS tracking, live bus tracking, driver mobile app.

## 22. Placement
Metrics: total companies, placement drives, eligible students, placed students, highest package, average package.
Manage companies, job roles, drives, eligibility, applications, interviews, offers, placement results, training,
mock tests. Reports: placement %, department-wise, company-wise hiring, package statistics.

## 23. Alumni
Profiles, batch, course, company, designation, location, industry, mentors, events, job opportunities, success
stories, donations. Dashboard: total alumni, working professionals, companies, mentors, events, job opportunities.

## 24. Website CMS
Pages: Home, About, Vision & Mission, Leadership, Programs, Admissions, Academics, Campus, Placement, Alumni, Contact.
Page builder blocks: hero, text, image, video, cards, statistics, testimonials, FAQ, CTA, gallery, course listing,
faculty listing, placement section. Banners: desktop image, mobile image, heading, subheading, CTA, URL, sort order,
status. Blog: title, slug, thumbnail, category, author, content, tags, SEO, publish date, status. Media: images,
videos, PDFs, documents. Menus: header, footer, dropdown, mega menu.

## 25. SEO management
Per page: SEO title, meta description, keywords, canonical, OG title/description/image, robots, schema JSON-LD.
Generate sitemap.xml and robots.txt. SEO dashboard.

## 26. Users & roles (RBAC)
Default roles: Super Admin, Administrator, Admission Officer, Academic Admin, Faculty, Accountant, Exam Controller,
Librarian, Hostel Warden, Transport Manager, Placement Officer, Alumni Coordinator, Content Manager, Staff.
Module-based permissions: VIEW, CREATE, EDIT, DELETE, EXPORT, IMPORT, APPROVE, PUBLISH, MANAGE.

## 27. Activity log
User, action, module, record, date, time, IP, browser, status — e.g. "Admin updated student profile",
"Accountant created payment", "Faculty entered marks", "Content manager published blog".

## 28. Settings
GENERAL (institute info, address, contact, email, website, academic session) · WEBSITE (logo, favicon, theme, colors,
typography, header, footer) · ACADEMIC (semester, grading, attendance rules, passing %) · EMAIL & SMS (SMTP, email
templates, SMS gateway) · PAYMENT (gateway, currency, tax, invoice settings) · SECURITY (password policy, 2FA-ready,
login security, session timeout) · BACKUP (database backup, file backup, history, restore) · ANALYTICS (Google
Analytics, Search Console, Meta Pixel) · SOCIAL (Facebook, Instagram, LinkedIn, YouTube, X).

## 29. Reports & analytics
Student, Admission, Attendance, Fee, Exam, Result, Faculty, Library, Hostel, Transport, Placement, Alumni, Website
reports. Filters: date, academic session, department, course, semester, status. Export: PDF, Excel, CSV, Print.

## 30. Notification center
Types: new admission, pending fee, low attendance, exam reminder, new enquiry, new contact message, new application,
certificate generated, system alert. Panel: read, unread, mark all read, delete.

## 31. Global search
Students, faculty, admissions, courses, fees, results, notices, pages, blog, alumni, companies — grouped results.

## 32. Database
Normalized MySQL with foreign keys (see `database/schema/*.sql`).

## 33–35. UI components, tables, forms
Components: cards, tables, modals, dropdowns, tabs, breadcrumbs, forms, file uploader, date picker, search, filters,
pagination, status badges, toasts, confirmation dialogs, empty states, loading states, skeleton loaders. AJAX
everywhere; no full page reloads for simple actions.
Every major listing: search, filters, sorting, pagination, column control, bulk selection, bulk actions, export,
print, view, edit, delete (e.g. Students → search + department + course + semester + status).
Forms: proper labels, required indicators, validation, inline errors, success messages, file validation, preview,
AJAX submit, loading state, duplicate detection. Never allow invalid data to reach the database.

## 36–38. Responsive, accessibility, performance
Desktop full sidebar; tablet collapsible; mobile drawer. Tables scroll horizontally or become cards; forms single
column on mobile; responsive charts. Keyboard navigation, labels, focus states, accessible buttons, contrast, ARIA.
Optimised queries, server-side pagination (never load thousands of rows), caching where appropriate.

## 39–40. Security & uploads
PDO prepared statements, password hashing, CSRF tokens, XSS sanitisation, input validation, output escaping, session
regeneration, role validation, permission middleware, secure uploads (extension, MIME, size, filename, random names,
no PHP execution in upload folders), access control, audit logs. Never trust client-side validation alone.

## 41–43. UX, empty/error states, toasts
The admin should always know: where am I, what needs attention, what changed, what can I do. Use breadcrumbs, page
title, description, quick actions, filters, data cards, charts, tables, activity timeline. Professional empty states
with CTAs ("Add Student", "Create Admission", "Create Notice") — never blank screens. Toast copy examples:
"Student added successfully." / "Unable to save student. Please try again." / "This student already exists." /
"Are you sure you want to delete this record?"

## 44–46. Profile, dark mode, export
Profile: photo, name, email, phone, role, password change, security, login history, activity. Optional dark mode,
saved per admin (default light). CSV / Excel / PDF / Print export in every major module.

## 47. Print layouts
Student profile, fee receipt, marksheet, certificate, attendance report, timetable, admission form, ID card,
placement report.

## 48–49. Backup & activity dashboard
Create / view / download / delete / restore database backups; show date, size, created by, status.
Real-time style activity feed ("Admin added new student", "Faculty uploaded marks", "Accountant received payment").

## 50–53. Quality bar & deliverable
Premium university ERP + SaaS admin. Avoid cheap Bootstrap look, excessive gradients, random colors, huge icons,
excessive animation, cluttered tables, poor spacing, generic AI dashboards. Consistent spacing, professional
typography, clean cards, subtle shadows, clear hierarchy, premium icons, consistent navy/blue branding, data-rich but
readable. **Do not create static UI only — every ADD/EDIT/DELETE/VIEW/SEARCH/FILTER/EXPORT/IMPORT must work against
the backend. No fake buttons that do nothing. Do not leave important modules as placeholders.** Clear separation of
UI, business logic, database, authentication, permissions and reusable components.

Build order: (1) auth, DB, layout, dashboard, users, roles, permissions (2) students, faculty, departments, programs,
courses, subjects, academics (3) admissions, timetable, attendance, fees (4) examination, results, marksheets,
certificates (5) library, hostel, transport (6) placement, alumni (7) notices, events, enquiries, newsletter
(8) website CMS: pages, menus, banners, blog, gallery, SEO (9) reports, analytics, settings, backup, security.

---------------------------------------------------------------------------------------------------------------
## Public website (homepage brief)
World-class, premium university website for GIMT inspired by modern platforms like Online Manipal but with a unique
original design. GIMT logo, navy-blue / royal-blue / green palette, high-resolution campus imagery, modern typography,
spacious sections. Sticky header with logo, Programs, Admissions, Academics, Campus Life, Placement, Alumni, About,
Contact and **Apply Now**; hero with headline **"Shaping Future Leaders"**, CTA buttons, campus/student visuals and
key statistics; then Programs & Courses, Why GIMT, Admissions Process, Academic Excellence, Campus Life, Placement
Highlights, Recruiters, Student/Alumni Success Stories, Events & News, FAQs, Contact/Enquiry CTA, and a professional
multi-column footer. Responsive, elegant, interactive, fast-loading, SEO-friendly, visually comparable to a premium
international university website.

Requested public file layout: `index.php, about.php, programs.php, admissions.php, academics.php, campus.php,
placement.php, alumni.php, faculty.php, contact.php, blog.php, faq.php`, `assets/{css/style.css, js/main.js, images,
fonts, uploads}`, `includes/{header.php, navbar.php, footer.php, config.php}`, `admin/{index.php, students.php,
faculty.php, admissions.php, ...}`.

---------------------------------------------------------------------------------------------------------------
## Client update — website technology & motion (latest instruction, overrides the PHP-only website wording)
Build the website using **TypeScript + React.js + Tailwind CSS + CSS3**, with a premium, highly interactive
university experience: CSS3 animations (`@keyframes` incl. `-webkit-` prefixed), smooth transitions, hover effects,
scroll-reveal animations, parallax effects, animated gradients, floating elements, image overlays, glassmorphism,
animated patterns, premium sliders/carousels (hero slider, testimonial and recruiter sliders), auto-scroll cards,
typing indicators, animated counters, progress animations, loading screens, skeleton loaders, ripple effects,
magnetic buttons, dropdown/mega-menu transitions, accordion animations, modal/overlay effects, image zoom, lazy-load
animations, staggered card reveals, marquee sections, cursor interactions, sticky-header transitions, back-to-top
animation and polished micro-interactions. Keep everything smooth, responsive, accessible and performance-optimised
(animate `transform`/`opacity` only), with `prefers-reduced-motion` support and no excessive/distracting animation.

Architecture decision (keeps the brief's SEO + fast-loading requirement): pages stay server-rendered by PHP (real HTML
content for search engines and instant first paint); a Vite-built **React + TypeScript "islands" bundle** mounts the
interactive components onto server-rendered placeholders (progressive enhancement — content is still readable
without JS). The admin panel likewise uses subtle premium motion (`Reveal`, `Stagger`, `CountUp`, page transitions).

## Client update — 2026-10-12: website look = Online Manipal style, orange + black
The client asked for the website front pages, menu and everything to follow https://www.onlinemanipal.com/ in colour
and design, and for a campaign landing page like their "online degree courses" ad page. Decision: public website uses
"Design v2" in docs/DESIGN.md (orange #F26722 + near-black #151419, serif display headings, floating pill header,
Manipal-style mega menu, pastel cards, dark grid bands, cream footer); campaign landing page at **/admissions-open**.
Inspired-by only: no copying of their logos, images, text, rankings or exact layouts. The admin panel keeps navy/green.

## Client update — full website redesign brief (2026-10-12, latest; supersedes the orange + black note above)
Redesign the GIMT public website into a premium, modern, high-conversion university website (world-class UI/UX +
creative frontend + motion design). Brand: orange-red, deep navy, white, subtle warm grey; modern sans-serif
typography (Inter / Plus Jakarta Sans / Manrope); clean, trustworthy, sophisticated, dynamic, human-designed,
conversion-focused; authentic campus imagery (students, labs, classrooms, faculty, academic activities); one
consistent design system across all pages. Client recommendation: orange-red + navy + white, bold editorial
typography, asymmetrical campus photography, restrained 3D cards, animated statistics and strong admission CTAs —
motion only on important elements so the site stays premium AND fast.
Components, motion, visual effects, hero, CTA system and advanced widgets: see docs/DESIGN.md "Design v3".
Interaction: hover/focus/active/disabled/loading states everywhere, keyboard-accessible navigation and dropdowns,
form success/error messages, touch-friendly mobile, clear CTA hierarchy, **no dead links or decorative controls
pretending to work**. Technical: inspect and reuse the existing stack (PHP SSR + React/TS islands + Tailwind + CSS3),
preserve the backend and all working features, semantic HTML5, CSS custom properties for tokens, SVG illustrations,
IntersectionObserver / Web Animations API, responsive + lazy images, progressive enhancement, @supports feature
queries, vendor prefixes only where useful (-webkit-backdrop-filter, -webkit-mask-image next to standard properties).
Performance & a11y: WCAG, prefers-reduced-motion, no unnecessary JS, no layout shift, transform/opacity animation,
no horizontal overflow, optimised images/fonts, readable contrast + visible focus, no autoplay audio, SEO-friendly,
Core Web Vitals-conscious. Responsive: large desktop, laptop, tablet, mobile (mobile: readable text, fast, compact
nav, touch-friendly CTAs, lightweight animation). Implement real components and interactions (not mockups), page by
page, reusing components.
Final QC (must be run before completion, fix everything found): every page at desktop/tablet/mobile — all links,
buttons, dropdowns, forms, CTAs work; animations smooth/purposeful; no horizontal overflow, layout shift, broken images
or console errors; WebKit effects have standard fallbacks; reduced motion respected; keyboard + visible focus
everywhere; optimised images/fonts/scripts; SEO metadata, semantic headings, alt text, structured data; admission
information and institutional claims use VERIFIED content only (settings/CMS/DB — no invented rankings or numbers);
backend and existing features intact; remove redundant animations, excessive gradients, generic effects, unneeded
dependencies.

## Client update — 2026-10-12: complete audit, enhancement & finalization brief (latest; adds to everything above)
Act as senior full-stack developer, UI/UX designer, technical SEO expert, cybersecurity specialist and university
website auditor. Audit, enhance and finalize the GIMT website WITHOUT breaking existing functionality:
1. **Policy pages** (CMS pages, admin-editable): Privacy Policy, Terms & Conditions, Disclaimer, Cookie Policy, Refund &
   Cancellation Policy, Admission Policy, Scholarship Policy, Anti-Ragging Policy, Grievance Redressal Policy,
   Accessibility Policy, Equal Opportunity Policy and other relevant university policies (e.g. Code of Conduct, Fee
   Policy, Examination Policy, Internal Complaints Committee / POSH, Mandatory Disclosure). Verified institutional info
   only; unverified legal details as clearly marked placeholders (e.g. "[To be confirmed by GIMT: …]").
2. **Dynamic Notice Board & Announcement System**: admission notices, examination schedules, results, holidays, events,
   scholarship announcements, important deadlines; downloadable PDFs, categories, search, filters, pinned notices,
   publication + expiry dates, archives, pagination, admin management.
3. **Route audit**: fix every missing page, broken link, 404/500, wrong redirect, non-functional button, missing asset,
   invalid form and console error; custom 404 and 500 pages; relevant redirects (admin-managed redirect manager);
   breadcrumbs, page summaries, related content, meaningful empty states.
4. **Crawl & index**: valid XML sitemaps + sitemap index when needed, robots.txt, canonical URLs, correct HTTP status
   codes, crawl rules, per-page indexability settings.
5. **SEO**: technical, on-page, off-page workflow support, local, international, image, video, structured data —
   unique titles/descriptions, Open Graph, Twitter Cards, semantic HTML, heading hierarchy, internal linking, alt text,
   pagination handling, hreflang where appropriate, Schema.org, Google Search Console, GA4, Google Tag Manager,
   consent-aware conversion tracking (cookie consent + Google Consent Mode), Core Web Vitals, SEO audit tooling.
6. **Integrations** via admin settings + environment variables (secrets never in frontend code): Google Maps, spam
   protection (reCAPTCHA or equivalent), secure SMTP / transactional email, WhatsApp, social media, error monitoring,
   analytics, other useful services.
7. **Light / Dark / System mode** on the website with persistent preference, accessible contrast, consistent styling.
8. **View modes**: Grid, List, Compact, Detailed for courses, notices, events, news, faculty directory — responsive,
   remembered per user.
9. **Branding/UI**: premium orange-red + navy + white (Design v3), professional typography, responsive navigation,
   animated gradients, 2D shapes, blobs, subtle 3D, glassmorphism, scroll reveals, hover/interactive cards,
   micro-animations, sticky admission CTAs, floating WhatsApp, skeletons, transitions, reduced motion; speed,
   usability, accessibility first.
10. **Forms (every form)**: accessible labels, required-field validation, client + server validation, clear error/success
   messages, spam prevention, secure submission, duplicate-submission protection, DB integration; reliable email
   validation + normalisation; international phone validation with a searchable country selector (flags, names,
   dialing codes, country-specific number validation via a reliable library), normalised to E.164; country, state,
   city, postal code and address fields wherever necessary.
11. **Admin**: role-based permissions + audit logs for pages, policies, notices, courses, enquiries, SEO metadata,
   redirects, sitemap generation, robots settings, analytics, themes, page-view modes, forms, media, integrations,
   global website settings.
12. Inspect and reuse the existing architecture, preserve working features, avoid unnecessary packages, never replace
   real functionality with static mockups. Test every page, link, form, validation rule, theme, view mode,
   integration, redirect, SEO output, sitemap, robots directive, security control and breakpoint; fix everything found.
13. **Deliverables**: production-ready, secure, accessible, responsive, high-performance site + a concise report of
   completed features, resolved errors, remaining limitations, required credentials, SEO setup requirements and
   deployment instructions (docs/REPORT.md, docs/DEPLOYMENT.md).
