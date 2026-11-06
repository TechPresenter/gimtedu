# GIMT Website CMS — content contract

The contract between the **Website CMS admin** (`/admin/cms/*`, unit `cms_site`) and the **public website** (PHP pages +
React islands, `docs/WEBSITE.md`). Everything the admin stores is described here: tables, JSON shapes, allowed values
and how the website must render them. The single source of truth for field definitions is
`app/services/cms.php` (`cms_section_types()`, `cms_block_types()`), which the admin forms are generated from and the
API validates against (`GET /api/cms/schema` returns it). When you change a shape, change it there and here.
Website templates that need the helpers below (`cms_page_blocks()`, `cms_paragraphs()`, `cms_stat_value()`,
`cms_sitemap_urls()`, `cms_robots_txt()` …) call `require_once APP_ROOT . '/app/services/cms.php';` (no side effects;
it only loads `app/crud.php` for its exception classes).

Brief: `docs/REQUIREMENTS.md` §24 (Website CMS), §25 (SEO), "Public website", "Client update — website technology &
motion" and "Design v3" (`docs/DESIGN.md`: deep navy `#0B2A5B` headings and dark bands, orange-red `#D03E16` filled CTAs /
`#F05A28` large accents / `#C93C14` small text, warm greys `#F5F2EE` `#EDE8E2`, Plus Jakarta Sans 700/800 headings over
Inter — no serif). The admin previews (page builder, home sections, banners, menus, announcements) approximate Design v3.

---------------------------------------------------------------------------------------------------------------------
## 0. Conventions used in every shape

| Name | Shape | Render with |
|---|---|---|
| **text** | plain string (never HTML) — escape with `e()` | `e($v)` |
| **paragraphs** | plain string; a blank line starts a new paragraph | `cms_paragraphs($v)` → `<p>…</p>` (escaped) |
| **html** | rich text, already sanitised server-side with `sanitize_html()` | echo as-is (re-sanitise if it comes from elsewhere) |
| **url** | `/about`, `/programs/mba`, `#faq`, `https://…`, `mailto:…`, `tel:…` | `cms_link($url)` |
| **link** | `{"label": "Apply Now", "url": "/apply"}` (both optional; render only when `label` and `url` are set) | `button_link($l['label'], $l['url'])` |
| **image** | `assets/images/site/x.jpg` (bundled), `assets/uploads/…` (uploaded) or `https://…` | `site_image($path)` |
| **icon** | lucide kebab name from `cms_icon_names()` (same names as PHP `icon()` and `frontend/site/lib/Icon.tsx`) | `icon($name, 'h-5 w-5')` |
| **tint** | `lavender` `sky` `aqua` `cream` `sand` `orange` `ink` — small Design v3 card accents: lavender `#E7E1FF`, sky `#DCEBFB`, aqua `#D9F6F4`, cream/sand = warm grey `#EDE8E2`, orange = accent-50, ink = navy `#0B2A5B` with white text (empty = automatic rotation) | CSS class `tint-<name>` |
| **stat** | `{"value": "1000", "prefix": "", "suffix": "+", "label": "Students", "icon": "users", "source": "students"}` — `value` is what to show; when `source` is set the website must show the **live** number instead: `cms_stat_value($source)` (falls back to `value`) | `stat_counter()` / `data-count` |

Live stat sources (`cms_stat_sources()`): `programs`, `departments`, `students`, `faculty`, `alumni`, `recruiters`,
`offers`, `placement_rate` (%), `highest_package` (LPA), `average_package` (LPA), `years` (since `established_year`),
`satisfaction` (setting `student_satisfaction`, %). No invented numbers: prefer a `source` over a typed value.

Fields not listed in a shape are dropped by the API. Optional fields may be missing or `""`/`null` — always guard.
All lengths below are maximums enforced by the API.

---------------------------------------------------------------------------------------------------------------------
## 1. Home page sections — table `homepage_sections`

One row per `section_key` (unique). Columns: `title` (section heading), `subtitle` (lead paragraph under the heading),
`content` (JSON, below), `is_enabled`, `sort_order`. The home page renders **enabled** sections in `sort_order`
using `site_section($key)` (returns `null` when disabled → skip). Every content object may also carry:
`eyebrow` (text ≤ 120, small label above the heading) and `anchor` (id for in-page links, `[a-z0-9-]`).

| key | title / subtitle mean | content |
|---|---|---|
| `hero` | title = headline line 1 ("Shape Your Future."), subtitle = paragraph | `highlight` text ≤120 (second line / circled word, accent colour) · `image` **required** · `image_alt` · `primary_cta` link · `secondary_cta` link · `badge` text ("Admissions Open for 2026-27") · `tagline_words` string[] ≤6 ("Learn", "Grow", …) · `chips` [{`icon`, `label` ≤40}] ≤6 (floating glass chips) · `video_url` url + `video_label` text ("Watch Our Campus Tour") · `use_banners` bool — when true, live `home_hero` banners rotate in the hero's **spotlight card** next to the hero copy (§4); the hero copy itself always comes from this section |
| `stats` | optional heading | `variant` `overlap` (white card overlapping the hero bottom — mockup) \| `band` (dark navy band, large bold numbers, thin dividers) \| `cards` · `items` stat[] 2–6 |
| `features` | optional (visually hidden heading) | `items` [{`icon` **req**, `title` **req** ≤60, `text` ≤160}] 3–8 — the icon strip under the hero |
| `programs` | "Explore Our Academic Programs" | `categories` string[] ⊂ `all ug pg management technology commerce diploma certificate` (pill filters, `program_categories()` keys) · `mode` `all` \| `featured` (`is_featured=1`) \| `selected` · `program_ids` int[] (mode selected, in this order) · `limit` 3–24 · `view_all` link. Programs come from `programs` (`status='active' AND show_on_website=1`) → `program_explorer()` |
| `why` | "Why Choose GIMT" | `items` [{`icon` **req**, `title` **req** ≤60, `text` ≤200, `tint`}] 4–12 · `image` (optional side photo) |
| `academic_excellence` | heading + lead (mockup "About GIMT") | `text` paragraphs ≤2000 · `bullets` string[] ≤8 (green-check list) · `cta` link ("Discover GIMT") · `image` · `image_alt` · `video_url` + `video_label` ("Virtual Campus Tour" chip) · `cards` [{`icon`, `title` **req**, `text` ≤300}] ≤3 (Our Vision / Our Mission overlay) |
| `admission_process` | "The Admission Journey" | `steps` [{`icon`, `title` **req** ≤60, `text` ≤200}] 3–7 → `progress_steps()` · `cta` link · `image` · `image_alt` |
| `campus_life` | "Life at GIMT" | `cards` [{`title` **req** ≤80, `text` ≤200, `image` **req**, `url`, `link_label` ≤40}] 2–6 → `overlay_card()` (Campus Life / Placements / News & Events) |
| `placements` | "Placement Highlights" | `stats` stat[] ≤4 · `text` paragraphs · `image` · `image_alt` · `show_top_offers` bool + `offers_limit` 3–12 (latest accepted `placement_offers` by package: student, program, company, package) · `cta` link |
| `recruiters` | "Our Recruiters" | `source` `companies` (active `companies` with `is_recruiter=1`, logo or wordmark) \| `manual` · `limit` 6–48 · `items` [{`name` **req**, `logo` image, `url`, `industry`}] (manual) · `rows` 1\|2 → `logo_marquee()` · `cta` link |
| `testimonials` | "Student & Alumni Success Stories" | `types` ⊂ `student alumni parent recruiter` (empty = all) · `limit` 3–20 · `autoplay` ms 0–20000 (0 = off) · `cta` link. Items from `testimonials` (`status='active'`, `sort_order`) → `testimonial_slider()` |
| `events_news` | "Events & News" | `show_events` bool + `events_limit` 1–6 (upcoming `events`, `show_on_website=1`) · `show_news` bool + `news_limit` 1–6 (published `blog_posts`) · `show_notices` bool + `notices_limit` 1–10 (published `notices`, `show_on_website=1`) · `view_all_events` link · `view_all_news` link |
| `faqs` | "Frequently Asked Questions" | `categories` ⊂ `general admissions academics fees hostel placement campus` (empty = all) · `home_only` bool (`faqs.show_on_home=1`) · `limit` 3–20 · `columns` 1\|2 · `view_all` link → `faq_accordion()` |
| `cta` | heading + text (contact / enquiry CTA band) | `primary` link · `secondary` link · `image` · `theme` `dark` \| `orange` \| `light` · `show_enquiry_form` bool (EnquiryForm island → `POST /api/public/enquiry`, source `home_cta`) |

Example (`hero`):
```json
{"eyebrow":"Shaping Future Leaders","highlight":"Build Your Legacy.","image":"assets/images/site/hero-campus-students.jpg",
 "image_alt":"GIMT students outside the academic block","primary_cta":{"label":"Apply for Admission","url":"/apply"},
 "secondary_cta":{"label":"Explore Programs","url":"/programs"},"badge":"Admissions Open for 2026-27",
 "tagline_words":["Learn","Grow","Innovate","Succeed"],"chips":[{"icon":"briefcase-business","label":"Industry Focused"}],
 "video_url":"https://www.youtube.com/watch?v=…","video_label":"Watch Our Campus Tour","use_banners":true}
```
Defaults for every section (also used by "Reset to default" in the admin and by `database/seed/05_cms_core.php`):
`cms_default_sections()`.

---------------------------------------------------------------------------------------------------------------------
## 2. Pages & page builder — table `pages`

| Column | Notes |
|---|---|
| `title`, `slug` (unique, `[a-z0-9-]`), `subtitle`, `excerpt` (≤500, hero text + meta fallback), `banner_image` (image **path** chosen with the CMS image picker — campus photo, media library or upload; shared files are never deleted with a page) | hero of the page |
| `content` | rich text (sanitised). Rendered **before** the blocks (or alone when there are no blocks) |
| `blocks` | JSON array of builder blocks (below), rendered in order; `hidden` blocks are skipped |
| `template` | `default` (page hero + contained content), `full_width` (blocks edge to edge), `landing` (no page hero; reduced header for campaigns), `sidebar` (content + sticky enquiry sidebar) |
| `parent_id` | breadcrumbs (`Home › About › Leadership`) |
| `is_system` | core pages: cannot be deleted and their slug is locked |
| `status` / `published_at` | public only when `status='published' AND (published_at IS NULL OR published_at <= NOW())` |

**URLs** (`cms_page_url($page)`):
- **route pages** — slug has a dedicated PHP template (`about.php`, `campus.php` …) or is a known public route
  (`cms_public_routes()`, e.g. `/placement`): the page lives at `/{slug}`; the template reads the row for its hero
  text/banner (`sa_cms_page('about')`), its rich text and (optionally) its blocks. SEO is stored on the **route** row.
- **system pages** (`is_system = 1`, e.g. `/privacy-policy`): served at `/{slug}` by `page.php` (the router maps
  `/{slug}` → `page.php` when no `{slug}.php` exists). SEO on the **page** row.
- **custom pages** created by editors: `/page/{slug}` → `page.php` (keeps them clear of future routes). SEO on the page row.

Seeded system pages: `about vision-mission leadership research campus-life privacy-policy terms-and-conditions
refund-policy accessibility` (content pages) and `programs admissions academics campus placement alumni faculty
contact` (route pages — hero copy + intro for their templates); `database/seed/05_cms_core.php` upserts exactly these
slugs (ids stay stable: About GIMT = 1) and never touches other pages such as the policy pages of `08_policies.php`.

**Policy pages** (`database/seed/08_policies.php`, core seed): the `/policies` hub ("Policies & Disclosures") plus
`disclaimer cookie-policy refund-and-cancellation-policy admission-policy scholarship-policy fee-policy
examination-policy code-of-conduct anti-ragging-policy grievance-redressal-policy internal-complaints-committee
equal-opportunity-policy mandatory-disclosure`. `page.php` renders them with the editor-stated "Last updated" line, a
table of contents built from the `h2`s (scroll-spy), a print button and **highlighted `[To be confirmed by GIMT: …]`
notes** — every legal or statutory detail GIMT still has to verify is written that way; replace each note with the
verified text in CMS › Pages. The seeder only updates pages it created and that no editor has changed since
(`pages.updated_by`), so completed pages are never overwritten. `/refund-policy` redirects to
`/refund-and-cancellation-policy` when no published `refund-policy` page exists. The Leadership page (`leadership`)
holds `person` cards marked "(placeholder)" until GIMT enters the real names, photos and profiles; `/about` reads the
same cards.
A NEW page may not take a reserved slug (`cms_reserved_slugs()`: router prefixes such as `admin api assets page blog
events notices gallery programs portal search`, every top-level folder and every slug that already has its own PHP
template or public route, e.g. `apply` or `faq`) — the API answers 422.

### 2.1 Block envelope
```json
{"id":"b7k2m9","type":"cards","hidden":false,
 "settings":{"anchor":"values","background":"light","padding":"normal"},
 "data":{ …type-specific… }}
```
`id` `[a-z0-9_-]{1,40}` (unique within the page) · `settings.anchor` `[a-z0-9-]` · `settings.background`
`white|light|cream|sand|dark` · `settings.padding` `normal|tight|none`. Max 60 blocks per page. Readers must also
accept the flat legacy form `{type, …fields}` (see `sa_page_blocks()`).

### 2.2 Block types (`data`)
| type | data |
|---|---|
| `hero` | `eyebrow` · `title` **req** ≤190 · `highlight` ≤120 · `text` ≤600 · `image` · `image_alt` · `align` `left\|center` · `size` `md\|lg` · `primary_cta` link · `secondary_cta` link · `chips` [{`icon`,`label`}] ≤6 |
| `text` | `heading` ≤190 · `html` **req** (rich text) · `align` `left\|center` · `width` `narrow\|normal\|wide` |
| `image` | `image` **req** · `alt` **req** ≤190 · `caption` ≤255 · `width` `normal\|wide\|full` · `link` url |
| `video` | `url` **req** (YouTube / Vimeo / `.mp4` / `.webm`) · `title` · `poster` image · `caption` · `aspect` `16:9\|4:3\|1:1` → VideoLightbox |
| `cards` | `eyebrow` · `heading` · `subheading` · `style` `icon\|image\|person\|pastel` · `columns` `2\|3\|4` · `items` [{`icon`, `image`, `title` **req** ≤120, `subtitle` ≤120 (role for `person`), `text` ≤500, `url`, `link_label`, `tint`}] 1–24. `person` cards (name = title, role = subtitle, bio = text, photo = image) are what `sa_page_people()` reads for leadership |
| `statistics` | `eyebrow` · `heading` · `subheading` · `variant` `cards\|inline\|band` · `items` stat[] 1–8 → `stat_counter()` |
| `testimonials` | `eyebrow` · `heading` · `subheading` · `source` `auto` (from `testimonials`) \| `manual` · `types` (auto) · `limit` (auto) · `items` [{`name` **req**, `quote` **req** ≤600, `role`, `photo`, `rating` 1–5}] (manual) |
| `faq` | `eyebrow` · `heading` · `subheading` · `source` `auto` (from `faqs`) \| `manual` · `category` (auto, empty = all) · `limit` (auto) · `columns` `1\|2` · `items` [{`q` **req** ≤300, `a` **req** ≤2000 paragraphs}] (manual) |
| `cta` | `eyebrow` · `title` **req** · `text` ≤400 · `primary` link · `secondary` link · `image` · `theme` `dark\|orange\|light` |
| `gallery` | `eyebrow` · `heading` · `subheading` · `source` `manual\|gallery` · `gallery_id` (from `galleries`; items from `gallery_items`) · `layout` `grid\|masonry\|bento` · `columns` `2\|3\|4` · `items` [{`image` **req**, `alt`, `caption`}] (manual) → GalleryLightbox |
| `course_listing` | `eyebrow` · `heading` · `subheading` · `mode` `all\|featured\|level\|department\|selected` · `level` `UG\|PG\|PhD\|Diploma\|Certificate` · `department_id` · `program_ids` int[] · `limit` 1–24 · `show_filters` bool · `cta` link → `program_card()` / ProgramExplorer |
| `faculty_listing` | `eyebrow` · `heading` · `subheading` · `department_id` (empty = all) · `limit` 1–48 · `show_qualification` bool · `show_bio` bool · `cta` link. Faculty: `status='active' AND show_on_website=1` |
| `placement_section` | `eyebrow` · `heading` · `subheading` · `stats` stat[] ≤4 · `show_recruiters` bool + `recruiters_limit` · `show_offers` bool + `offers_limit` · `cta` link |

Conditional fields: `testimonials.items`, `faq.items`, `gallery.items` are required only with `source = manual`;
`gallery.gallery_id` with `source = gallery`; `course_listing.level` / `department_id` / `program_ids` with the
matching `mode`. The admin hides fields that do not apply (`when` in the schema).

Rendering helper for the website team: `cms_page_blocks($page)` (in `app/services/cms.php`) returns the visible blocks
already normalised (`{id, type, settings, data}`), so `page.php` only needs a `switch ($block['type'])`.

---------------------------------------------------------------------------------------------------------------------
## 3. Menus — tables `menus` + `menu_items`

One menu per **location** (`menus.location` unique, `status` active/inactive). Items: `title`, `url` (always filled —
when an item is linked to a CMS page via `page_id` the API writes that page's URL into `url`), `target`
(`_self|_blank`), `icon`, `description` (shown in dropdown/mega panels), `is_mega`, `sort_order`, `status`.
Read them with `site_menu($location)` (active items only, tree with `children`).

| location | Used for | Max depth |
|---|---|---|
| `header` | main navigation (desktop bar + side drawer). Top-level items with children open a dropdown; `is_mega=1` opens the mega menu. A mega item **without children** is auto-filled by the website from `programs` (program cards + "By Level / By Department" filters — the recommended setup); with children, the children are **columns** (headings) and the grandchildren the links | 3 (top → column → link) |
| `footer_quick` | footer column "Institute" | 1 |
| `footer_academics` | footer column "Programs" | 1 |
| `footer_admissions` | footer column "Admissions" | 1 |
| `footer_support` | footer column "Student Corner" | 1 |
| `legal` | footer legal bar (Privacy, Terms, Refund, Accessibility) | 1 |
| `topbar` | optional login links for the top bar (`/portal/student/login`, `/portal/faculty/login`, `/admin/login`); the current header hard-codes the same links, so it is seeded inactive until the website reads it | 1 |
| `mobile` | optional override for the side drawer (falls back to `header` when empty/inactive; seeded empty) | 2 |

Seeded links are exactly the fallbacks of `includes/navbar.php` / `includes/footer.php` (`cms_menu_fallback()`), so
the website looks the same with or without CMS data. Admin › Menus › "Programs menu" switches the Programs mega menu
between the automatic cards (`cms_sync_program_menu('auto')`, no children) and editable Undergraduate / Postgraduate /
Diploma & Certificate columns (`cms_sync_program_menu('columns')`).

---------------------------------------------------------------------------------------------------------------------
## 4. Banners / sliders — table `banners`

Columns: `title` (heading), `highlight` (second line in accent colour), `eyebrow` (small label), `subheading`,
`desktop_image` (≥1600×800), `mobile_image` (≥800×1000, optional), `image_alt`, `cta_text`/`cta_url`,
`cta2_text`/`cta2_url`, `placement`, `sort_order`, `starts_at`/`ends_at` (schedule, NULL = open), `status`
(`active|inactive`). Live = `status='active'` and inside the schedule — `site_banners($placement)` already filters.

| placement | Rendered as |
|---|---|
| `home_hero` | the **spotlight carousel card** of the home hero (when `hero.use_banners`): eyebrow, title, thumbnail (`mobile_image` or `desktop_image`) and the first button. Rules (includes/partials/home.php, mirrored by the admin preview `spotlightSlides()`): live banners in `sort_order`, max 4; a banner needs a button (`cta_text`+`cta_url` or `cta2_text`+`cta2_url`); a banner whose title equals the hero headline is skipped |
| `page_header` | wide promotional card under the page hero of Programs, Admissions, Academics, Campus, Placements, Faculty, Alumni and About (`site_page_promo()`, printed by `page_hero()`; page option `'promo' => true\|false` overrides): first live banner with a title — image, eyebrow (gold pill), title + highlight, sub-heading, up to 2 buttons |
| `popup` | notice popup on the **homepage** (`notice_modal()` in the footer): first live banner with a title — image \| text card, eyebrow, title + highlight, sub-heading, buttons. Once per browser session per banner (editing it shows it again), 3 s after load (phones: after 35 % scroll or 20 s), never on top of another dialog, the cookie banner or the chat; on the homepage the enquiry popup goes first and the notice waits for a later page view; `?popup=notice` previews it |
| `strip` | slim navy promo bar on the homepage after the Programs section (after the first section when Programs is off) (`site_promo_strip()`): round photo, gold eyebrow pill, title + highlight, sub-heading, 1–2 buttons |

Images are **paths** chosen with the CMS image picker (bundled campus photos, the media library or a new upload that is
registered in the media library), validated by `cms_valid_image()`; deleting a banner never deletes a shared file.
Website support: **all four placements are rendered** (helpers in `includes/components.php`, "CMS placements"). Each
returns nothing when no banner is live, so pages look exactly as before without CMS data. Popups never appear on
`/apply`, `/contact`, campaign landing pages (minimal header) or error pages.

---------------------------------------------------------------------------------------------------------------------
## 5. Announcements — table `announcements`

Columns: `title` (≤255, the message), `content` (≤500, longer text for popups), `link` url + `link_text`, `type`,
`starts_at`/`ends_at`, `sort_order`, `status`. `site_announcements($type)` returns the live ones.

| type | Rendered as |
|---|---|
| `topbar` | items of the scrolling announcement strip above the header on every page (`site_announcement_items()` in `includes/config.php`: title + optional link; `topbar` items first) |
| `ticker` | news-ticker items in the same strip, after the `topbar` items (max 8 items in total). With no live `topbar` / `ticker` item the strip shows the setting `announcement_strip_text` ("A \| B \| C"), else the admissions-open line. The strip shows when `show_topbar` = 1 (System Settings › Website › Header). Campaign landing pages use their own strip text (setting `landing_strip_text`) |
| `popup` | notice popup (`notice_modal()`): title + `content` + `link` / `link_text` button under a gold header. Shown on every page except `/apply`, `/contact`, campaign landings and error pages; on the homepage only when no `popup` banner is live (§4). Same rules as the banner popup: once per browser session per announcement, 3 s after load (phones: 35 % scroll or 20 s), never over another dialog |
| `banner` | dismissible gold alert bar above the header on every page (`site_alert_bars()` in `includes/header.php`; closures, deadlines): title + `link` / `link_text`; max 2 live bars; ✕ hides a bar in that browser until its text or link changes |

Website support: **all four types are rendered**. `starts_at` / `ends_at` schedule them; only `status = active` rows
inside the schedule appear.

---------------------------------------------------------------------------------------------------------------------
## 6. SEO — table `seo_settings` + settings

| entity_type | entity | Public URL | site_header() reference |
|---|---|---|---|
| `page` | `pages.id` | `/{slug}` (system pages) or `/page/{slug}` (custom pages) — `cms_page_url()` | `['seo' => ['page', $id]]` |
| `route` | `route` = path (`/`, `/about`, `/programs` …) | the path | `['seo' => ['route', '/about']]` |
| `program` | `programs.id` | `/programs/{slug}` | `['seo' => ['program', $id]]` |
| `post` | `blog_posts.id` | `/blog/{slug}` | `['seo' => ['post', $id]]` |
| `event` | `events.id` | `/events/{slug}` | `['seo' => ['event', $id]]` |
| `notice` | `notices.id` | `/notices/{slug}` | `['seo' => ['notice', $id]]` |

CMS pages served by a dedicated template (`about.php` …) keep their SEO on the **route** row (`/about`), so the page
editor and the route editor edit the same record (`cms_page_seo_ref()`).

Columns: `meta_title` (aim 30–60 chars), `meta_description` (aim 70–160), `meta_keywords`, `canonical_url` (absolute
URL or path), `og_title`, `og_description`, `og_image` (image path), `robots` (`index,follow | noindex,follow |
index,nofollow | noindex,nofollow`), `schema_json` (valid JSON-LD object/array, printed in a `<script
type="application/ld+json">`), `include_in_sitemap`, `sitemap_priority` (0.0–1.0), `sitemap_changefreq`
(`always hourly daily weekly monthly yearly never`). Fallbacks when a row/field is empty: title = entity title +
` | GIMT`, description = excerpt/overview, OG = entity image.

Known public routes (`cms_public_routes()`): `/ /about /programs /admissions /admissions-open /academics /campus
/placement /alumni /faculty /contact /blog /events /notices /gallery /faq /apply /verify-certificate` (a route is
listed in the sitemap once its template file exists).

**Sitemaps** — the URL set is `cms_sitemap_urls()`: `[{loc, path, type, title, lastmod, changefreq, priority}]` =
existing routes + published CMS pages + active programs + published posts + website events + website notices +
published galleries, minus anything `noindex` or `include_in_sitemap = 0`. The website serves it (`sitemap.php`,
`app/services/seo.php`) as a **sitemap index** at `/sitemap.xml` with child sitemaps `/sitemap-pages.xml`,
`-programs`, `-posts`, `-events`, `-notices`, `-galleries` and, when setting `sitemap_images` is on, `-images`
(cached in `storage/cache/seo`, rebuilt when content changes; "Rebuild" under SEO › Robots & sitemap).
**robots.txt** — served by `robots.php` from setting `robots_txt` (group `seo`, edited under SEO › Robots & sitemap);
when empty, the recommended defaults (`seo_default_robots_txt()`: allow the website, disallow `/admin/`, `/api/`,
`/portal/`, `/storage/`, `/search` and filtered / tracking query URLs, plus the `Sitemap:` line). When "Discourage search
engines" is on (setting `seo_block_indexing`, System Settings › Integrations › Search engines) it serves
`Disallow: /` instead — use that on staging copies only. `cms_sitemap_xml()` / `cms_robots_txt()` remain for the admin
preview and the download endpoints below; `cms_seo_files_served()` reports both files as served.

**Redirects** — Website CMS › Redirects (`/admin/redirects`, permission `seo`): 301 / 302 / 307 / 308 rules and 410
"Gone", with loop / chain / missing-target checks, a URL tester, hit counters, bulk activate / deactivate, CSV / Excel
export and CSV import, and a 404 report (human vs bot hits, suggestions). Unknown URLs reach `404.php`, which applies
the rules first, then the lower-case / no-trailing-slash URL when it exists, and logs the 404 otherwise.

---------------------------------------------------------------------------------------------------------------------
## 7. Admin API (for reference)

`/api/crud/{pages|menus|menu_items|banners|homepage_sections|announcements|seo_settings}` (generic CRUD) and:

| Endpoint | Purpose |
|---|---|
| `GET cms/overview` | KPIs, SEO score, recent content & activity |
| `GET cms/schema` | section + block definitions, icons, placements, types, stat sources (live values) |
| `GET cms/preview-data` | programs, departments, faculty, testimonials, FAQs, galleries, recruiters, offers, events/news/notices for previews |
| `GET/PUT cms/pages/{id}/builder` | load / save blocks (`{blocks, publish?}`) — validated against §2 |
| `POST cms/pages/{id}/duplicate`, `POST cms/pages/{id}/publish`, `POST cms/pages/{id}/unpublish` | page actions |
| `GET cms/homepage`, `PUT cms/homepage/{key}`, `POST cms/homepage/reorder`, `POST cms/homepage/{key}/toggle`, `POST cms/homepage/{key}/reset` | home sections |
| `POST cms/banners/reorder`, `POST cms/announcements/reorder` | drag order |
| `GET cms/media`, `POST cms/upload` | image picker (media library + bundled photos) / upload (registered in `media`) |
| `GET menus`, `GET menus/{location}`, `PUT menus/{location}` (`{name, status, items: tree}`), `GET menus/{location}/fallback`, `POST menus/header/sync-programs` (`{mode: auto\|columns}`) | menu tree editor |
| `GET seo/overview`, `GET seo/entries`, `GET/PUT/DELETE seo/entry`, `GET/PUT seo/robots`, `GET seo/sitemap` (+ `served` flags) | SEO |
| `GET seo/sitemap-xml`, `GET seo/robots-txt` | download the generated sitemap.xml / robots.txt (Search Console, manual publishing) |
| `/api/crud/redirects`, `GET redirects/summary`, `POST redirects/check`, `POST redirects/test`, `POST redirects/bulk`, `GET redirects/404s` | redirect manager and 404 report |
| `GET integrations/sitemaps`, `POST integrations/sitemaps/rebuild`, `GET/POST integrations/seo-audit…` | child sitemaps, cache rebuild, SEO audit crawler (SEO › Audit) |

Permissions: `cms` (view/create/edit/delete/**publish**/manage) for pages, menus, sections, banners, announcements;
publishing a page needs `cms.publish`; `seo` (view/edit/manage) for SEO and redirects; `blog` for blog / news and
categories; `media` for the media library and gallery. Every change is written to the activity log.

---------------------------------------------------------------------------------------------------------------------
## 8. Website settings that change what the CMS content looks like

Admin › System Settings (`/admin/settings`). These are settings, not CMS rows, but editors usually need them together.

| Setting (key) | Where | Effect |
|---|---|---|
| Default colour theme `site_theme_default` (system \| light \| dark), switch visibility `site_theme_toggle` | Website › Appearance | Light / Dark / System theme of the public site; visitors' own choice is remembered in their browser (cookie `gimt_theme`, localStorage `gimt.theme`) |
| Listing views `view_mode_default`, `view_mode_programs`, `view_mode_faculty`, `view_mode_notices`, `view_mode_events`, `view_mode_news` (grid \| list \| compact \| detailed) | Website › Appearance | default Grid / List / Compact / Detailed view of each listing (seeded: notices list, everything else grid); visitors' choice is remembered per listing |
| Enquiry popup `enquiry_modal_image`, `enquiry_popup_title` (words in `[brackets]` get the gold highlight), `enquiry_popup_ribbon` (`{session}` = admission session), `enquiry_popup_auto`, `enquiry_popup_delay` (3–60 s, default 8) | Website › Enquiry popup | the "Get Free Counselling" popup (Enquire Now tab, Request a Callback buttons) and its automatic opening on the homepage — once per visit, never for 30 days after the visitor sent an enquiry. Use a real high-resolution photo (ideally a transparent PNG cut-out, about 600 × 700 px) |
| `show_topbar`, `header_cta_text` / `header_cta_url`, `whatsapp_number` | Website › Header | announcement strip on / off (§5), header button, WhatsApp buttons |
| `footer_about`, `copyright_text` (`{year}`) | Website › Footer | footer texts |
| `maintenance_mode` | Website › Maintenance mode | maintenance page for visitors; the admin panel stays available |
| `affiliation` | General › Institute information | approvals line on the website, prints and chatbot answers (currently demo text — GIMT must confirm) |
| `approvals_list` (JSON `[{label, title, text, icon}]`), `announcement_strip_text`, `admissions_deadline`, `landing_*` (campaign landing copy, `landing_indexable`, `landing_show_seats`, `landing_strip_text`, `landing_scholarship_text`) | Website › Additional settings | approval cards, strip fallback text, application deadline countdown, campaign landing pages (`docs/WEBSITE.md` §11) |
| Chat assistant (`chatbot_*`) | Admin › AI Chatbot › Settings | launcher, greeting bubble, show on phones, providers (`docs/CHATBOT.md`) |

Gold highlights (`.hl-gold`, `.pill-gold`) are added only from data: popular / featured programs, notices that are pinned
or newer than `notice_new_days`, and the real maximum active scholarship ("Scholarships up to N%" — set the real
scholarships in Fees › Scholarships & Discounts, or override the text with `landing_scholarship_text`).
