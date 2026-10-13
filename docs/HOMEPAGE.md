# GIMT homepage (`/`) — sections, data sources, islands

The homepage (`index.php`) is a thin driver: it reads the enabled CMS sections, prints the header (SEO from
`seo_settings` route `/` + homepage JSON-LD), renders each section and closes with the footer. Everything else lives in
`includes/partials/home.php` (section renderers + data queries) and `includes/partials/campus_map.php` (campus map
facts). Visual direction: `docs/DESIGN.md` "Design v3"; CMS contract: `docs/CMS.md` §1; platform: `docs/WEBSITE.md`.
*(To be merged into docs/WEBSITE.md by the integrator.)*

```php
$sections = hm_sections();                                   // enabled homepage_sections, CMS sort order
site_header(['seo' => ['route', '/'], 'preload' => hm_preload($sections), 'schema' => hm_schema($sections)]);
foreach ($sections as $key => $s) { echo hm_render($key, $s, $prev); $prev = $key; }
site_footer(['cta' => !isset($sections['cta'])]);           // the CTA section replaces the footer CTA band
```

## Rules the renderers follow

* **CMS controls the page.** Order and on/off come from `homepage_sections` (Website CMS › Homepage); each section's
  `content` is merged over `cms_default_sections()` so fields an editor never filled keep a sensible default. With an
  empty/missing table the full default homepage renders. A section that has nothing to show renders nothing.
* **No invented numbers.** Stats with a live `source` use `cms_stat_values()`; a source without data hides the stat
  (the typed placeholder is never shown for a live stat). `satisfaction` only counts when the `student_satisfaction`
  setting is actually set. Live counts shown with "+" are rounded *down* (1,248 → 1,200+) so the "+" stays true.
* **No dead links.** `hm_url_ok()` checks internal URLs against the routes that exist right now (mirrors
  `tools/router.php`: `{slug}.php`, pretty routes, published CMS pages served by `page.php`). A CMS button whose target
  is not built yet is hidden (or falls back to an on-page anchor, e.g. a "Placements" overlay card → `#placements`); the
  links appear automatically once the page exists.
* **Verified content only.** Approvals/affiliations from `approvals_list` / `affiliation`; rankings only from the
  optional `rankings_list` setting; research copy only from the published CMS `research` page; offers show first name +
  initial only; faculty cards show public fields only.
* **Anchors**: `#top #at-a-glance #highlights #programs #why-gimt #about #research #faculty #recognitions #admissions
  #scholarships #campus-life #campus-explorer #placements #recruiters #stories #news #calendar #faqs #enquiry`
  (a section's CMS `anchor` overrides its default id).

## Sections (CMS key → what renders → data)

| CMS key | Renders | Data / source |
|---|---|---|
| `hero` | Layered hero: eyebrow pill, CSS-only text-mask headline (`title` + `highlight`), lead (`subtitle`), admission notification badge (`badge`, else `admissions_open_text` while a session has `admissions_open = 1`; shows the `admissions_deadline` date), Apply (`primary_cta`) + Explore (`secondary_cta`) buttons, tagline words, trust row (`site_approvals()` + "Since {established_year}"), asymmetric photo stack (CMS `image` in an arch-cut frame with `.clip-in`, two bundled campus photos), morphing blob, gradient ring, floating shapes, first CMS `chip`, campus-tour `VideoLightbox` when `video_url` is set, **Spotlight** card = live `home_hero` banners (when `use_banners`) whose CTA resolves, scroll-snap with prev/next (no autoplay). LCP photo preloaded + `fetchpriority=high` | `homepage_sections.hero`, `banners`, settings |
| `stats` | `overlap` (white card overlapping the hero), `band` (navy band) or `cards`; vanilla `data-count` counters | `content.items[]` via `hm_stat()` |
| `features` | Icon strip (h2 is visually hidden) | `content.items[]` |
| `programs` | **CourseFinder** island + "Explore by department" cards (program count, HOD, "View programs" sets the finder's stream filter) | `site_programs()`, `departments` + HOD (`faculty`, public fields). CMS `mode`/`program_ids` decide which programs lead, `limit` how many show before "Show all", `categories` become quick-pick pills, `view_all` the header button |
| `why` | Sticky heading column + reasons grid with restrained `data-tilt` (side photo when `image` is set, else a counsellor CTA card) | `content.items[]` (tint mapped to v3 tints) |
| `academic_excellence` | About block (clip-reveal photo, Vision/Mission `cards`, text, check `bullets`, `cta`, virtual tour chip when `video_url`) **+ Research & Innovation** (navy band) **+ Faculty highlights** **+ Recognitions** (ribbon cards) | CMS section; `pages` slug `research` (hero/cards/text blocks) + latest `blog_posts` in a research category; `faculty` (HODs first, round-robin by department, 8); `rankings_list` + `site_approvals()` (block omitted when empty) |
| `admission_process` | `progress_steps()` timeline, Key dates / Fee overview / Documents panels, **ScholarshipAssistant** island | CMS `steps`, `cta`; `academic_sessions`, `site_admissions_deadline()` (+ `countdown()`), program fees (per-year range + per-course minimum), `pf_document_specs()`; `scholarships` |
| `campus_life` | **StudentLifeSlider**, **CampusExplorer**, CMS overlay `cards` (`overlay_card()`) | `gallery_items` (published albums, fest/sports/convocation first, interleaved, no portraits/program art, height ≥ 300px) + bundled photos; `hm_campus_map()` |
| `placements` | Navy band: live stats, recent accepted offers (one per company, best package, last 18 months, "Nisha K."), CMS text + photo, alumni success stories | `placement_offers` + `students` + `programs` + `companies`; `alumni_success_stories` (name only when the alumnus is public) |
| `recruiters` | `logo_marquee()` (pause control) | `companies` (`is_recruiter`) or CMS manual items |
| `testimonials` | `testimonial_slider()` | `testimonials` filtered by CMS `types`, `limit`, `autoplay` |
| `events_news` | Latest news (featured + list), upcoming events (date tiles, venue, Register when registration is open), **AcademicCalendar** island, notice board | `blog_posts`, `events`, `notices` (live, website) per CMS toggles/limits; calendar = `hm_calendar()` |
| `faqs` | `faq_accordion()` with search (2 columns) + "Still have a question?" callback strip | `faqs` per CMS `categories` / `home_only` / `limit` (falls back to all FAQs when none are marked for the home page) |
| `cta` | Navy band: heading, Apply + Download Brochure, contact quick links (call, WhatsApp, email, visit) and the **SmartEnquiry** island (`show_enquiry_form`) | CMS section, settings `phone`, `admission_email`, `whatsapp_number`, `city` |

JSON-LD (`hm_schema()`): `ItemList` of `Course` items (all website programs), one `Event` per upcoming website event
(online venues → `VirtualLocation`), `FAQPage` for the FAQs shown. `CollegeOrUniversity` comes from the header.

## Islands (frontend/site/islands)

All are lazy (mount near the viewport), keyboard accessible, reduced-motion/automation static, and render the same
footprint as their PHP fallback (which is real, crawlable content and works without JS).

| Island | Props | Behaviour | No-JS fallback |
|---|---|---|---|
| **CourseFinder** | `streams, levels, durations, eligibility: {value,label,count}[]`, `quick: {key,label,level?,cat?}[]`, `initial: {q,stream,level,duration,elig,cat}`, `limit`, `total`, `anchor`, `counsellorUrl`, `allUrl?`. Slot `items`: card wrappers with `data-key/stream/level/duration/elig/cat/name/search` | Search + Stream/Degree/Duration/Eligibility selects with facet counts, quick-pick pills, removable filter tags, URL sync (`?stream=&level=&duration=&elig=&cat=&q=`), FLIP grid (swipeable row on phones), "Show all", empty state → counsellor. `a[data-finder-stream]` links anywhere set the stream filter | Same controls as a GET form (server-side filtering by the same rules), cards, "Show all" → `/programs` |
| **ScholarshipAssistant** | `schemes: {id,name,type,value,max,category,criteria,rules:{minPct?,maxPct?,incomeMax?,specials?,categories?},benefit}[]`, `levels`, `programs: {value,label,level,fee,per}[]`, `percentBands`, `incomeBands: {value,label,max}[]`, `specials`, `categories`, `currency`, `callbackUrl`, `applyUrl`, `detailsUrl?`, `maxPercent?` | 3–5 questions (only those the data needs: score bands from merit thresholds, income bands from income limits, special categories from criteria, social category only if a scheme mentions SC/ST/OBC) → matching schemes, cap, indicative saving on the chosen program, "Request a Callback" (pre-fills SmartEnquiry) / Apply / Start over | Intro + list of every active scheme |
| **StudentLifeSlider** | `items: {src,alt,caption,tag?,video?}[]`, `ariaLabel?`, `interval?` (ms, 5500; 0 = off) | Scroll-snap track, mouse drag (no momentum; touch = native swipe), ←/→ on the track, prev/next, gentle auto-advance with pause button (also pauses on hover/focus/hidden tab/off-screen), counter + progress, video cards open the shared lightbox | Same track (native scroll) |
| **CampusExplorer** | `map: {src,width,height,alt}`, `categories: Record<key,label>`, `zones: {id,n,name,category,icon,x,y (%),summary,description,facts[],image,imageAlt,link}[]` | Original SVG map + numbered hotspot buttons (roving focus ←/→/↑/↓, Home/End), info panel (photo or icon, facts, link, prev/next), category chips, Map/List toggle, "jump to" list | Map with pins, first place's panel, list of all places |
| **AcademicCalendar** | `session: {name,start,end}`, `today`, `items: {id,title,start,end,type,meta?,url?}[]` (`type` academic/exam/holiday/event/admission), `types`, `fullUrl?` | Month grid (`role=grid`; arrows/Home/End/PageUp/PageDown, Enter selects a day), agenda list, filter chips with counts, today marker, month navigation within the session | Static month grid + this month's agenda |
| **SmartEnquiry** | `endpoint`, `levels`, `programs: {value,label,level}[]`, `times: {value,label,hint?}[]`, `officeHours?`, `consent`, `privacyUrl?`, `hidden` (source/form/utm), `applyUrl`, `brochureUrl?`, `phone?` | 3 steps (interest → name/+91 mobile/email → city/callback time/consent) with per-step validation mirroring `pf_submit_enquiry()`, progress, honeypot, CSRF (`postForm`), success state with next steps; posts `name, phone (+91 …), email, city, program (slug or level text), level, callback_time, consent, message (composed), source=home, form=smart-enquiry, utm_*` | Single-step form posting to `/api/public/enquiry` (`_csrf`) |

Cross-widget hand-off: `openEnquiry(url, {level, program, note})` (`islands/home/shared.ts`) scrolls to `#enquiry` and
pre-fills SmartEnquiry (the intent waits on `window.__gimtEnquiryIntent` if the island has not mounted yet); when the
CTA form is not on the page the buttons link to `/admissions#enquiry`.

## Campus map (`includes/partials/campus_map.php`)

`hm_campus_map()` → `{image, width: 1200, height: 760, alt, categories, zones[]}`. The illustration
`assets/images/site/campus-map/campus-map.svg` is original artwork (ring road, main gate, main building + auditorium
drum, academic blocks A/B, L-shaped technology block with solar roof, library rotunda, central lawn + fountain,
hostels, track + courts, cafeteria with awning, parking/bus bay, tree belts, compass). Zone facts are live where the
database has them: classrooms/seminar/exam halls/labs/auditorium (`classrooms`), library holdings (`books`), hostels +
amenities (`hostels`), bus routes (`transport_routes`). Hotspot coordinates are in the SVG's viewBox units.

## Requests / follow-ups for other units

* **CMS** — campus map zones (name, description, facts, photo, x/y) as an editable contract; a `hero.layout`
  (`layered` | `slider`) field so editors can opt into the full HeroSlider instead of the Spotlight card; an optional
  `rankings_list` setting (JSON `[{label, title, text, icon}]`) in Settings › Website for verified rankings.
* **Foundation** — the hero heading's mask keyframes (`hm-rise`, CSS-only so the LCP text never waits for JS) are
  printed as a ~300-byte `<style>` inside the hero section; move them into `assets/css/src/style.css` +
  `keyframes-webkit.css` (e.g. a `.mask-rise` utility) and drop the inline block. Everything else on the page uses
  Tailwind utilities + the shared component classes.
* **Content owners** — campus map copy that the database cannot verify (cafeteria, visitor parking, court types) is
  qualitative only; please confirm it (or move it into the CMS contract above).
