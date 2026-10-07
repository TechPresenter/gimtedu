# GIMT SmartCampus — Design reference

The client supplied five mockups with the brief. Local captures (not committed — large files) live in
`tools/.cache/mockups/*.png` when available; this document describes them so the UI can be matched without them.
Use them as **direction**, not pixel specs: same structure, hierarchy, palette and density; original implementation.

Brand palette (see `tailwind.preset.cjs`): navy `brand-900 #0B2A5B` (primary, sidebar, headings, footer), royal blue
`brand-600/700`, green `accent-600 #22943F` (primary CTAs such as "Apply Now", active sidebar item), cyan/orange/
purple/pink only as soft tints for KPI icon chips. White cards, `shadow-card`, `rounded-2xl`, generous spacing.
Fonts: Plus Jakarta Sans (display/headings), Inter (body). Icons: lucide (admin `lucide-react`, website `icon()` PHP
helper with the same names).

---------------------------------------------------------------------------------------------------------------
## 1. Admin dashboard (`01-admin-dashboard-left.png`, `01-admin-dashboard-right.png`)
- **Sidebar** (navy, already built): logo + institute name, grouped menu with chevrons, active item = green pill,
  "Quick Links" card at the bottom (Visit Website, Student Portal, Faculty Portal, Help & Support).
- **Top bar** (built): global search "Search students, applications, fees, etc…" with Ctrl K hint, Academic Session
  switcher "2026-27", notification bell with count, messages with count, apps grid, admin avatar "Administrator ·
  Super Admin".
- **Welcome banner**: "Good Morning, Administrator 👋" + "Welcome to GIMT College Management System. Here's what's
  happening today." on a light card with a campus photo on the right (`assets/images/site/dashboard-banner.jpg`)
  and a navy info chip: "Academic Session 2026-27 · Admissions Open · [View Website →]" (green button).
- **KPI row** (8 compact cards, each a soft-tinted card with a coloured icon chip, big number, trend line in green/red
  with an arrow): Total Students 1,248 (↑12% from last session) · New Admissions 286 (↑18% this month) · Pending
  Applications 54 (↓6% from last week) · Active Programs 12 (0% no change) · Faculty Members 52 (↑4% this month) ·
  Fee Collected ₹28.4 L (↑22% this month) · Pending Dues ₹12.6 L (↓8% from last month) · Today's Attendance 92%
  (↑3% from yesterday).
- **Charts row** (4 cards with a period dropdown in the header): Admissions Trend (grouped bars Applications vs
  Admissions, last 6 months, tooltip) · Fee Collection (area/line, collection vs target, "This session") · Student
  Attendance (doughnut 92% overall: Present / Absent / Leave legend with %) · Course Distribution (doughnut with
  "1,248 Students" in the centre; Management / Technology / Commerce / Science / Diploma / Others with %).
- **Lists row**: Recent Activities (icon + title + person · program + relative time, "View All →") · Upcoming Events
  & Deadlines (date tile + title + subtitle + coloured category pill: Admission / Examination / Finance / Event /
  Result) · Top Programs by Admissions (rank, program, count, coloured progress bar, "This Session" dropdown).
- **Bottom row**: Quick Actions (tile buttons with tinted icons: Add Student, New Admission, Collect Fees, Mark
  Attendance, Create Notice, Generate ID Card, Issue Certificate, Send Email/SMS) · System Status (Server Online,
  Database Connected, Backup "Last 02 Oct 2026", Storage 68% used bar).

## 2. Homepage (`02-home-*.png`)
- Top bar (navy): "Admissions Open for 2026-27", helpline, email · Student Login, Faculty Login, Admin Login.
- Header (white, sticky): logo + "GLOBAL INSTITUTE OF MANAGEMENT & TECHNOLOGY", nav Home, About, Academics, Programs,
  Admissions, Campus Life, Placements, Research, Notices, Contact, search icon, green "Apply Now →". (Built.)
- Hero: light background with campus building + 4 smiling students photo on the right; left: "Shape Your Future."
  (navy) / "Build Your Legacy." (green) — client also asked for the headline **"Shaping Future Leaders"** (use it as
  eyebrow/tagline); paragraph; buttons "Apply for Admission →" (green) + "Explore Programs" (outline); "Admissions Open
  for 2026-27 · Learn • Grow • Innovate • Succeed"; floating glass chips on the image (Industry Focused, Expert
  Faculty, Modern Campus, Placement Support); "Watch Our Campus Tour" play button; white stats card overlapping the
  hero bottom: 10+ Programs, 1000+ Students, 50+ Faculty, 95% Student Satisfaction.
- Feature strip: Academic Excellence, Industry-Focused Curriculum, Experienced Faculty, Modern Infrastructure, Career
  Development, Student-Centric Education (icon + 2-line label, separated).
- "Explore Our Academic Programs": filter pills (All Programs, Undergraduate, Postgraduate, Management, Technology,
  Commerce, Diploma, Certificate) + "View All Programs →"; program cards (photo, title, duration, eligibility,
  ₹ fee/year, "View Program" outline + "Apply Now" green).
- "Why Choose GIMT": 8 icon cards (Industry-Relevant Learning, Expert Faculty & Mentorship, Digital Learning Resources,
  Career Preparation, Practical Exposure, Student Support, Innovation & Technology, Holistic Development).
- "About GIMT": text + 4 green-check bullets + "Discover GIMT →" (navy) + campus photo with "Virtual Campus Tour" play
  chip + Our Vision / Our Mission card overlay.
- "The Admission Journey": 5 numbered steps with icons connected by arrows (Explore Programs, Submit Application,
  Upload Documents, Verification & Approval, Enrollment & Fee Payment) + "Start Your Application →" + students photo.
- Three image cards with navy overlay: Campus Life, Placements, News & Events (title, text, link).
- CTA band (navy): "Ready to Build a Brighter Future?" + "Apply for Admission →" (green) + "Download Brochure".
- Footer (built): logo + "Education • Innovation • Opportunity", Quick Links, Academics, Support, Connect With Us
  (social icons, address, email, phone), bottom bar © + Privacy / Terms / Refund / Accessibility.
- The brief additionally wants: Academic Excellence, Placement Highlights + Recruiters logos strip, Student/Alumni
  Success Stories (testimonials), Events & News, FAQs and a Contact/Enquiry CTA on the homepage.

## 3. Programs page (`03-programs-*.png`)
Inner hero (navy overlay on campus photo, breadcrumbs Home › Programs, "Academic Programs", green subtitle
"Future-Ready Programs for a Brighter Tomorrow", text, 4 feature chips, script "Learn Grow Innovate Succeed").
"Find Your Program" + category tiles with icons (All, Undergraduate, Postgraduate, Management, Technology, Commerce,
Diploma, Certificate) + "View All Programs →". "Featured Programs" 3-column cards (photo, "Most Popular" ribbon,
title, duration, level, fee, short description, "View Details →" outline + "Apply Now →" green) with a sticky right
sidebar: navy "Need Help Choosing a Program?" counselling form (Full Name*, Phone*, Email*, Program Interest select,
"Get Free Counselling →" green), "Chat on WhatsApp" card, "Program Brochure — Download Brochure" card.
"Why Study at GIMT?" 8 icon tiles. "Career Opportunities" recruiter logo strip (TCS, Infosys, Wipro, HCL, Amazon,
Deloitte, Capgemini, Microsoft — use text/wordmark style placeholders, not real trademarked logos) +
"View Placement Details →". CTA band with graduation photo "Start Your Academic Journey at GIMT" + "Apply for
Admission" + "Explore All Programs". Footer.

## 4. About page (`04-about-*.png`)
Inner hero "About GIMT" / green "Empowering Minds. Shaping Futures." + 4 feature chips. "OUR STORY — A Journey Towards
Excellence" text + "Discover Our Journey →", stats card (1000+ Students, 50+ Faculty Members, 10+ Programs, 95% Student
Satisfaction) + students-on-lawn photo. Our Vision / Our Mission / Our Values cards (icon in tinted circle, faint
illustration). "WHY GIMT — Education That Makes a Difference" 8 small icon tiles + atrium photo. "OUR CAMPUS — A Place
to Learn, Grow and Thrive" + "Take a Virtual Tour →" + 6 photo tiles (Smart Classrooms, Advanced Laboratories, Digital
Library, Hostel Facilities, Sports & Recreation, Green & Safe Campus). "OUR LEADERSHIP — Guiding Towards a Brighter
Future" + "View All Members →" + 3 leader cards (photo, name, role, one-line bio: Dr. R.K. Sharma Chairman, Prof. Neha
Verma Director, Dr. Amit Kumar Academic Dean). "OUR APPROVALS & AFFILIATIONS — Recognized and Trusted" (AICTE
Approved, UGC Recognized, NAAC Accredited, Affiliated to State University, ISO 9001:2015 Certified — generic emblem
icons, no real logos). CTA band "Join GIMT and Be Part of a Global Community" + "Apply for Admission →". Footer.

## 5. Contact page (`05-contact-*.png`)
Inner hero "Contact Us" / green "We're Here to Help". Five contact cards in a row with round coloured icons: Call Us
(+91 9955446477, Mon–Sat 9–6), Email Us (info@gimt.ac.in), Visit Us (address + "Get Directions →"), Admission Enquiry
(admissions@gimt.ac.in), Student Support (support@gimt.ac.in). "Send Us a Message" form (Full Name*, Email*, Phone*,
Enquiry Type* select, Your Message*, terms checkbox, green "Send Message" button) next to a map card (Google Maps
embed or static map with "View on Google Maps →"). "Our Campus Locations" 4 photo cards (Main Campus, Academic Block,
Library & Learning Center, Hostel Facility, each "Get Directions →") + "View Virtual Campus Tour". "Quick Enquiry
Options" 4 cards (Admission Enquiry, Program Information, Scholarships, Campus Visit — "Enquire Now →"/"Book a Visit
→") + "Connect With Us" social icons + "Chat on WhatsApp — Chat Now". "Frequently Asked Questions" 2-column accordion
+ "View All FAQs →". CTA band with student photo "Still Have Questions?" + "Talk to Our Counsellor →". Footer.

Bundled photography for all of the above: `assets/images/site/*.jpg` (hero-campus-students, campus-*, facility-*,
program-*, students-*, leader-1..3, graduation, placement-student, news-campus, dashboard-banner).
