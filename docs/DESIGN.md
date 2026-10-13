# GIMT SmartCampus — Design reference

> **PUBLIC WEBSITE = "Design v3" at the end (latest client brief, 2026-10-12): orange-red + deep navy + white + warm grey,
> bold sans-serif editorial typography, Online Manipal–inspired layout patterns.** (Design v2 = superseded where v3 differs.)
> The admin panel keeps the navy/green look of mockup §1. For the website, mockups §2–§5 still define WHICH sections
> and content each page has; their navy/green colours and styling are superseded by Design v2.

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

---------------------------------------------------------------------------------------------------------------
## Design v2 — public website (client decision 2026-10-12): Online Manipal–style, orange + black
Reference captures (local, not committed): `tools/.cache/ref/home/*.png` (onlinemanipal.com homepage, desktop + mobile
slices, `desktop-menu.png` = Courses mega menu) and `tools/.cache/ref/landing/*.png` (their ad landing page);
`info.json` in each folder lists the measured colours/fonts. Take the **design language** from them — never their
logos, photos, text, university names, rankings or exact layouts (brief: inspired by, not copied). All content, photos
and claims are GIMT's own (settings/CMS/DB driven; no invented rankings, no fake urgency numbers).

Palette (website only): orange `#F26722` (primary CTA, highlights, links on hover; hover `#DD5A17`), ink `#151419`
(headings, dark bands, active pills), body text `#575757`, muted `#686868`, surfaces `#F7F6F6` / `#F4F4F4` / white,
pastels for cards `#CCC1FF` (lavender) `#A9D8FF` (sky) `#BEFFFE` (aqua) `#EFEFD0` (cream, footer backdrop)
`#F2DFB4` (sand), yellow `#FDD900` (urgency/countdown strip, "Most Popular" tags), ribbon red `#D32531` (ranking
ribbons), WhatsApp green for the chat tab only. GIMT logo stays as-is (it is navy/green — place it on light surfaces).
Typography: elegant high-contrast **serif display** for headings (self-hosted free font such as Fraunces / Playfair
Display / Instrument Serif — similar feel to their "Queens"), clean grotesk body (Inter, already self-hosted), large
light-weight serif numbers for stats.

Patterns: floating rounded light-grey header bar inset from the page edges (logo · nav with ▾ carets · orange
"Apply Now" pill · round search + menu icon buttons), sticky with a small top gap; big rounded mega menu (left sidebar
"By Level / By Department" filters + grid of program cards with a black tag pill top-right, pastel chips, duration,
full-width "View all programs" outline pill); hero = big serif headline with a hand-drawn orange ellipse around a key
word, doodles (sparkle stars, curved arrow, rotated sticker tags such as "Learn · Grow · Succeed"), faint grid-paper
background, student cut-out photo, row of small program image cards; pill tab filters (black active pill, outlined
inactive); carousels with round white arrow buttons; light-grey rounded cards (`#F4F4F4`, 16–20px radius, hairline
border) with image + pastel institution/department chip + rating/duration/fee meta + dark tag; pastel feature cards
with white pill buttons; dark ink bands with subtle grid pattern and serif stat numbers separated by thin dividers;
recruiter logo strip in a light card; testimonial cards with photo + program meta; FAQ accordion; footer = large white
rounded card on a cream backdrop with serif column headings + expandable "All programs" link directory, copyright on
the cream below; floating vertical green "Chat" WhatsApp tab on the right edge + round call button; rounded-full pill
buttons everywhere.

Campaign landing page `/admissions-open` (ad traffic): scrolling announcement strip (orange text on light bar),
reduced header (logo · phone · Apply Now), dark hero with grid + soft swirl, serif headline with a highlighted pill
phrase, orange scholarship line, programs list, Download Brochure pill, approvals/affiliations badge card (generic
emblems, settings-driven), right-side dark lead form card ("Free Career Counselling": name, email, +91 phone, program,
consent, Apply Now → POST /api/public/enquiry with UTM params captured into the enquiry source), yellow strip with a
countdown to the real application deadline (setting) and real seats-filled % (admissions vs intake) — hide either if
data is missing; then approvals carousel with red ribbon labels, explore programs with pill tabs, scholarships/
financing cards on a sand band + "Request call back", advantages tiles with colourful icon circles, student stories,
final CTA with the form again, sticky mobile bottom bar (Call · Apply). robots noindex,follow (configurable).

---------------------------------------------------------------------------------------------------------------
## Design v3 — public website, FINAL direction (latest client brief, 2026-10-12)
Supersedes Design v2's colours and serif typography; v2's layout patterns (floating pill header, mega menu with
sidebar filters + program cards, pill tabs, round arrow carousels, warm footer card, WhatsApp tab, /admissions-open
structure) remain. Identity: "premium academic institution meets modern technology university" — clean, trustworthy,
sophisticated, dynamic, human-designed, conversion-focused; distinctive, NOT generic AI aesthetics.

Tokens (CSS custom properties + Tailwind; WCAG-checked): orange-red vivid `#F05A28` (accents, large display text,
icons, scribbles), filled CTA `#D03E16` + white text (4.8:1), hover `#B83511`, orange-red text on white `#C93C14`
(5.1:1); deep navy `#0B2A5B` (headings, dark bands, active pills) / `#071A3B` (darkest); white; warm greys `#F5F2EE`
(sections) `#EDE8E2` (cards/borders); body text `#4A5568`. Small pastel tints optional for card accents; yellow only
for urgency strips; WhatsApp green for the chat button. Typography: bold modern sans-serif — Plus Jakarta Sans
700/800 display (tight tracking, fluid clamp() sizes), Inter body. No serif.

Visual language: asymmetric/layered campus photography with masks & clip-path, gradient overlays, floating 2D
geometric shapes, slow morphing SVG blobs, mesh/radial gradient lighting, subtle grain where tasteful, gradient borders
+ animated accent lines, depth through layering and negative space, glassmorphism only on selected floating panels
(solid @supports fallbacks, -webkit- prefixes beside standard properties), restrained 3D card tilt. Motion only on
important elements: scroll fade-up + stagger, text-mask heading reveal, clip-path image reveal, counters, parallax at
restrained speed, magnetic CTA (desktop), ripple, animated underline nav, accordion, skeletons, View Transitions page
transitions where supported; everything static under prefers-reduced-motion.

CTA system (site-wide, 180–300 ms, slight lift, arrow nudge, focus ring, active/loading/disabled states):
**Apply for Admission** = primary filled orange-red · **Explore Courses** = secondary navy · **Download Brochure** =
outline · **Request a Callback** = soft contrast (warm-grey/orange tint).

Required website widgets/components (see REQUIREMENTS "Client update — full website redesign brief"): floating
sticky header, animated mega menu, announcement ticker + admission notification bar, layered hero (value proposition,
supporting line, Apply + Explore CTAs, layered imagery, animated shapes, admission badge, trust indicators), animated
stats, course & department cards, rankings/accreditation (verified, settings-driven), campus life gallery, faculty
cards, research & innovation showcase, testimonials & success stories, news / events / academic calendar widgets,
scholarship & admission panels, interactive campus map, FAQ accordion, floating WhatsApp, sticky enquiry CTA,
back-to-top, smart enquiry forms, rich footer with newsletter; advanced: Course Finder (stream, degree, duration,
eligibility filters), Admission Journey timeline, Campus Explorer (interactive map + gallery), Placement Showcase
(verified data only), Smart multi-step Enquiry form, Academic Calendar with filters, Faculty Directory (search,
department filters, profiles), Scholarship Assistant (eligibility questions -> matching scholarships), Student Life
slider (draggable photos/videos), mobile admission bar (Apply + Call).
