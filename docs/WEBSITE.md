# GIMT website platform — React islands + CSS3 motion

Reference for building public pages (`/*.php`). Live examples of everything below: **`/site-kit.php`** (dev only,
noindex — copy from it). Client brief: `docs/REQUIREMENTS.md` → "Client update — website technology & motion".

## 0. Design v3 — visual system (public website only)

"Premium academic institution meets modern technology university" (client decision; docs/DESIGN.md → Design v3). The
layout patterns follow the Online Manipal reference (floating pill header, mega menu with sidebar filters + program
cards, pill tabs, round-arrow carousels, warm footer card, WhatsApp chat tab, ad landing structure) — **never copy their
logos, photos, text, university names, rankings or exact layouts**; all content/claims are GIMT's own and DB/settings
driven. The admin SPA (navy/green) and printed documents (navy/green, `assets/css/print.css`) are unaffected.

**Tokens** — CSS custom properties on `:root` (light map; a future dark map overrides them under
`[data-theme="dark"]`, components use `var(--c-*)` in plain CSS) mirrored as Tailwind tokens (`tailwind.config.cjs`):

| Token | Value | Use | Tailwind |
|---|---|---|---|
| `--c-navy` / `--c-navy-deep` | `#0B2A5B` / `#071A3B` | headings, dark bands, active pills, footer accents (white text 14:1) | `brand-900` / `brand-950`, `navy`, `navy-deep` |
| `--c-flame` | `#F05A28` | vivid orange-red: decorative accents, large display text, icons, scribbles | `accent-500`, `flame` |
| `--c-flame-fill` / `-hover` / `-active` | `#D03E16` / `#B83511` / `#962B0F` | filled primary buttons (white text 4.79:1) | `accent-600/700/800` |
| `--c-flame-text` | `#C93C14` | orange-red text/links on white (5.07:1) — CSS uses `accent-700` for small text | `flame-text` |
| `--c-warm` / `--c-warm-2` / `--c-line` | `#F5F2EE` / `#EDE8E2` / `#E2DAD0` | page sections · cards/borders · hairlines | `warm`, `warm-2`, `warm-line` |
| `--c-body` / `--c-muted` | `#4A5568` / `#6B7280` | body text (6.7:1 on warm) | `body`, `muted` |
| text `slate-500` (light) | `#5B677B` (not Tailwind's `#64748b`) | small grey text: 5.7:1 on white, 5.1:1 on warm, 4.7:1 on warm-2 — `LIGHT_TEXT` in tailwind.config.cjs (text utilities only; `slate-400` stays for icons / separators — never small text). `placeholder:text-slate-400` is re-pointed to this token at the end of style.css (placeholders double as labels in compact forms) | `text-slate-500` |
| `--c-sun` | `#FDD900` | urgency strips only (countdown boxes) | `sun` |
| `--c-gold` / `--c-gold-deep` / `--c-gold-soft` / `--c-gold-ink` | `#FFC93C` / `#F5B300` / `#FFF3CF` / `#0B2A5B` | yellow-gold marker highlights + gold pills (client request 2026-10-13); text on gold is always navy `--c-gold-ink` (≈ 10:1, also in the dark theme) | `gold`, `gold-deep`, `gold-soft` |
| `--c-whatsapp` / `-hover` | `#25D366` / `#1EBE57` | WhatsApp controls only (official green) | `whatsapp` |
| semantic | `--c-surface --c-surface-warm --c-text --c-heading --c-border` | aliases for the future dark map | — |

**Colour map (accents only)** — one hue per program level and per department, defined once in `site_hues()`
(`includes/config.php`), `.hue-*` classes + `--hue-*` properties (style.css) and `frontend/site/lib/hues.ts`. Each `.hue-x`
sets `--hue` (strong, ≥ 4.5:1 on white and with white text), `--tint` (pastel surface) and `--ink` (text on the tint, ≥ 7:1).

| Key | Meaning | `--hue` | `--tint` |
|---|---|---|---|
| `ug` · `pg` · `diploma` · `cert` | Undergraduate (sky) · Postgraduate (violet) · Diploma (teal) · Certificate (amber) | `#0369A1` · `#6D28D9` · `#0F766E` · `#B45309` | `#E0F2FE` · `#EDE9FE` · `#CCFBF1` · `#FEF3C7` |
| `mgt` · `tech` · `ca` | Management (dept MGT, brand orange-red) · Technology (CSE, blue) · Computer Applications (DCA, purple) | `#D03E16` · `#1D4ED8` · `#7E22CE` | `#FDEDE7` · `#DBEAFE` · `#F3E8FF` |
| `com` · `sci` · `skill` · `other` · `all` | Commerce (COM, emerald) · Science/Data (SCI, cyan) · Professional & Skill (PSD, rose) · fallback (indigo) · "All" (navy) | `#047857` · `#0E7490` · `#BE123C` · `#4338CA` · `#0B2A5B` | `#D1FAE5` · `#CFFAFE` · `#FFE4E6` · `#E0E7FF` · `#EFF4FB` |

PHP: `level_hue('PG')` → `hue-pg`, `dept_hue($programRow)` (department code → category → other), `program_tags($p)` (real
DB flags only: `is_popular` → Most Popular, `is_featured` → Featured). Classes: `.level-chip`, `.hue-chip`, `.dept-chip`,
`.hue-dot`, `.tag-pill .tag-popular|.tag-featured`. TS: `levelHue()`, `deptHue()`, `HUES`. Tailwind: `hue-{key}`,
`hue-{key}-tint`, `hue-{key}-ink` (e.g. `bg-hue-pg-tint text-hue-pg-ink`) — literal class names only (no string building).

**Typography** — sans-serif only: headings Plus Jakarta Sans 700/800 (`font-display`, tight tracking `tracking-display`,
fluid `clamp()` sizes in `.section-title`, `.hero-title`, `.page-hero-title`), body Inter; stat numbers bold Jakarta.
Both self-hosted (`assets/fonts`), Jakarta 800 + Inter 400 preloaded. **No font-swap layout shift:** each family is
followed by a metric-matched local fallback (`"Inter Fallback"`, `"Plus Jakarta Sans Fallback"` @font-face in style.css:
Arial / Helvetica / Liberation Sans with `size-adjust` + `ascent-override` / `descent-override` measured in Chromium —
`tools/.cache/shots/design_polish/font-metrics.cjs`; stacks in `tailwind.config.cjs` → `fontFamily`).

**CTA button system** (`button_link()` variants / classes, 180–300 ms, lift + arrow nudge, focus ring, pressed, `.is-loading`, `:disabled`):
"Apply for Admission" = `apply` (filled orange-red) · "Explore Courses" = `explore` (navy filled) · "Download Brochure" =
`brochure` (navy outline) · "Request a Callback" = `callback` (soft orange tint, opens `#enquiry-modal`). Aliases kept for
older pages: `accent|orange` = apply, `primary|navy|ink` = explore, `outline` = brochure, `soft` = callback; plus
`secondary`, `white`, `outline-light` (on navy), `ghost`.

**Identity devices** — layered photo compositions (arch-cut frame + inset photo + gradient ring + glass badge), floating
2D shapes `geo_shapes()`, slow morphing SVG `blob()`, mesh/radial lighting `.bg-mesh-navy` `.bg-mesh-warm`, faint grids,
`.grain`, `.gradient-border`, `.accent-line`, `.glass-panel` (only on floating panels, solid `@supports` fallback),
hand-drawn orange-red `scribble()` ellipse around one word, sticker tags. Motion only on important elements.

**Yellow-gold highlights** (client request 2026-10-13, docs/DESIGN.md) — a warm marker behind selected key words next to
navy + orange-red. Rules: max ~1–2 per screen, never on body paragraphs, one per heading and never together with a
scribble on the same heading; text on gold is always navy. Where it is used: homepage hero key word (`hm_gold()`: CMS
content key `gold`, default the last word of the title), "The Admission Journey" heading, scholarship panel figure (real
maximum from the `scholarships` table), closing CTA phrase (`cta_band` / footer default "Brighter Future"), program
"Most Popular" / "Featured" tags (DB flags), notice "New" (last `notice_new_days`) / "Important" (pinned) labels,
/admissions hero (session) + first scholarship value, /admissions-open hero pill phrase, scholarship figure ("up to N%"
found in the real scholarship line) and the deadline strip, the enquiry popup heading.

| Variant | PHP | React (`frontend/site/lib/GoldMark.tsx`) | Markup / CSS |
|---|---|---|---|
| marker | `gold_highlight('Free Counselling')`, `gold_text($text, $phrase)` (`$phrase` null = the `[bracketed]` part), `section_heading(…, ['gold' => 'Journey'])`, `page_hero(…, ['gold' => '2026-27'])`, `cta_band(['gold' => …])`, `site_footer(['cta_gold' => …])`, `hero_slider` slide `gold` | `<GoldMark text="…" />`, `goldText(text, phrase)`, HeroSlider slide `gold` | `<span class="hl-gold" data-draw><span class="hl-gold-w">Free</span> <span class="hl-gold-w" style="--w:1">Counselling</span></span>` — one skewed, rounded band per word (`::before`, lower ~60 % of the line; the phrase can wrap; inner bands overlap into the spaces); drawn in left → right on reveal (`transform` on the pseudo-element only; `initScribbles()` adds `.is-drawn`; static under reduced motion, in automated browsers, without JS and in print). `.hl-gold-tall` / `['tall' => true]` = full-height band — automatic on navy surfaces (`.section-navy`, `.on-dark`, `.cta-band-card`, `.bg-brand-900/950`, `.lp-hero`, `.enquiry-dark/-lead`) and in the dark theme, so navy text never sits on navy |
| pill | `gold_highlight('New', ['variant' => 'pill', 'icon' => …, 'size' => 'lg'])` | `<GoldMark variant="pill" />` | `.pill-gold` (`.pill-gold-lg`) — tags (`.program-ribbon`, `.tag-popular`, `.scholar-value`, `.pill-phrase` and `.enquiry-strip` are gold too) |
| soft | `gold_highlight('up to 50%', ['variant' => 'soft'])` | `<GoldMark variant="soft" />` | `.hl-gold-soft` — gold text + soft gold underline for navy bands (#FFC93C on navy ≈ 9.6:1) |

`.pill-gold`, `.hl-gold`, `.bg-gold` are light scopes (`LIGHT_SCOPES` in tailwind.config.cjs + the style.css dark block).

## 1. Architecture

```
PHP page (index.php, about.php …)                     ← real HTML for SEO + instant first paint
  site_header() → includes/header.php + navbar.php    ← <head>, loader, top bar, sticky header, mega menu, drawer, search
  page_hero() / section_open() / program_card() …     ← includes/config.php + includes/components.php
  island('HeroSlider', $props, $fallbackHtml)         ← <div data-island="HeroSlider" data-props="{…}">fallback</div>
  site_footer() → includes/footer.php                 ← CTA band, footer, back-to-top, bundle <script type=module>

frontend/site (TypeScript, Vite → assets/site)
  main.tsx              tiny entry (≈11 KB gzip, no React): boot()
  core/*.ts             vanilla enhancements on every page (header, menus, overlays, reveal, parallax, counters, …)
  core/islands.ts       finds [data-island], lazy-loads the component when it nears the viewport, mounts it
  runtime/mount.tsx     React side: createRoot + error boundary + height lock (no layout shift)
  islands/index.ts      registry: name → () => import('./Name')   (each island = its own chunk)
  islands/*.tsx         the React components;  lib/*  shared hooks, Icon map, Modal, CountUp, types
```

* React + ReactDOM live in one shared `react` chunk that is **only downloaded by pages that render an island**.
* Islands mount when within 200px of the viewport; `['eager' => true]` (hero) mounts immediately and its chunks are
  `modulepreload`ed by `site_assets_footer()`.
* Each island is error-isolated: if it throws, the server fallback is restored and the error is logged.
* Without JS (or if the bundle fails) every page still works: fallbacks are real content, menus open on
  `:hover/:focus-within`, `<details>` accordions, links for videos, plain-POST forms. If the bundle never boots within
  4s, an inline head script adds `html.no-motion` so nothing stays hidden.
* `assets/js/main.js` is the **no-bundle fallback** (loaded only when `assets/site/.vite/manifest.json` is missing).
  Keep its markup contracts in sync with `frontend/site/core/*` if you change them.

### Commands

```bash
cd frontend
npm run dev:site        # Vite dev server http://127.0.0.1:5174 (HMR); PHP pages use it automatically when
                        # config/local.php has app.site_dev_server = 'http://127.0.0.1:5174' and it is reachable
npm run build:site      # production bundle -> assets/site (manifest .vite/manifest.json); only touches assets/site
npm run typecheck:site  # tsc -p tsconfig.site.json --noEmit (separate from the admin `tsc -b`)
cd .. && bash tools/build-css.sh   # Tailwind -> assets/css/style.css (scans *.php, includes/, frontend/site/**)
```

* PHP picks the source per request (`site_assets_head()` / `site_assets_footer()`): dev server (only when
  `is_dev()` and the port answers) → built manifest → `assets/js/main.js` fallback.
* All styling is in `assets/css/src/style.css` (Tailwind + custom CSS). The site bundle imports no CSS. After adding
  Tailwind classes in PHP **or** in `frontend/site/**`, run `bash tools/build-css.sh`.
* `@-webkit-keyframes` live in `assets/css/src/keyframes-webkit.css` (Tailwind drops them) and are appended by
  `tools/build-css.sh`. Add a matching block there whenever you add a `@keyframes`.
* Never run the admin `npm run build`. `build:site` is safe (separate config, own `outDir`, own Vite cache dir).

## 2. PHP helpers

### Layout (`includes/config.php`)

| Helper | Notes |
|---|---|
| `site_header($page)` | keys: `title, description, active, image, seo, canonical, robots, schema, body_class, og_type`, `cursor` (bool, opt-in cursor ring; default setting `site_cursor`=0; never in automation/reduced motion), `motion` (false → `<html data-motion="off">`), `loader` (false → no first-visit loader), `preload` (image URLs or `['href', 'media']` — pass the LCP image), **Design v3:** `header` (`full` \| `minimal` = logo · phone · Apply Now for campaign pages), `header_cta` (`['label','url']`), `announce` (bool \| `"A \| B \| C"` — announcement ticker with pause button; default setting `show_topbar`), `admission_bar` (bool \| `admission_bar()` options; default setting `site_admission_bar`=0), `chat` (false hides the WhatsApp widget) |
| `site_footer($opts)` | `cta` (false hides the band), `cta_title, cta_text, cta_primary [label,url], cta_secondary [label,url,icon], cta_image`, `cta_highlight` (word to scribble), `cta_gold` (phrase in yellow-gold instead; the default title marks "Brighter Future"), `popup_auto` (bool: auto-open the enquiry popup on this page — default homepage only, setting `enquiry_popup_auto`), `footer` (`full` \| `minimal`), `directory` (bool, "All Programs & Specialisations" directory), `mobile_cta` (bool \| `['call','apply'=>[label,url],'whatsapp'=>bool]`; default setting `site_mobile_cta`=1, off on /apply), `enquiry_tab` (bool; default setting `site_enquiry_tab`=1, off on apply/contact/landing pages), `chat` (`tab` \| `button` \| `none`; default setting `site_chat_style`). Footer credit line from settings `footer_credit_text / footer_credit_name / footer_credit_url`. A guarded `includes/partials/chatbot.php` → `chatbot_widget()` include sits before `</body>` (bottom-left is reserved for it; offset with `var(--mobile-cta-h)`) |
| `page_hero($title, $subtitle, $breadcrumbs, $image, $text, $features, $opts)` | unchanged params. **Design v3** `$opts.variant`: `light` (default, setting `page_hero_variant`: warm mesh + grid, text-mask title with a scribbled word, layered photo composition: arch-cut frame with clip reveal, inset photo, gradient ring, glass badge, blob, geometric shapes) \| `dark` (navy mesh) \| `photo` (legacy full-bleed). Other opts: `highlight` (word to circle; default last word; false = none), `image2` (inset photo; false = none), `badge` (`['icon','text']`; default admissions_open_text; false = none), `parallax`, `pattern`, `glass`, `eyebrow`, `eyebrow_icon`, `script` (sticker tag), `actions` (HTML buttons — `outline-light` buttons render navy on the light hero), `size` (md\|lg), `align` (left\|center), `id`, `image_alt`, `gold` (phrase of the title with the yellow-gold marker instead of the scribble). `$image = ''` = text only (text-only / centred heroes use the `hero-text` shape preset: nothing near the breadcrumb row) |
| `section_heading($title, $lead, $eyebrow, $align, $actionHtml, $o)` | 6th param (optional): `highlight` (word to scribble), `gold` (word/phrase with the yellow-gold marker instead — never both), `sticker` (text \| `['text','color','rotate']`), `tag` (h2\|h1\|h3), `class`, `lead_class`, `scribble` (`orange`\|`light`\|`ink`), `reveal` (default true = text-mask line reveal) |
| `island($name, $props, $fallbackHtml, $attrs)` | raw island placeholder. `$attrs`: `tag` (div\|span\|section…), `eager` (bool), `class`, `id`, any attribute. Props are JSON (escape-safe). Children of `[data-slot="x"]` elements in the fallback arrive in the component as `slots.x` (`{html, data}[]`) |
| `site_assets_head()` / `site_assets_footer()` | used by header/footer — don't call in pages |

### Building blocks (`includes/components.php`)

| Helper | Output |
|---|---|
| `reveal_attr($effect = 'fade-up', $delayMs = 0)` | ` data-reveal="…" data-reveal-delay="…"` |
| `section_open(['id','bg' => white\|light\|surface\|cream\|sand\|paper\|ink\|navy\|gradient\|brand-soft,'pattern' => dots\|grid,'padding' => normal\|tight\|none,'class','container','label','reveal'])` … `section_close()` | `<section class="section …"><div class="container-site">` (light = warm grey `#F5F2EE`; ink/navy = navy band + grid, adds `.on-dark`; gradient = animated navy/ember gradient) |
| `button_link($label, $url, $variant, ['size','icon','icon_left','magnetic','shine','gleam','attention','class','target','attrs'])` | CTA system variants **`apply` `explore` `brochure` `callback`** (+ aliases `accent orange primary navy ink outline soft`, and `secondary white outline-light ghost`); apply/explore get a trailing arrow by default (`'icon' => ''` removes it); every `.btn` has the colour-overlay sweep; `shine` = light streak on hover (primary CTAs), `gleam` = shine + idle streak when it comes into view (a few key CTAs), `attention` = idle glow (main hero CTA only) |
| `program_card($programRow, ['reveal','delay','ribbon','heading','variant' => default\|landing,'summary','actions','apply_url','details_label'])` | white card, rounded photo with Most Popular/Featured tag (DB flags `is_popular` / `is_featured` only — the landing variant also labels featured programs "Featured", never "Trending") + level pill, department chip in the department hue, title, duration · level · fee meta (no ratings), Apply Now + Details, short-name tag in the department hue; restrained 3D tilt |
| `program_filter_tokens($p)`, `program_level_label($level)`, `program_icon($p)`, `program_categories()` | helpers for filtering / menus |
| `feature_tile($icon, $title, $text, ['tint' => navy\|orange\|sky\|aqua\|sun\|lavender\|blush\|pistachio\|warm,'variant' => card\|compact\|plain\|warm,'url','reveal','delay'])` | white card, tinted icon tile, bold title; gradient border on hover |
| `stat_card($value, $label, $icon, ['prefix','suffix','decimals','tint','dark','reveal'])` | number with vanilla `data-count` |
| `cta_band(['title','text','primary','secondary','image','eyebrow','compact','highlight','gold','shell'])` | navy mesh rounded card (grid, geometric shapes, blob, masked photo with width/height from `cta_band_image_size()` — no CLS) on the warm footer backdrop (the footer uses it; its default title marks "Brighter Future" in gold) |
| `gold_highlight($text, $o)`, `gold_text($text, $phrase, $o)` | yellow-gold marker / pill / soft highlight (§0) |
| `overlay_card($title, $text, $url, $image, $linkLabel, ['reveal','delay'])` | Campus Life / Placements / News image card |
| `check_list($items, $class)` | orange-red check bullet list |
| `dept_chip($p)`, `level_hue($level)`, `dept_hue($p)`, `program_tags($p)`, `site_hues()` | colour map helpers (see §0) |
| `scribble($word, $tone)`, `scribble_text($text, $highlight, $tone)` | hand-drawn orange-red ellipse around a word (draws in on reveal) |
| `geo_shapes('hero'\|'band'\|'card', $class)`, `blob($class, 'flame'\|'navy'\|'warm')`, `doodle($name)`, `emblem($name)` | floating 2D shapes, SMIL-morphing SVG blob, original SVG doodles (`assets/images/site/doodles`), generic approval emblems (seal, laurel, shield, pillars, globe, book, medal — never real logos) |
| `sticker($text, ['color','rotate','wiggle','size'])`, `ribbon_label($text)`, `pastel_card($title, $text, ['color' => warm\|navy\|sky…,'button','image','ribbon'])`, `icon_circle($icon, $tint)` | decorations |
| `approvals_card($items = site_approvals())`, `approval_card($a)` | approvals/affiliations (settings `approvals_list` JSON or `affiliation`) — white emblem card / ribbon card for carousels |
| `announcement_strip($items, ['links','speed'])`, `admission_bar(['text','cta'])`, `mobile_cta_bar([...])`, `whatsapp_button()`, `enquiry_tab()`, `enquiry_modal(['id','title','subtitle','ribbon','submit','source','program','auto'])`, `back_to_top()` | chrome / floating widgets (rendered by header/footer; usable anywhere). `enquiry_modal()` = the two-panel enquiry popup (§14) |
| `site_secure_form_attrs($form)`, `site_secure_form_fields()` | forms-platform protection for a plain `data-ajax-form` (footer / blog newsletter): `data-secure` + public captcha config on the `<form>`, hidden one-time token `_ft`, honeypot, captcha holder + reCAPTCHA notice. core/widgets.ts posts it through `forms/security.ts` (token, captcha answer, one retry) and fetches a fresh token after a success |
| `site_admission_session_name()`, `enquiry_popup_image()`, `program_tile_grid($n)`, `ef_phone_data()` | admission session label (cached) · popup photo (setting / bundled default, cut-out detection) · ProgramExplorer tile grid by tile count · cached phone regions for the form fallbacks (`storage/cache/site-phone-regions.php`, rebuilt when `app/data/phone_meta.json` changes — the 117 KB JSON is no longer decoded on every page that renders a form) |
| `view_mode_toggle($key, $modes, $default)`, `view_mode_open($key, [...])`, `view_mode_close()`, `view_mode_default($key)` | Grid / List / Compact / Detailed listing control (see §8) |
| Data: `site_programs()`, `site_program_departments()`, `site_program_levels()`, `site_program_courses()`, `site_approvals()`, `site_admissions_deadline()`, `site_seats_filled()`, `site_max_scholarship()`, `site_announcement_items()`, `site_whatsapp_url()`, `site_tel()`, `site_utm_params()` | DB/settings data with graceful fallbacks (cached per request) |
| `glass_chip($icon, $label, 'dark'\|'light'\|'white')` | chip for use over images |

### Island wrappers (fallback + island in one call)

All return HTML strings (`<?= … ?>`). Text props are plain text; URLs must be final (`site_url()`, `site_image()`,
`asset()`, `cms_link()` — the wrappers run `cms_link()` on CTA/viewAll URLs for you).

| Helper | Island | Fallback (SEO / no-JS) |
|---|---|---|
| `hero_slider($props)` | HeroSlider (eager) | first slide, same layout, real `<h1>` |
| `program_explorer($programs, $props)` | ProgramExplorer | pills wired to vanilla `data-filter` + every program card |
| `carousel($slidesHtml[], $props)` | Carousel | native scroll-snap track of the same cards |
| `testimonial_slider($items, $props)` | TestimonialSlider | all quotes in the DOM, first visible |
| `logo_marquee($items, $props)` | LogoMarquee | working CSS-only marquee |
| `stat_counter($items, $props)` | StatCounter | final numbers / rings / bars |
| `typing_text($phrases, $props)` | TypingText (inline span) | first phrase |
| `faq_accordion($items, $props)` | Accordion | `<details>` (vanilla height animation) |
| `content_tabs($tabs, $panelsHtml[], $props)` | Tabs | tab labels + first panel (others hidden, still in HTML) |
| `video_lightbox($props)` | VideoLightbox | link opened by the vanilla lightbox |
| `gallery_grid($items, $props)` | GalleryLightbox | thumbnail grid using the vanilla lightbox |
| `enquiry_form($props)` | EnquiryForm | identical `<form data-ajax-form>` with `_csrf` |
| `skeleton_list($props)` | SkeletonList | shimmer placeholders (+ `<noscript>` link) |
| `auto_scroll_cards($itemsHtml[], $props)` | AutoScrollCards | static scrollable list |
| `progress_steps($steps, $props)` | ProgressSteps | finished timeline (responsive) |
| `countdown($deadline = site_admissions_deadline(), ['label','variant' => inline\|boxes,'seconds'])` | Countdown | server-computed "Xd Yh Zm" — returns '' when there is no future deadline |

### Page author example

```php
<?php
require __DIR__ . '/includes/config.php';
$programs = db_all("SELECT * FROM programs WHERE status = 'active' AND show_on_website = 1 ORDER BY sort_order, name");
site_header(['title' => 'Academic Programs', 'active' => 'programs', 'seo' => ['route', '/programs'],
             'preload' => [asset('assets/images/site/students-laptop.jpg')]]);
page_hero('Academic Programs', 'Future-Ready Programs for a Brighter Tomorrow', ['Programs'],
    'assets/images/site/students-laptop.jpg', 'Explore our wide range of UG, PG, diploma and certificate programs.',
    [['icon' => 'briefcase-business', 'label' => 'Industry Relevant Curriculum'], ['icon' => 'users', 'label' => 'Experienced Faculty']],
    ['script' => 'Learn Grow Innovate Succeed']);
?>
<?= section_open(['id' => 'find', 'bg' => 'brand-soft']) ?>
  <?= section_heading('Find Your Program', 'Choose from our diverse range of programs.', 'Programs') ?>
  <?= program_explorer($programs, ['layout' => 'tiles', 'syncParam' => 'category']) ?>
<?= section_close() ?>
<?= section_open(['id' => 'counselling']) ?>
  <div class="mx-auto max-w-md"><?= enquiry_form(['endpoint' => base_url('api/public/enquiry'), 'theme' => 'dark', 'compact' => true,
      'title' => 'Need Help Choosing a Program?', 'submitLabel' => 'Get Free Counselling', 'hidden' => ['source' => 'programs'],
      'fields' => [['name' => 'name', 'label' => 'Full Name', 'required' => true], ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'tel', 'required' => true],
                   ['name' => 'email', 'label' => 'Email Address', 'type' => 'email', 'required' => true]]]) ?></div>
<?= section_close() ?>
<?= section_open(['bg' => 'light']) ?>
  <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-4" data-stagger="70">
    <?= feature_tile('users', 'Expert Faculty & Mentorship', 'PhD faculty and industry mentors.', ['tint' => 'green']) ?> …
  </div>
<?= section_close() ?>
<?php site_footer(['cta_title' => 'Start Your Academic Journey at GIMT']);
```

## 3. Islands and props

Every island also receives `slots` (from `[data-slot]` children in its fallback). All are keyboard accessible,
reduced-motion aware (no autoplay / count-up / typing; final state) and pause autoplay on hover, focus, hidden tab
and when off-screen.

| Island | Props |
|---|---|
| **HeroSlider** | `slides: {image, mobileImage?, alt?, eyebrow?, title, scribble? (word of the title to circle), gold? (word/phrase in the yellow-gold marker instead), highlight?, text?, cta?: {label,url}, cta2?}[]`, `sticker?` (text on the photo), `chips?: {icon,label}[]`, `stats?: {value, prefix?, suffix?, label, icon?}[]`, `video?: {url, label, sublabel?}`, `tagline?`, `taglineWords?: string[]`, `interval?` (ms, 6500; 0 = off), `theme?: 'light'\|'dark'`, `ariaLabel?`. Design v3 layout: copy left, arch-cut photo frame right with slot-rendered decorations (`backdrop`, `doodles` slots from the PHP fallback), white pill chips, stats row with dividers. Ken-Burns, progress bullets, play/pause, swipe, ←/→, ARIA carousel + live region |
| **Carousel** | `ariaLabel?`, `perView?: {base?, sm?, md?, lg?, xl?}` (default 1.15/2/3, fractions = peek), `gap?` (px, 24), `autoplay?` (ms), `loop?` (true), `arrows?` (round arrows centred below), `dots?` (default false), `theme?: 'light'\|'dark'`, `items?: {title, text?, image?, url?, meta?, badge?}[]` (used when no `slides` slot). Native touch swipe, mouse drag, keys |
| **TestimonialSlider** | `items: {name, quote, role?, company?, program?, photo?, meta?, quoteMarks? (false for third-person bios)}[]`, `autoplay?` (7000), `theme?`, `ariaLabel?`, `perView?: {base, sm, lg, xl}` — photo (or pastel initials) story cards in a scroll-snap track with round arrows |
| **LogoMarquee** | `items: {name, logo?, url?, industry?}[]` (no logo → wordmark tile), `rows?: 1\|2` (2 when ≥ 8), `speed?` (px/s, 45), `grayscale?` (true), `ariaLabel?`, `label?` (heading inside the warm card strip) |
| **ProgramExplorer** | `categories: {key,label,icon?}[]` (default `program_categories()`; `all` shows everything), `search?` (true), `searchPlaceholder?`, `emptyText?`, `initial?`, `syncParam?` (query-string key of the category), `searchParam?` (query-string key of the search term, e.g. `q`: `/programs?q=mba` opens filtered and typing keeps it in the URL — on /programs), `layout?: 'pills'\|'tiles'`, `gridClass?`, `tileClass?` (tile grid; default `TILE_GRID` / `program_tile_grid()` by count — 8 tiles share one row only from xl), `itemLabel?: {one, many}` (live count + "Show all …" wording, default program/programs), `viewAll?: {label,url}`. Slot `items`: children with `data-category="ug management"`, `data-search`, `data-key` |
| **StatCounter** | `items: {value, label, prefix?, suffix?, decimals?, icon?, progress? (0-100), caption?}[]`, `variant?: 'cards'\|'rings'\|'bars'\|'inline'\|'divided'` (divided = big numbers with thin dividers, for navy bands with `theme: 'dark'`), `theme?`, `gridClass?` |
| **TypingText** | `phrases: string[]`, `prefix?`, `suffix?`, `typeSpeed?` (65), `deleteSpeed?` (35), `pause?` (1800), `loop?`, `dots?` (typing indicator between phrases), `textClass?` (e.g. `text-gradient`) |
| **Accordion** | `items: {q, a, category?}[]` (blank line in `a` = new paragraph), `mode?: 'single'\|'multi'`, `defaultOpen?` (0; -1 none), `search?`, `searchPlaceholder?`, `categories?`, `columns?: 1\|2`, `theme?` |
| **Tabs** | `tabs: {key,label,icon?}[]`, `variant?: 'pills'\|'underline'`, `initial?`, `syncHash?`, `center?`. Slot `panels` (same order) |
| **VideoLightbox** | `url` (YouTube/Vimeo/mp4/webm), `title?`, `label?`, `sublabel?`, `poster?`, `variant?: 'thumb'\|'button'\|'chip'`, `aspect?`, `className?` |
| **GalleryLightbox** | `items: {src, thumb?, alt, caption?, category?}[]`, `columns?: 2\|3\|4`, `layout?: 'grid'\|'masonry'\|'bento'`, `filter?`, `ariaLabel?`. Lightbox: arrows, keys, swipe, click-to-zoom with panning, thumbnails |
| **EnquiryForm** | `endpoint` (POST, gets FormData + `X-CSRF-Token`; expects `api_ok/api_error` JSON), `fields: {name, label, type?, required?, placeholder?, options?, col?: 'full'\|'half', minLength?, maxLength?, min?, max?, pattern?, patternMessage?, rows?, autocomplete?, help?, value?}[]`, `title?`, `subtitle?`, `icon?`, `submitLabel?`, `successTitle?`, `successMessage?`, `hidden?: Record<string,string>`, `consent?` (adds required `consent` checkbox), `theme?: 'light'\|'dark'\|'glass'\|'lead'\|'plain'`, `compact?` (labels become placeholders), `titleHighlight?` (part of the title in orange-red), `tags?: {icon,label}[]` (lead card feature tags), `captureUtm?` (utm_* / gclid / fbclid → hidden fields), field `prefix?` (e.g. `+91`). PHP-only: `footer` (HTML strip attached under the card — the yellow countdown/seats strip), `id`. Honeypot field `website` is always sent — reject non-empty values server-side. Client messages mirror `validate()` (`required`, `email`, `phone`, `min`, `max`, `regex`). **Forms platform props** (set by `enquiry_form()`): field types `country` (searchable country select, submits the name + `<name>_code`), `state` (Indian states & UTs when the country is India, else free text), `postal` (validated per country; both follow `countryField`, default `country`), tel `country` (ISO2 preselected; E.164 sent, `<name>_country` carries the ISO2); `resetLabel` (success-state button; default per form: "Register another person" for events, "Send another message" for contact …), `formKey` (contact\|enquiry\|event\|apply\|alumni — analytics + captcha action), `formToken` (one-time `_ft`; fetched from `GET /api/public/form-token` when absent), `captcha` (`{provider, siteKey}` \| null), `initial` (`{ok, message, errors, values}` — result of a no-JS post), `fid` (fallback form id: values typed before the island mounted are kept), `consentError` (unticked-consent message; default = the server wording for the form). Theme `plain` = no card chrome (used inside the enquiry popup) |
| **SkeletonList** | `endpoint?` (GET JSON: `data` = items[] or `{items: []}` with `{title, url?, image?, date?, excerpt?, category?, location?}`), `count?`, `variant?: 'card'\|'list'\|'event'`, `gridClass?`, `emptyText?`, `viewAll?` |
| **AutoScrollCards** | `direction?: 'vertical'\|'horizontal'`, `speed?` (px/s, 28), `height?` (px, 420, vertical), `ariaLabel?`. Slot `items`. Pause button (WCAG 2.2.2) |
| **ProgressSteps** | `steps: {title, text?, icon?}[]`, `orientation?: 'auto'\|'horizontal'\|'vertical'`, `cta?: {label,url}`, `theme?` |
| **Countdown** | `deadline` (ISO), `label?`, `variant?: 'inline'\|'boxes'`, `seconds?`. Renders nothing once the deadline has passed; ticks every 30 s (1 s with seconds), pauses on hidden tabs |
| **ApplyWizard** | `endpoint` (multipart POST, `/api/public/apply`), `session`, `programs: {id, name, short, slug, level, levelLabel, category, duration, fee, eligibility, image, seats, popular, admissionFee, courses: {id,name}[]}[]`, `initialProgram?` (id), `options: {genders, categories, quotas, qualifications: {value,label}[], bloodGroups, relations, states: string[]}`, `documents: {key, label, hint, when: 'always'\|'pg'\|'category'\|'optional', image, max}[]`, `limits: {total, minYear, maxYear, maxPassingYear}`, `help: {phone, email, whatsapp?, hours?}`, `links: {admissions, programs, privacy, terms, classic, contact}` (privacy / terms are null until those pages exist — plain text then), `storageKey?`. Build props with `sf_apply_props()` (includes/partials/site_forms.php). 7 steps with per-step validation mirroring the server, draft autosave (localStorage, text only), file previews + size checks, review with edit links, upload progress, success screen with application number + acknowledgement link |

Icons passed as props use lucide kebab names from the curated map in `frontend/site/lib/Icon.tsx` (same names as the
PHP `icon()` helper). Add an entry there when you need a new one (unknown names render a sparkle).

### Public forms & online application (unit site_forms)

* **API** (`api/routes/public.php`, logic in `app/services/public_forms.php`, all `['auth' => false]`):
  `GET /api/public/form-token` (`{token, csrf, captcha: {provider, siteKey} | null, minSeconds}` — one-time token for islands /
  enhanced forms; single-use, must be at least `minSeconds` old) · `GET /api/public/programs` · `POST /api/public/contact` (name, email, phone, enquiry_type, message, consent) ·
  `POST /api/public/enquiry` (name, phone, email?, program_id | program (id/slug/name), city?, message?, source?, form?) ·
  `POST /api/public/newsletter` (email, name?) · `POST /api/public/event-registration` (event_id, name, email, phone?, organization?) ·
  `POST /api/public/apply` (multipart: application fields + `doc_photo`, `doc_10th_marksheet`, `doc_12th_marksheet`, `doc_graduation`, `doc_id_proof`, `doc_caste_certificate`).
  CSRF header/field, honeypot `website`, per-IP `rate_limit()` (429 + `Retry-After`), 422 field errors, `notify()` + `log_activity()`,
  acknowledgement email only when the template exists (`admission_received`, `newsletter_welcome`; `contact_received`, `enquiry_received`,
  `event_registration` are used automatically once created in Email Templates). Plain (no-JS) posts get a 303 back to the page with the
  result in the session when that page renders it (`sf_flash_notice('contact'|'enquiry')` on /contact and /admissions, see
  `pf_flash_pages()`); from any other page (footer newsletter, other units' forms) they get a small standalone result page with a link
  back. Bodies over php.ini `post_max_size` get 413 (the wizard's total-size limit follows `post_max_size`).
* **Server-rendered fallbacks that are not `enquiry_form()`** carry the same protection by hand: `_csrf`, the one-time
  token `_ft` (`pf_form_token()`), honeypot `website`, and `_fid` + `_back` when the page shows the no-JS result inline
  (`pf_take_flash($form, $fid)`). Example: the homepage SmartEnquiry fallback (`hm_render_smart_enquiry()`,
  `_fid=home-smart-enquiry`, back to `/#enquiry` with field errors under each field and the typed values refilled).
  Vanilla `data-ajax-form`s use `site_secure_form_attrs()` / `site_secure_form_fields()` (footer + blog newsletter).
* **Contact enquiry types** (`pf_contact_type_options()`): admission, program, scholarship, campus_visit, placement, student_support, general —
  link to `/contact?type=<key>#message` to preselect one.
* **Apply links**: `/apply?program=<slug|id>` preselects a program; `/apply?classic=1` is the single-page form (also the no-JS fallback);
  `/apply?ack=<application_no>&t=<token>` is the printable acknowledgement (`pf_ack_url($admission)`).
* **Admissions anchors**: `/admissions#process #eligibility #fees #scholarships #documents #dates #faq #enquiry`.

### Adding an island

1. `frontend/site/islands/MyWidget.tsx` — `export default function MyWidget(props: MyWidgetProps)`; type the props
   (`extends IslandBaseProps`), honour `useStaticMode()`, animate only transform/opacity, keyboard + ARIA.
2. Register it in `frontend/site/islands/index.ts` (`MyWidget: () => import('./MyWidget')`).
3. Render from PHP with a meaningful, same-sized fallback: `echo island('MyWidget', $props, $fallbackHtml);`
   (or add a wrapper to `includes/components.php`).
4. `npm run typecheck:site`, `bash tools/build-css.sh`, document the props here, demo it in `site-kit.php`.

Rules: islands render their own animations (don't put `data-reveal` on React-owned elements — React re-renders would
drop `is-visible`; slot HTML is fine, the runtime re-runs the enhancements on it). Use `useStaticMode()` for
reduced motion. Keep large static data out of components (pass it as props from PHP).

## 4. Data attributes (vanilla TS, every page — also work inside slot HTML)

| Attribute | Behaviour |
|---|---|
| `data-reveal="fade-up\|fade-in\|fade-down\|zoom-in\|slide-left\|slide-right\|blur-in"` + `data-reveal-delay="150"` | scroll reveal (`.is-visible`); legacy `.reveal` class still works |
| `data-stagger="80"` (+ `data-stagger-effect`, `data-reveal-delay` base) | children reveal one after another |
| `data-parallax="0.15"` | moves with scroll (desktop fine pointer only); with `.parallax-media` it stays inside its frame |
| `data-magnetic` / `data-magnetic="0.3"` | element leans towards the pointer |
| `data-tilt="6"` | 3D tilt towards the pointer |
| `.btn` / `data-ripple` (opt-out `data-no-ripple`) | ripple from the click point |
| `data-count="1248" data-prefix="₹" data-suffix="+" data-decimals="1" data-duration="1600"` | count-up (render the final value server-side) |
| `data-progress="72"` inside `.progress-track` | bar fills when visible |
| `img loading="lazy"` / `data-lazy` / `data-src` | fade + settle when loaded (`data-src` swapped near viewport) |
| `data-accordion="single\|multi"` around `<details>` / `details[data-animate]` | animated height, single-open groups |
| `data-filter="key" data-filter-group="g"` + `data-filter-item="a b" data-filter-group="g"` (+ `data-filter-count`, `data-filter-empty`) | chip filtering with re-entry animation |
| `data-slider` + `data-slider-track` / `-prev` / `-next` (+ `data-autoplay="5000"`) | simple scroll-snap slider |
| `data-hero` + `data-slide` / `data-slide-dot` | legacy crossfade slideshow |
| `data-lightbox` / `data-lightbox="group"` on links to images/videos (+ `data-caption`); legacy `data-video` | vanilla lightbox (gallery arrows, swipe, Esc) |
| `data-modal-open="#id"` → `<div id class="modal hidden" data-modal>` with `.modal-scrim[data-modal-close]` + `.modal-card` | generic modal with focus trap |
| `form data-ajax-form action=… data-success="…"` + `[data-error-for=field]`, `[data-form-message]` | AJAX POST with CSRF, spinner, inline errors, `form:success` event |
| `.marquee[data-marquee="40"] > .marquee-track` (+ `[data-marquee-host]` and `[data-marquee-toggle]`) | CSS marquee, content duplicated, pause on hover/off-screen |
| `<body data-cursor>` (set by `site_header`) | cursor follower ring; `data-cursor-label="View"` shows a label |
| `#site-header`, `#mobile-menu`, `#site-search`, `[data-back-to-top]`, `[data-read-progress]`, `#site-loader`, `[data-ticker]` | chrome hooks (keep ids/attributes when editing header/footer) |
| `data-text-reveal` (headings) | text-mask reveal: rendered lines slide up from behind a mask when visible (hidden until split; static in automation/reduced motion) |
| `data-reveal="clip"` | clip-path photo reveal (+ photo settles from scale 1.15); `.clip-in` = CSS-only variant for above-the-fold photos (starts partially visible, keeps LCP) |
| `data-reveal="wipe"` (short labels) | overlay wipe: an orange-red block (`--wipe-color`) sweeps across left → right and leaves to the right, the label appears mid-sweep (700 ms, `::after` translateX + scaleX, compositor-only). Used by `section_heading()` eyebrows (off with `'reveal' => false`) and the hero "Admissions open" chips (never pre-hidden: the block sweeps over the visible label); labels are only pre-hidden when motion is allowed; static mode adds `.reveal-static` (no block) |
| `data-spotlight` | cursor-following orange-red light inside the element (desktop fine pointer) |
| `data-dismiss="key"` + `[data-dismiss-close]` | dismissible bar remembered in localStorage (admission bar) |
| `data-mega` / `data-mega-filter` / `data-mega-item` / `data-mega-empty` | Programs mega menu filters (level / department / tag) with staggered card entrance |
| `data-view-toggle="key"` + `data-view-mode` · `data-view-key="key" data-view` | listing view modes (§8) |
| `[data-enquiry-popup]` (+ `data-popup-auto="8"`) · URL `?popup=1` | enquiry popup (§14): auto-open rules in `core/popup.ts`; `?popup=1` opens the first popup on any page immediately (also in automated browsers — previews / screenshots) |
| `form[data-ajax-form][data-secure="newsletter"]` (+ `data-captcha`, `[data-captcha-holder]`) | token + captcha protected vanilla form (`site_secure_form_attrs()` / `site_secure_form_fields()`); `form:success` bubbles |
| `.hl-gold[data-draw]` | gold marker draw-in (same observer as the scribbles) |
| `svg[data-blob]` | morphing blob (paused in static mode) |
| `.btn-attention` | idle glow pulse on the main hero CTA, paused off-screen |
| `.btn-gleam` (+ `.btn-shine`) | idle gleam: when the button comes into view the shine streak crosses it twice, 2.4 s apart (`initGleam`: `.is-live` until the sweeps end / hover / focus / leaving the view; header, drawer and mobile-bar CTAs once per page; buttons entering together take turns 0.8 s apart) |

Runtime handle: `window.__gimtSite.enhance(root)` (re-run enhancements on injected HTML), `.mountIslands(root)`,
`.openLightbox(items, start)`.

## 5. CSS library (`assets/css/src/style.css`)

* **Keyframes** (each also as `@-webkit-keyframes`): `fade-up fade-down fade-in zoom-in slide-in-right slide-in-left
  float float-rotate gradient-shift shimmer marquee marquee-reverse marquee-y ripple pulse-ring blob-morph
  pattern-drift spin-slow typing-dots caret-blink draw-line draw-x ken-burns bounce-subtle glow progress-fill
  loader-out pop-in`.
* **Entrances**: `.animate-fade-up .animate-fade-down .animate-fade-in .animate-zoom-in .animate-slide-in-right
  .animate-pop-in` + `.delay-1…4` (above-the-fold content; below the fold use `data-reveal`).
* **Ambient**: `.float-slow`/`.animate-float`, `.float-delayed`, `.float-rotate`, `.spin-slow`, `.bounce-subtle`,
  `.glow`, `.pulse-ring` (uses currentColor; `--pulse-scale`), `.blob` (+ size/colour utilities), `.animate-ken-burns`.
* **Surfaces**: `.glass`, `.glass-dark`, `.glass-light`, `.bg-animated-gradient` (`-light`), `.pattern-dots`,
  `.pattern-grid` (`-light` variants for dark sections; add `.animate-pattern-drift`, `.pattern-fade`, or use a
  `.pattern-layer` child), `.overlay-gradient`, `.overlay-navy`, `.bg-hero-overlay`, `.text-gradient` (`-light`).
* **Hover/micro**: `.card-lift`, `.img-zoom` (on the frame; zooms the inner img, also when a `.group` parent is hovered),
  `.tilt-hover`, `.btn-shine`, `.underline-grow`, `.link-arrow`, `.stretched-link`, ripple (`.ripple`, automatic).
* **Feedback**: `.skeleton` (shimmer), `.typing-dots` (three spans), `.progress-track` + `.progress-fill`
  (`--progress` 0–1) or `[data-progress]`, `.draw-line` (SVG with `pathLength=100`), `.btn.is-loading`.
* **Gold highlights**: `.hl-gold` (+ `.hl-gold-w` words, `.hl-gold-tall`), `.pill-gold` (`.pill-gold-lg`), `.hl-gold-soft`, `.bg-gold` (§0).
* **Enquiry popup**: `.epop-modal`, `.modal-card.epop`, `.epop-visual` (`.is-cutout`), `.epop-ribbon`, `.epop-stage`, `.epop-blob`, `.epop-photo` (photo box: frame + chips), `.epop-figure`, `.epop-chips`/`.epop-chip`, `.epop-body`, `.epop-title`, `.epop-privacy`, `.epop-x` (keyframes `epop-float`, `epop-wiggle` + `-webkit-` twins) (§14).
* **Design v3**: tokens + `.hue-*` colour map (§0), `.bg-mesh-navy .bg-mesh-warm .bg-grid-paper .bg-grid-navy .grain .gradient-border .accent-line .glass-panel .spotlight`, `.geo-*` shapes, `.blob-svg`, `.scribble`, `.sticker-*`, `.ribbon-label`, `.pastel-*`, `.level-chip .hue-chip .dept-chip .hue-dot .tag-pill`, `.view-*` / `.vm-*`, `.link-sweep` (footer link overlay), `.announce-strip`, `.admission-bar`, `.enquiry-tab`, `.wa-fab`, `.chat-tab`, `.mobile-cta`.
* **Components**: `.btn btn-apply|explore|brochure|callback` (+ aliases `accent orange primary navy ink outline soft`, and `secondary outline-light white ghost`; `btn-sm|lg`, `btn-shine`, `btn-attention`, `is-loading`), `.chip`
  (`.chip-active`, `.chip-count`), `.card`, `.eyebrow` (`.eyebrow-line`), `.section` (`-light`, `-navy`, `-tight`),
  `.tint-blue|green|cyan|amber|violet|rose|navy`, `.date-tile`, `.check-list`, `.form-input` (`.is-invalid`),
  `.form-check`, `.form-message(-success|-error)`, `.program-card`, `.feature-tile`, `.stat-card`, `.overlay-card`,
  `.cta-band`, `.page-hero`, `.pill-row` (one swipeable chip row on phones).
* Classes built dynamically in PHP/TS must appear literally somewhere Tailwind scans (or in the safelist in
  `tailwind.config.cjs`) — `@layer components/utilities` rules are purged otherwise.

## 6. Performance & accessibility rules

* Budget: entry JS < 15 KB gzip (currently ≈ 11 KB); React (≈ 45 KB gzip) only on pages with islands; each island
  1–3 KB gzip + shared hooks/icons. Check with `npm run build:site` output.
* Animate `transform`/`opacity` (and `filter` sparingly) only; never width/height/top/left in loops. The accordion
  (grid-rows) and tab indicator width are the deliberate exceptions. `will-change` only on long-running layers.
* Images: `loading="lazy" decoding="async"` everywhere except the LCP image (`fetchpriority="high"`, preload it via
  `site_header(['preload' => [...]])`); give width/height or an aspect class to avoid CLS.
* Islands must render the same footprint as their fallback (the runtime holds the height for ≤ 1.5 s). Forms: the server
  fallbacks print an empty `<p class="form-error">` per field — `.form-error:empty { display: none }` keeps them at the
  island's height (the islands render no error line until there is an error).
* Query parameters / cookies on public pages are scalars only: `includes/config.php` drops array values (`?category[]=x`,
  `?q[a][b]=x`, cookie `gimt_theme[]=x`) from `$_GET` / `$_COOKIE` before any page code runs (not for API requests), so
  crafted URLs answer 200 / 404 without "Array to string conversion" warnings or TypeErrors; `sitemap.php` (no
  config.php) reads `type` with `api_str()`. Test: `php tools/.cache/shots/website_review/fuzz-params.php` (43 URLs ×
  ~110 parameters as list / nested / keyed arrays).
* Content-Security-Policy: not sent for public pages yet. Inline scripts come from several owners (theme / view-transition
  guard / chat launcher in includes/**, consent defaults + Google tag + error monitor from `app/services/seo.php`), and a
  nonce policy needs every one of them to carry the same per-request nonce — otherwise even `Report-Only` fills the console
  with violation messages. To add it: a `site_csp_nonce()` helper (random 16 bytes, base64, per request) used by every
  inline `<script>` and by `site_assets_head()/site_assets_footer()`, then `Content-Security-Policy-Report-Only:
  default-src 'self'; script-src 'self' 'nonce-…' https://www.googletagmanager.com https://www.google-analytics.com
  https://connect.facebook.net https://www.google.com https://www.gstatic.com https://challenges.cloudflare.com
  https://js.hcaptcha.com https://js.sentry-cdn.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com;
  font-src 'self' data: https://fonts.gstatic.com; img-src 'self' data: blob: https:; connect-src 'self' https:;
  frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https://www.google.com
  https://challenges.cloudflare.com https://*.hcaptcha.com; frame-ancestors 'self'; base-uri 'self'; form-action 'self';
  object-src 'none'` (+ the Vite dev origin `http://127.0.0.1:5174 ws://127.0.0.1:5174` only in development), with a
  `report-uri` endpoint; switch to enforcing after a clean reporting period.
* Everything is static under `prefers-reduced-motion` (CSS block at the end of style.css + `staticMode()` in TS). The
  same static mode is used for automated browsers (`navigator.webdriver`) so Playwright screenshots show final states.
* Keyboard: menus (Enter/Space/↓, arrows, Esc), drawer/search/modals (focus trap, Esc, focus restore), sliders (←/→,
  pause buttons), tabs (roving focus), accordions (↑/↓/Home/End). Visible focus rings (`:focus-visible`).
* Autoplaying/moving content always has a pause control or pauses on hover/focus, and stops when off-screen or the
  tab is hidden.
* View transitions: same-document ones only through `viewTransition(fn)` (core/effects.ts — theme switch, view modes): it
  handles the `ready` / `finished` / `updateCallbackDone` rejections of an aborted transition and starts none while the
  window is being resized or the tab is hidden. Cross-document page transitions (`@view-transition { navigation: auto }`
  in keyframes-webkit.css) are guarded by the inline `pagereveal` / `pageswap` script at the top of includes/header.php.
  Result: no "Uncaught (in promise) InvalidStateError: Transition was aborted …" in the console. Never call
  `document.startViewTransition()` directly. Test: `tools/.cache/shots/website/vt-test.cjs`, `vt-nav.cjs`.
* Header nav (from 1280 px): labels never wrap (`white-space: nowrap`); between 1280 and 1439 px (1280 / 1366 laptops
  whose scrollbar leaves ~1263 px) links, the theme toggle and the round buttons are slightly more compact. Check with
  `tools/.cache/shots/website/header.cjs` (`SCROLLBARS=1` launches Chromium with real scrollbars).

## 7. Testing

* Screenshots: `MSYS_NO_PATHCONV=1 NODE_PATH=tools/.cache/pw/node_modules node tools/screenshot.cjs --base http://127.0.0.1:8000 --public --both --full --out tools/.cache/shots/<unit> / /about`
  (static mode → reveals, counters and typing show their final state).
* Check islands mounted: `document.querySelectorAll('[data-island]')` → `data-island-state="mounted"` (`error` means
  the fallback was kept — see the console).
* Test real motion in Playwright with `context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }))`.

## 8. Listing view modes (Grid / List / Compact / Detailed)

```php
<?= view_mode_toggle('programs', ['grid', 'list', 'compact', 'detailed']) ?>   // accessible segmented control
<?= view_mode_open('programs', ['class' => 'gap-6']) ?>                       // <div class="view-host" data-view-key data-view="grid">
  <article class="view-item card"><div class="vm-media">…</div><div class="vm-body"><h3>…</h3><p class="vm-text">…</p><div class="vm-extra">…</div></div></article>
<?= view_mode_close() ?>
```
* SSR default = setting `view_mode_default` (`grid`) → no layout shift; the choice is remembered per key in
  localStorage (an inline script applies it before paint, `core/viewmode.ts` binds the buttons — `role="radiogroup"`,
  ←/→, `aria-checked`). Layout CSS: `.view-host[data-view="grid|list|compact|detailed"]` with the `.vm-media`
  (hidden in compact), `.vm-text` (clamped), `.vm-extra` (detailed only) hooks. Per-listing default: setting `view_mode_{key}`. Demo: `/site-kit.php#views`.

## 9. Button colour-overlay animation (every `.btn`)

Implemented once in style.css: a second colour layer (`.btn::before`, `--btn-overlay`) sweeps in from the left behind
the label (`scaleX(0→1)`, 260 ms `cubic-bezier(.2,.8,.2,1)`) on hover, keyboard focus and touch `:active`; the label
stays above (AA contrast in both states); arrow nudges, 1–2 px lift with a coloured shadow (`--btn-shadow`), pressed
state on `:active`, ripple on click (never delays navigation).

| Variant | Rest | Overlay (hover/focus/active) |
|---|---|---|
| `apply` (+ `accent`, `orange`) | orange-red `#D03E16`, white | navy `#0B2A5B` |
| `explore` (+ `primary`, `navy`, `ink`) | navy, white | orange-red `#D03E16` |
| `brochure` (+ `outline`, `secondary`) | navy outline | navy fill, white text |
| `callback` (+ `soft`) | soft orange tint | orange-red fill, white text |
| `white`, `outline-light` (on navy) | white / white outline | orange-red fill, white text |
| `ghost` | transparent | warm grey `#EDE8E2` |
| WhatsApp (`.chat-tab`, `.wa-action`) | `#25D366` | darker green `#128C7E` |
| `.btn-whatsapp` (`whatsapp_cta($label, $text, ['size'])`) | soft green tint, dark-teal text, glyph in a `#25D366` circle | dark teal `#075E54`, white text |

* `.btn-shine` — light streak on primary CTAs (`::after`). `.btn-attention` — idle glow pulse every ~4.5 s for the single
  main hero CTA (paused off-screen by `initAttention`, on hover/focus and under reduced motion).
* `.btn-gleam` (`button_link(..., ['gleam' => true])`, implies `btn-shine`) — the streak also crosses on its own, twice
  per appearance in the viewport (a screen's motion is over within 5 s; leaving hover / focus never restarts it; header,
  drawer and mobile-bar CTAs only once per page). On gleam buttons the hover streak is a keyframe animation. Used on a
  few key CTAs only: header / drawer / mobile-bar Apply Now, home + landing hero primary, Admissions + program hero, CTA
  bands — never on the `white` variant (white streak). Static under reduced motion, in automated browsers and without JS.
* `.is-loading` — spinner replaces the label + pulsing overlay; `:disabled` / `[aria-disabled="true"]` — no motion,
  grey `#6B7280` fill (or grey outline) with ≥ 3:1 contrast. Reduced motion = instant colour swap.
* Overriding: set `--btn-overlay`, `--btn-hover-text`, `--btn-shadow` on a custom `.btn`. State matrix demo:
  `/site-kit.php#buttons` (`.is-demo-hover/.is-demo-focus/.is-demo-active` freeze states for the kit only).
* Footer/legal links use the same idea as a text sweep (`.link-sweep`, `.footer-link`: orange-red `::before`
  scaleX 180–300 ms, `:focus-visible`, instant under reduced motion).

## 10. Chrome & floating widgets

| Widget | Helper | Setting / option | Notes |
|---|---|---|---|
| Announcement ticker + utility links | `announcement_strip()` | `show_topbar`, `announcement_strip_text`, page `announce` | items = live CMS announcements of type `topbar` first, then `ticker` (max 8; `site_announcement_items()`), else `announcement_strip_text`; own clipped + faded ticker container with pause button; links (phone, Student/Faculty login, Admin) never overlap; ticker only on phones |
| Alert bars (CMS) | `site_alert_bars()` (header.php, every page) | Website CMS › Announcements, type `banner` | gold urgency bar above everything (navy text, megaphone icon, title + `link`/`link_text`), max 2 live; ✕ dismisses it in this browser (`data-dismiss`, localStorage `gimt.alert.{id}.{hash}` — editing the text shows it again); a tiny inline script hides a dismissed bar before the first paint (no flash / layout shift) |
| Page promo card (CMS) | `site_page_promo()`, printed by `page_hero()` → `page_hero_promo()` | Banners, placement `page_header` (first live one) | wide card under the hero of Programs, Admissions, Academics, Campus, Placements, Faculty, Alumni, About (`page_hero(..., ['promo' => true\|false])` overrides); image, eyebrow (gold pill), title + highlight, sub-heading, up to 2 buttons (Explore · Brochure styles); a following section that overlaps the hero (`-mt-*`) starts below it |
| Promo strip (CMS) | `site_promo_strip()` (index.php, after the Programs section — after the first section when Programs is off) | Banners, placement `strip` | slim navy bar on the Programs section's warm-50 backdrop: round photo with a gold ring, gold eyebrow pill, title, sub-heading, Apply-style button (+ outline-light) |
| Notice popup (CMS) | `notice_modal()` / `site_notice_popup_data()` (footer.php) | Banners `popup` (homepage) · Announcements `popup` (other pages, and the homepage when there is no popup banner) | light `.npop` card: image \| text (banner) or a gold header with a megaphone tile (announcement); once per browser session per notice (sessionStorage `gimt.notice.{b\|a}{id}.{hash}`), `data-notice-delay` 3 s after load, phones after 35 % scroll or 20 s, never over another layer / cookie banner / chat, never on /apply, /contact, campaign landings or error pages; at most ONE popup per page view — on the homepage the enquiry popup goes first and the notice waits for a later page view, and a visitor who opened another dialog on the page (Enquire Now, Request a Callback …) is not interrupted by it; `?popup=notice` previews it; a click on its links closes it; `dataLayer` `notice_popup_open` |
| Admission notification bar | `admission_bar()` | `site_admission_bar` (0), `admission_bar_text` | dismissible (`data-dismiss`), yellow = urgency only |
| WhatsApp | `whatsapp_button()` (FAB) / chat tab | `site_chat_style` (`tab`\|`button`\|`none`) | `icon('whatsapp-brand')` white on `#25D366`, hover `#1EBE57`, 58 px FAB with pulse ring, label "Chat with us on WhatsApp (opens in a new tab)"; React icon map key `whatsapp-brand` |
| Enquiry tab + popup | `enquiry_tab()`, `enquiry_modal()` | `site_enquiry_tab` (1), `enquiry_popup_*` | navy vertical tab on the left edge (vertically centred, from 1344 px; a slim strip below 1536 px) opening `#enquiry-modal` — the two-panel enquiry popup (§14); the homepage also opens it by itself once per visit |
| Mobile admission bar | `mobile_cta_bar()` | `site_mobile_cta` (1) | Call · WhatsApp · Apply; sets `--mobile-cta-h` on `body` so other fixed widgets can sit above it |
| Inline WhatsApp CTA | `whatsapp_cta($label, $text, ['size','class'])` | `whatsapp_number` | used on landing pages next to Request a Callback and under the final form; '' when no number |
| Back to top | `back_to_top()` | — | progress ring |
| Chatbot slot | `includes/partials/chatbot.php` → `chatbot_widget()` | page `chatbot` (false hides it), `chatbot_greeting` (false hides only the bubble), chatbot setting `showOnMobile` | guarded include before `</body>`; bottom-left is kept free for it (offset with `bottom: calc(1rem + var(--mobile-cta-h))`). Below 1024 px the launcher rests (hidden, `.gcb-host.gcb-rest .gcb-dock`) until the visitor has scrolled half a screen — it never covers the first screen's text or CTAs above the mobile admission bar; pages that cannot scroll that far show it at once; 1024–1439 px (laptops: the page gutter is narrower than the 60 px launcher) it rests the same way only when it would cover a control, heading or text of the first screen (`covers()` in the partial's inline script samples five points with `elementsFromPoint`; e.g. the program page tabs at 1280 × 800), a free corner shows it at once; it never rests while the chat is open or the launcher has keyboard focus; from 1440 px it always shows (it fits in the gutter); test `tools/.cache/shots/website_review/chat-laptop.cjs`; without JS it always shows (test: `tools/.cache/shots/website/chat-rest.cjs`). "Let's chat!" bubble: after ≥ 30 % scroll or 20 s, never on /apply, /contact or the portals, on landing pages never while a lead form is on screen, only when scrolling has settled and nothing sits under it (headings, any text, links, images, controls, other floating widgets — `AVOID` in ChatWidget.tsx), never while a dialog / drawer is open, once per visit, folds away after 15 s, a dismissal is remembered 7 days |

Footer credit: settings `footer_credit_text` ("Design, Maintain And Developed By"), `footer_credit_name`, `footer_credit_url`
(new tab, `rel="noopener"`, accessible name "… (opens appsgain.in in a new tab)") on every page incl. minimal footers.

## 11. Admissions campaign landing pages (`admissions-open.php`)

* `/admissions-open` = general campaign page (all programs, "Choose your program" index); `/admissions-open/{key}`
  resolves a program by `slug`, `lower(code)` or `slugify(short_name)` (`/admissions-open/mba`,
  `/admissions-open/master-of-business-administration`, `/admissions-open/b-tech-cse`); unknown key → friendly 404 with
  links. Routes: `tools/router.php` + `.htaccess`. Regular navigation (mega menu, program cards, footer) never links
  here — these URLs are for ads/campaigns.
* Minimal header (logo · phone · Apply Now → `#lead`) and minimal footer; no enquiry tab; mobile bar Call + Apply.
* Sections (all DB/settings driven, sections hide when empty): announcement strip → hero ("Admissions Open 2026-27:" +
  program pill, level · duration · intake · mode chips, scholarship line from `scholarships`, specialisations from
  `courses`, Download Brochure = program brochure or `/programs?brochure=1&program=slug`, approvals card) + lead card
  (program preselected, hidden `source=landing_{code}`, UTM/gclid capture) with the gold strip (countdown to
  `admissions_deadline` + real seats-filled % from `admissions`/intake, `landing_show_seats`) → highlights strip →
  approvals ribbon cards → semester-wise curriculum (`subjects`, collapsible) → fees (`fee_structures`) & scholarships +
  Request a Callback → careers (`placement_offers`/`companies` for the program, else `career_prospects`) → advantages
  (`landing_advantages` JSON or defaults) → stories (`testimonials` → `alumni_success_stories` → `alumni`, filtered by
  program, else general) → FAQ (program facts + `faqs`, FAQPage JSON-LD) → other programs carousel → final CTA form.
* SEO: unique title/description, canonical = slug URL, `robots noindex,follow` unless setting `landing_indexable` = 1,
  Course JSON-LD. Copy overrides: `landing_hero_title`, `landing_hero_pill`, `landing_scholarship_text`,
  `landing_strip_text`, `landing_form_title/_highlight/_subtitle`, `landing_financing_title/_text`, `approvals_list`.

## 12. Print documents

Printed/PDF views (`admin/print/*`) keep the ERP navy/green look: `tools/build-css.sh` also builds
`assets/css/print.css` from `tailwind.print.config.cjs` (preset brand/accent + the v3 named tokens, no orange accent
override) and `print_layout_start()` links it (falls back to style.css) and marks `<body class="print-doc">` (toolbar button
overlays stay navy). Do not link `style.css` from print views.

## 13. Light / Dark / System theme (unit theme_viewmodes)

* **Files**: `includes/partials/theme.php` (server side + inline scripts), `frontend/site/core/theme.ts` (cross-fade, cross-tab
  sync, live announcement), `frontend/site/core/viewmode.ts` (§8), the dark colour map in `tailwind.config.cjs`
  (`DARK`, `LIGHT_SCOPES`; utilities read `--dk-*` variables) and the "Dark theme" block at the end of
  `assets/css/src/style.css` (tokens + component overrides, screen only — printing is always light).
* **Hooks** (header.php / navbar.php): `site_theme_html_attrs()` (`<html data-theme data-theme-pref [data-theme-lock]>`),
  `site_theme_head()` (theme-color meta + pre-paint resolver, `window.__gimtTheme`), `site_theme_toggle(['variant' =>
  'header'|'drawer'])` (radiogroup sun · moon · monitor; prints `site_theme_toggle_script()` once), `html.theme-js`,
  `site_view_sync_script($key)` (view-mode buttons right in the first paint).
* **Preference order**: localStorage `gimt.theme` → cookie `gimt_theme` (1 year, only when the visitor picks a theme; lets
  PHP render `data-theme` server-side) → setting `site_theme_default`. "System" follows `prefers-color-scheme` live and
  the `Sec-CH-Prefers-Color-Scheme` client hint without JS.
* **Settings** (Settings › Website › Appearance): `site_theme_default` (system\|light\|dark), `site_theme_toggle`,
  `view_mode_default`, `view_mode_programs`, `view_mode_faculty`, `view_mode_notices`, `view_mode_events`,
  `view_mode_news` (seeded by `database/seed/09_theme.php`).
* **Light scopes** (always light inside, also in the dark theme): `.light-scope .btn-white .bg-sun .logo-tile .tag-popular
  .program-ribbon .scholar-value .enquiry-strip .sticker-sun .sticker-white .ribbon-light .pill-gold .hl-gold .bg-gold` —
  keep `LIGHT_SCOPES` (tailwind.config.cjs) and the `:is(…)` list in the style.css dark block in sync.
* Cookie list (consent preferences / Cookie Policy): `gimt_theme` and the browser-storage keys (`gimt.theme`,
  `gimt.view.*`, `gimt.popup.*`, `gimt.notice.*`, `gimt.bar.*`, `gimt.alert.*`, `gimt.visited`, `gimt.apply.draft.v1`, `gimt-chat:*`) are listed by
  `consent_cookie_list()` in `includes/partials/consent.php`.

## 14. Enquiry popup (client request 2026-10-13)

* **Markup**: `enquiry_modal($o)` (includes/components.php) — `<div id="enquiry-modal" class="modal epop-modal hidden"
  role="dialog" aria-modal="true" aria-labelledby="…-title" data-modal data-enquiry-popup [data-popup-auto="8"]>` with a
  light card (`.modal-card.epop.light-scope`): LEFT visual panel (gold blob, orange-red ring / quarter circle / dots,
  sparkles, "Admissions Open {session}" ribbon, photo in an arch frame — or a transparent cut-out standing on the blob —
  and three white trust chips: "Free expert counselling", "Scholarships up to N%" from `site_max_scholarship()` (else
  "Scholarships for eligible students"), "Callback within 24 hours" (same promise as the server's success message and /admissions) — the chips sit in the photo box
  `.epop-photo` with the frame and are placed relative to the photo, not the panel: top-left over the blob, the right edge at
  shoulder height, the left edge near the bottom, so they never cover the face whatever the form panel's height (errors,
  captcha, success state); they never wrap (max 16rem). 768–959 px (narrow photo panel): the three chips stack between the
  ribbon and the photo. Cut-out photos: chips hug the wider box (−6 %) so they stay inside the panel); RIGHT form panel (gold-marker heading, the
  EnquiryForm with theme `plain`: name, international mobile, email, program, consent; red submit with the overlay sweep;
  privacy line). Phones (< 768 px): bottom sheet, the visual becomes a 124 px strip (ribbon + one chip | small photo).
* **Rendered by**: the footer whenever the Enquire Now tab is on, on the homepage when auto-open is on, and on any page
  with `?popup=1` (never on /apply or on minimal-header pages); landing pages render their own `#lead-modal` with the
  program preselected and `source=landing_{code}`. Triggers: any `[data-modal-open="#enquiry-modal"]` (Enquire Now tab,
  "Request a Callback" buttons). Hidden values: `source` (`enquiry-tab-{page}` / `enquiry-popup-home`), `form=enquiry-popup`,
  `consent_required=1` (every EnquiryForm that shows a consent box — island and server fallback — posts it, so
  `/api/public/enquiry` answers 422 `consent` when the box was not ticked; `pf_enquiry_consent_required()`).
* **Auto-open** (`frontend/site/core/popup.ts`): homepage only, after `enquiry_popup_delay` seconds (3–60, default 8);
  phones / touch: additionally ≥ 35 % scroll or 20 s; once per browser session (sessionStorage `gimt.popup.seen`, also
  set when it is opened by a click); never for 30 days after a lead was sent (localStorage `gimt.popup.done`, any form with
  a phone field firing `form:success`, and every island lead — `gimt:lead` on window from `forms/security.ts pushLead()`:
  the homepage Smart Enquiry, the Apply wizard, the chat callback card); waits while another layer / the cookie banner / the chat window is open, the tab
  is hidden or the visitor is typing in a field; never in automated browsers except with `?popup=1`. Focus goes to the
  dialog card (no keyboard pop-up on phones); Esc / ✕ / backdrop close it, focus returns, page scroll is locked
  meanwhile (core/overlays.ts); the focus trap (core/dom.ts `trapFocus`) also wraps Shift+Tab from the card itself and pulls
  focus back when it is outside the dialog. `dataLayer` event `enquiry_popup_open` {trigger} when a tag manager is present.
* **Warm-up** (no photo pop-in, no fallback → island swap after opening): `warm()` in core/popup.ts switches the photo to
  `loading=eager` and mounts the EnquiryForm island while the dialog is still hidden (`mountIslandsNow(root)` in
  core/islands.ts) — ~2 s after load when the popup will open by itself, on intent (pointer over / focus on / touch of a
  `[data-modal-open]` trigger), and on open. `?popup=1` opens it as soon as the form and photo are ready (≤ 1.5 s). The
  server fallback form and the island have the same height (empty `.form-error` lines are hidden; compact phone fields use
  `py-2.5` like the other inputs), so even an unwarmed open does not jump.
* **Settings** (Settings › Website › Enquiry popup; seeded INSERT IGNORE by `database/seed/09_enquiry_popup.php`):
  `enquiry_modal_image` (upload; transparent PNG/WebP = cut-out), `enquiry_popup_title` (default `Get [Free
  Counselling]` — brackets = gold phrase), `enquiry_popup_ribbon` (default `Admissions Open {session}`),
  `enquiry_popup_auto` (1), `enquiry_popup_delay` (8).
* **Default photo**: `assets/images/site/enquiry-girl.jpg` + `.webp` (564×594 file = the 282×297 px region x 944, y 278 of
  the bundled `hero-campus-students.jpg`, stored at 2×). The frame is ≤ 249 × 291 CSS px, so 1× screens show it at about its
  source resolution; on 2× (retina) screens it is visibly softer than the vector UI — no bundled photo is sharp enough
  (sharper GD resamplings were compared and rejected: more JPEG artefacts, no real gain). Replace it with a real admissions
  counsellor / student photo (ideally a transparent PNG cut-out, ~600×700 px or larger) through the setting.
* **Motion**: card scale/opacity ≤ 300 ms, slow blob float (8 s), one ribbon wiggle; all static under reduced motion.
* **Tests**: `tools/.cache/shots/design_polish/popup-test.cjs` (auto-open rules, focus trap, Esc, once per session,
  phone scroll rule, ?popup=1, landing preselect) and `popup-submit.cjs` (real submission + success state); review set in
  `tools/.cache/shots/website_review/`: `popup-geom.cjs` (geometry at 360–1920 px, light + dark: card in view, chips inside
  their pills and the panel, 44 px targets), `popup-a11y.cjs` (Enter on the tab, Shift+Tab / Tab trap from the card and the
  first control, Esc + focus return, backdrop, landing preselect + focus return, reduced motion, chat open / automation /
  typing → no auto-open), `popup-mount.cjs` (fallback vs island height), `popup-submit.cjs` (errors → real submit →
  success, done flag, dataLayer; delete the "Popup Review QA" rows afterwards), `settings-popup.cjs <cutout.png>` (admin
  Settings › Website: Appearance + Enquiry popup render, 422 on delay 2, save → takes effect, cut-out upload, auto off,
  restore).

## 15. CMS pages (`page.php`)

* One URL per page: `/page/{slug}` and `/{slug}` resolve the same CMS page; old slugs / aliases (also of unpublished rows)
  301 to the current URL; unknown slugs get the branded 404.
* Users with `cms.view` see drafts automatically (preview banner); empty pages are `noindex`.
* Policy pages: editor-stated "Last updated" line, highlighted `[To be confirmed by GIMT: …]` placeholder notes, table of
  contents with scroll-spy, print button / print styles.
* Page-builder blocks (CTA, program explorer, FAQ …) render through `includes/partials/content.php` (docs/CMS.md §2).

## 16. Security hardening of public pages (Stage C)

* **Array query parameters**: `includes/config.php` drops every array-valued `$_GET` / `$_COOKIE` entry (`?category[]=x`,
  `?q[a][b]=x`, cookie `gimt_theme[]=x`) before any page code runs (not for API requests), so crafted URLs can no longer
  raise "Array to string conversion" warnings or a TypeError 500. POST handlers read fields with `is_string()` checks
  (`apply.php`). Fuzz test: `php tools/.cache/shots/website_review/fuzz-params.php [--each]` — 43 public URLs × 107 parameter
  names as `[]`, `[a][b]` and `[k]` arrays must answer 200/3xx/404 with no PHP warning.
* **Content-Security-Policy-Report-Only** (`site_csp_header()`, sent by `site_header()` on every public page): `script-src
  'nonce-…' 'strict-dynamic'` (+ `'unsafe-hashes'` with the hash of `window.print()` for the three Print buttons; `https:` and
  `'unsafe-inline'` are only the CSP1/2 fallback), `style-src 'self' 'unsafe-inline'` + Google Fonts, `img-src 'self' data: blob:
  https:`, `font-src 'self' data:` + fonts.gstatic.com, `connect-src 'self' https:` (+ the Vite dev server origin and its
  `ws:` socket in development), `media-src`, `frame-src 'self' https:` (maps, video, captcha), `worker-src 'self' blob:`,
  `object-src 'none'`, `base-uri 'self'`, `form-action 'self'`, `frame-ancestors 'self'`. Report-only = browsers never block
  anything, they only report. Every `<script>` and `<link rel="modulepreload">` the site prints carries the per-request nonce:
  `site_nonce_attr()` (string contexts: `'<script' . site_nonce_attr() . '>'`; templates: `<script<?= site_nonce_attr() ?>>`),
  and the SEO platform's trusted head output (error reporter, Sentry loader, `head` extras) goes through `site_csp_tag()`.
  Scripts those trusted scripts load (island chunks, analytics injected after consent, captcha widgets) are allowed by
  `'strict-dynamic'`. **Rule for new code**: every new inline `<script>` needs `site_nonce_attr()`, and no inline event
  handlers (`onclick=` …) — use data attributes + the bundle (the only exception is `onclick="window.print()"`, whose hash is
  in the policy). Never call `site_csp_tag()` on CMS or visitor content.
  Switches: env `GIMT_CSP_REPORT_ONLY=0` (or config `app.csp_report_only = false`) turns the header off; env
  `GIMT_CSP_REPORT_URI` / config `app.csp_report_uri` adds a `report-uri` (absolute URL or a path on this site). Verified with
  `tools/.cache/shots/website_review/csp-check.cjs` (34 pages in Chromium, scrolled, popups opened, theme / view switched:
  0 violations). Enforcing it later = rename the header to `Content-Security-Policy` once the report endpoint shows no
  violations for a while (GTM Custom-HTML tags need GTM's nonce-aware setup first).
* **Consent**: forms that show a consent box post `consent_required=1` (§14), and the server rejects them without consent.
