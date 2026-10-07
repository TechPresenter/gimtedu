# GIMT website platform — React islands + CSS3 motion

Reference for building public pages (`/*.php`). Live examples of everything below: **`/site-kit.php`** (dev only,
noindex — copy from it). Client brief: `docs/REQUIREMENTS.md` → "Client update — website technology & motion".

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
| `site_header($page)` | keys: `title, description, active, image, seo, canonical, robots, schema, body_class, og_type` + new: `cursor` (bool, custom cursor ring, default setting `site_cursor`=1), `motion` (false → `<html data-motion="off">`, no entrance animations), `loader` (false → no first-visit loader), `preload` (array of image URLs to preload — pass the LCP hero image) |
| `site_footer($opts)` | `cta` (false hides the band), `cta_title, cta_text, cta_primary [label,url], cta_secondary [label,url,icon], cta_image` |
| `page_hero($title, $subtitle, $breadcrumbs, $image, $text, $features, $opts)` | unchanged first 6 params. `$opts`: `parallax` (default true), `pattern` (animated dots, default true), `glass` (glass feature chips, default true), `eyebrow`, `script` ("Learn Grow Innovate Succeed" handwritten words), `actions` (HTML buttons), `size` (md\|lg), `align` (left\|center), `id`, `image_alt` |
| `section_heading($title, $lead, $eyebrow, $align, $actionHtml)` | unchanged |
| `island($name, $props, $fallbackHtml, $attrs)` | raw island placeholder. `$attrs`: `tag` (div\|span\|section…), `eager` (bool), `class`, `id`, any attribute. Props are JSON (escape-safe). Children of `[data-slot="x"]` elements in the fallback arrive in the component as `slots.x` (`{html, data}[]`) |
| `site_assets_head()` / `site_assets_footer()` | used by header/footer — don't call in pages |

### Building blocks (`includes/components.php`)

| Helper | Output |
|---|---|
| `reveal_attr($effect = 'fade-up', $delayMs = 0)` | ` data-reveal="…" data-reveal-delay="…"` |
| `section_open(['id','bg' => white\|light\|navy\|gradient\|brand-soft,'pattern' => dots\|grid,'padding' => normal\|tight\|none,'class','container','label','reveal'])` … `section_close()` | `<section class="section …"><div class="container-site">` (gradient = animated navy gradient) |
| `button_link($label, $url, $variant, ['size','icon','icon_left','magnetic','shine','class','target','attrs'])` | variants `primary accent navy secondary outline outline-light white ghost`; accent/primary/navy get a trailing arrow by default (`'icon' => ''` removes it) |
| `program_card($programRow, ['reveal','delay','ribbon','heading'])` | mockup program card (photo, Most Popular ribbon, level chip, duration/level/fee, summary, View Details + Apply Now) |
| `program_filter_tokens($p)`, `program_level_label($level)`, `program_icon($p)`, `program_categories()` | helpers for filtering / menus |
| `feature_tile($icon, $title, $text, ['tint' => navy\|blue\|green\|cyan\|amber\|violet\|rose,'variant' => card\|compact\|plain,'url','reveal','delay'])` | "Why GIMT" icon tile (icon lifts + turns navy on hover) |
| `stat_card($value, $label, $icon, ['prefix','suffix','decimals','tint','dark','reveal'])` | number with vanilla `data-count` |
| `cta_band(['title','text','primary','secondary','image','eyebrow','compact'])` | navy CTA band with pattern, blob and floating cap (the footer uses it) |
| `overlay_card($title, $text, $url, $image, $linkLabel, ['reveal','delay'])` | Campus Life / Placements / News image card |
| `check_list($items, $class)` | green-check bullet list |
| `glass_chip($icon, $label, 'dark'\|'light'\|'white')` | glass chip for use over images |

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
| **HeroSlider** | `slides: {image, mobileImage?, alt?, eyebrow?, title, highlight?, text?, cta?: {label,url}, cta2?}[]`, `chips?: {icon,label}[]`, `stats?: {value, prefix?, suffix?, label, icon?}[]`, `video?: {url, label, sublabel?}`, `tagline?`, `taglineWords?: string[]`, `interval?` (ms, 6500; 0 = off), `theme?: 'light'\|'dark'`, `ariaLabel?`. Ken-Burns, progress bullets, play/pause, swipe, ←/→, ARIA carousel + live region |
| **Carousel** | `ariaLabel?`, `perView?: {base?, sm?, md?, lg?, xl?}` (default 1.15/2/3, fractions = peek), `gap?` (px, 24), `autoplay?` (ms), `loop?` (true), `arrows?`, `dots?`, `items?: {title, text?, image?, url?, meta?, badge?}[]` (used when no `slides` slot). Native touch swipe, mouse drag, keys |
| **TestimonialSlider** | `items: {name, quote, role?, company?, program?, photo?, rating?}[]`, `autoplay?` (7000), `theme?`, `ariaLabel?` |
| **LogoMarquee** | `items: {name, logo?, url?, industry?}[]` (no logo → wordmark tile), `rows?: 1\|2` (2 when ≥ 8), `speed?` (px/s, 45), `grayscale?` (true), `ariaLabel?` |
| **ProgramExplorer** | `categories: {key,label,icon?}[]` (default `program_categories()`; `all` shows everything), `search?` (true), `searchPlaceholder?`, `emptyText?`, `initial?`, `syncParam?` (query-string key), `layout?: 'pills'\|'tiles'`, `gridClass?`, `viewAll?: {label,url}`. Slot `items`: children with `data-category="ug management"`, `data-search`, `data-key` |
| **StatCounter** | `items: {value, label, prefix?, suffix?, decimals?, icon?, progress? (0-100), caption?}[]`, `variant?: 'cards'\|'rings'\|'bars'\|'inline'`, `theme?`, `gridClass?` |
| **TypingText** | `phrases: string[]`, `prefix?`, `suffix?`, `typeSpeed?` (65), `deleteSpeed?` (35), `pause?` (1800), `loop?`, `dots?` (typing indicator between phrases), `textClass?` (e.g. `text-gradient`) |
| **Accordion** | `items: {q, a, category?}[]` (blank line in `a` = new paragraph), `mode?: 'single'\|'multi'`, `defaultOpen?` (0; -1 none), `search?`, `searchPlaceholder?`, `categories?`, `columns?: 1\|2`, `theme?` |
| **Tabs** | `tabs: {key,label,icon?}[]`, `variant?: 'pills'\|'underline'`, `initial?`, `syncHash?`, `center?`. Slot `panels` (same order) |
| **VideoLightbox** | `url` (YouTube/Vimeo/mp4/webm), `title?`, `label?`, `sublabel?`, `poster?`, `variant?: 'thumb'\|'button'\|'chip'`, `aspect?`, `className?` |
| **GalleryLightbox** | `items: {src, thumb?, alt, caption?, category?}[]`, `columns?: 2\|3\|4`, `layout?: 'grid'\|'masonry'\|'bento'`, `filter?`, `ariaLabel?`. Lightbox: arrows, keys, swipe, click-to-zoom with panning, thumbnails |
| **EnquiryForm** | `endpoint` (POST, gets FormData + `X-CSRF-Token`; expects `api_ok/api_error` JSON), `fields: {name, label, type?, required?, placeholder?, options?, col?: 'full'\|'half', minLength?, maxLength?, min?, max?, pattern?, patternMessage?, rows?, autocomplete?, help?, value?}[]`, `title?`, `subtitle?`, `icon?`, `submitLabel?`, `successTitle?`, `successMessage?`, `hidden?: Record<string,string>`, `consent?` (adds required `consent` checkbox), `theme?: 'light'\|'dark'\|'glass'`, `compact?` (labels become placeholders). Honeypot field `website` is always sent — reject non-empty values server-side. Client messages mirror `validate()` (`required`, `email`, `phone`, `min`, `max`, `regex`) |
| **SkeletonList** | `endpoint?` (GET JSON: `data` = items[] or `{items: []}` with `{title, url?, image?, date?, excerpt?, category?, location?}`), `count?`, `variant?: 'card'\|'list'\|'event'`, `gridClass?`, `emptyText?`, `viewAll?` |
| **AutoScrollCards** | `direction?: 'vertical'\|'horizontal'`, `speed?` (px/s, 28), `height?` (px, 420, vertical), `ariaLabel?`. Slot `items`. Pause button (WCAG 2.2.2) |
| **ProgressSteps** | `steps: {title, text?, icon?}[]`, `orientation?: 'auto'\|'horizontal'\|'vertical'`, `cta?: {label,url}`, `theme?` |

Icons passed as props use lucide kebab names from the curated map in `frontend/site/lib/Icon.tsx` (same names as the
PHP `icon()` helper). Add an entry there when you need a new one (unknown names render a sparkle).

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
* **Components**: `.btn btn-primary|accent|navy|secondary|outline|outline-light|white|ghost` (+ `btn-sm|lg`), `.chip`
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
* Islands must render the same footprint as their fallback (the runtime holds the height for ≤ 1.5 s).
* Everything is static under `prefers-reduced-motion` (CSS block at the end of style.css + `staticMode()` in TS). The
  same static mode is used for automated browsers (`navigator.webdriver`) so Playwright screenshots show final states.
* Keyboard: menus (Enter/Space/↓, arrows, Esc), drawer/search/modals (focus trap, Esc, focus restore), sliders (←/→,
  pause buttons), tabs (roving focus), accordions (↑/↓/Home/End). Visible focus rings (`:focus-visible`).
* Autoplaying/moving content always has a pause control or pauses on hover/focus, and stops when off-screen or the
  tab is hidden.

## 7. Testing

* Screenshots: `MSYS_NO_PATHCONV=1 NODE_PATH=tools/.cache/pw/node_modules node tools/screenshot.cjs --base http://127.0.0.1:8000 --public --both --full --out tools/.cache/shots/<unit> / /about`
  (static mode → reveals, counters and typing show their final state).
* Check islands mounted: `document.querySelectorAll('[data-island]')` → `data-island-state="mounted"` (`error` means
  the fallback was kept — see the console).
* Test real motion in Playwright with `context.addInitScript(() => Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }))`.
