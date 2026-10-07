# GIMT SmartCampus — developer guide

University ERP + Website CMS for Global Institute of Management & Technology. Requirements: `docs/REQUIREMENTS.md`
(the client brief — the source of truth for features). Visual direction: `docs/DESIGN.md` (+ mockups in
`tools/.cache/mockups/` when present). Today's context: academic session **2026-27** is current (Jul 2026–Jun 2027).

## Layout
| Path | What |
|------|------|
| `app/` | PHP core: `init.php` bootstrap, `db.php` (PDO helpers `db_all/db_row/db_value/db_column/db_pairs/db_exec/db_insert/db_update/db_delete/db_transaction`), `functions.php` (e(), setting(), money(), next_number(), validate(), log_activity(), notify(), current_session_id(), paginate() …), `auth.php` (can(), require_permission(), user_id()), `crud.php` (generic CRUD engine), `lookups.php` (named option sources), `upload.php`, `mailer.php` (send_mail, send_template_mail, send_sms/whatsapp stubs), `xlsx.php` (xlsx/csv download+read), `components.php` (PHP UI helpers + **print layout**), `icons.php` (`icon('name','classes')` lucide SVGs) |
| `app/modules/{key}.php` | CRUD module definitions (one file per table/screen) → served at `/api/crud/{key}` |
| `app/services/{domain}.php` | Domain business logic shared by routes/pages (create as needed, `require_once` it) |
| `api/routes/{group}.php` | Custom JSON endpoints; file is chosen by the first URL segment (`/api/fees/...` → `api/routes/fees.php`) |
| `frontend/src/` | React 18 + TS + Tailwind admin SPA. `modules/{area}/` pages + `routes.tsx`, `components/ui` kit, `components/crud` (CrudTable etc.), `components/charts`, `lib/` (api client, queries, format, auth) |
| `admin/build/` | Compiled SPA (committed). `admin/print/*.php` server-rendered print/PDF views |
| Public site | `/*.php` pages + `includes/{config,header,navbar,footer}.php`, `assets/css/src/style.css` → `assets/css/style.css`, `assets/js/main.js` |
| `database/schema/*.sql` | Schema (run in natural order by the installer). `database/seed/*.php` seeders (00–09 core, 10+ demo) |
| `tools/` | `install.php`, `seed.php`, `api.php`, `router.php`, `screenshot.cjs`, `build-css.sh` |

## Local environment (Windows + XAMPP)
- PHP: `C:\xampp\php\php.exe` (on PATH as `php`), MariaDB **10.4** (XAMPP): `C:\xampp\mysql\bin\mysql.exe -uroot gimt_erp`
  (root, no password). Code must also work on MySQL 8 / MariaDB 10.6+ → use portable SQL only.
- Dev server already running at **http://127.0.0.1:8000** (`php -S 127.0.0.1:8000 tools/router.php`). Do not start
  another one on 8000. Admin: `/admin/` — login `admin` / `Admin@12345` (role demo users: `Demo@12345`).
- Git Bash mangles leading-slash args (`/admin/x` → `C:/Program Files/Git/admin/x`): prefix such commands with
  `MSYS_NO_PATHCONV=1`.
- API from the CLI (auto-login, keeps cookie + CSRF): `php tools/api.php GET "crud/students?per_page=5&f[status]=active"`,
  `php tools/api.php POST fees/collect '{"student_id":5,...}'`, `--user accounts@gimt.ac.in --pass Demo@12345` to test RBAC.
- Run one seeder without touching other data: `php tools/seed.php 22_fees`.
- Type-check the SPA: `cd frontend && npx tsc -b --noEmit`.
- See the SPA without building: a **shared Vite dev server runs at http://127.0.0.1:5173** (serves `frontend/src`
  live, proxies `/api`, `/assets`, `/admin/print` to :8000). Do not start another one. If
  `curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:5173/admin/` is not 200, start it in the background with
  `cd frontend && npx vite --port 5173 --strictPort`. Screenshot:
  `MSYS_NO_PATHCONV=1 NODE_PATH=tools/.cache/pw/node_modules node tools/screenshot.cjs --base http://127.0.0.1:5173 --out <dir> /admin/fees /admin/fees/payments`
  (`--both` adds mobile, `--dark`, `--full`; public pages: `--base http://127.0.0.1:8000 --public / /about`).
  Put screenshots under `tools/.cache/shots/<unit>/`. Look at the PNGs with the Read tool. The tool also prints
  console errors / failed requests for each page — there must be none from your screens.
  Because every module's `routes.tsx` is imported eagerly, keep `routes.tsx` files syntactically valid at all times.
- Demo role users (password `Demo@12345`) for RBAC tests: administrator, admissions, academics, accounts, exams,
  library, hostel, transport, placement, alumni, content, staff, faculty (`--user accounts --pass Demo@12345`).
- Rebuild website CSS after adding Tailwind classes to PHP files: `bash tools/build-css.sh`.

## Backend conventions
- **Every query uses prepared statements** (db_* helpers with `?` params). Never interpolate user input. Escape all
  output in PHP templates with `e()`. Rich text from users goes through `sanitize_html()`.
- JSON envelope: `api_ok($data, 'Message shown as toast')` / `api_error('msg', 422, ['field' => 'error'])`. Validate
  with `api_validate(['amount' => 'required|numeric|min:1', 'student_id' => 'required|integer|exists:students,id'])`.
- Guard every custom endpoint: `api_require('fees', 'create')` (actions: view create edit delete export import approve
  publish manage — modules listed in `app/registry.php`). Routes require login + CSRF on non-GET by default.
- Log meaningful actions: `log_activity('create', 'fees', $id, 'Collected ₹5,000 from Rahul Sharma (RCPT-00012)')`.
  Raise in-app notifications with `notify(...)` (see functions.php) for the brief's notification types.
- Document numbers (receipts, application no, certificate no, invoice no…): `next_number('receipt', 'RCPT-{Y}-{n:5}')`
  (atomic, `sequences` table).
- Multi-table writes go in `db_transaction(fn () => ...)`. Throw `CrudException('user-facing message')` for business
  rule violations inside CRUD hooks.
- Money columns are DECIMAL(12,2); format with `money()` (PHP) / `formatMoney()` (TS). Dates `Y-m-d`, timezone
  Asia/Kolkata.
- Settings: `setting('key', default)` / `save_setting('key', $value, 'group')`.

### CRUD module definitions (`app/modules/{key}.php`)
Return an array; see `app/modules/departments.php` for a complete example. Keys:
`table, title, singular, permission, icon (lucide name), description, select, joins, where, search[], order,
default_sort, columns[], filters[], fields[], scopes{name: 'sql column'}, bulk{delete, status[], status_field},
import (bool), export (bool), readonly, row_actions, per_page, form{size, mode}, view{type}, hidden_columns[],
permissions{action: [module, action]}, hooks{}`.
- columns: `key, label, format (text|title|person|badge|date|datetime|time|money|number|percent|boolean|email|phone|
  image|link|tags|color|truncate|code), sub, image, link, align, hidden, sortable (true|'sql expr'), colors{value: color}`.
- filters: `key, label, type (select|multiselect|date|daterange|text|boolean), options | source, column, sql, sql_map, depends`.
- fields: `name, label, type (text|email|tel|url|password|number|decimal|money|date|datetime|time|color|textarea|
  richtext|select|combobox|multiselect|radio|boolean|toggle|checkbox|image|file|slug|json|hidden|section|repeater),
  required, unique, rules (validate() rules), col (1-12), default, options{value: label} | source ('lookup_name' or an
  inline source array — same shape as app/lookups.php), depends{param: field}, min, max, step, maxlength, placeholder,
  help, readonly_on_edit, create_only, edit_only, form (false = not in form), category/folder/private (uploads)`.
- hooks (closures): `validate($data, $id, $old, $input): array errors`, `before_save($data, $id, $old, $input): array`,
  `after_save($id, $data, $old, $input)`, `before_delete($id, $row)` (throw CrudException to block),
  `after_delete($id, $row)`, `list_query(&$where, &$args, $req)`, `transform_row($row): array`,
  `summary($fromSql, $args): array` (shown via CrudTable `header`), `describe($row): string`,
  `bulk_action($action, $ids, $input)`, `after_bulk(...)`.
- Prefer inline `source` arrays in module files over editing `app/lookups.php`.

### Print views (`admin/print/{name}.php`)
```php
<?php
require __DIR__ . '/../../app/init.php';
require APP_ROOT . '/app/auth.php';
require_permission('fees', 'view');           // redirects to login / 403 when not allowed
$id = input_int('id');
// ... load data, 404 politely if missing
print_layout_start('Fee Receipt RCPT-2026-00012');   // A4 sheet with letterhead + Print/Back toolbar
?> ... Tailwind markup (website CSS build includes admin/print/**) ...
<div data-qr="<?= e($verifyUrl) ?>" class="h-24 w-24"></div>   <!-- rendered as QR code -->
<?php print_layout_end();
```
Open from the SPA with `window.open(printUrl('receipt.php', { id }), '_blank')` (`printUrl` from `@/lib/config`).
Print pages use website CSS: run `bash tools/build-css.sh` after adding new classes.

## Admin SPA conventions (frontend/src)
- Each module owns `modules/{area}/` — page files + `routes.tsx` using `page(path, permModule, importer, action)`.
  Routes/sidebar (`navigation.ts`) are already wired for every screen in the brief; a sidebar link such as
  `/academics?tab=programs` must open that exact view (read the query param, e.g. with `useSearchParams`).
- Page anatomy: `<PageHeader title description breadcrumbs={[{label:'Dashboard',to:'/'},...]} actions={...} />`, then
  KPI row (`StatCard`/`StatTile`), filters, `Card`s, tables, charts. Always loading (`Skeleton`/`CardSkeleton`),
  empty (`EmptyState` with a CTA) and error (`Alert`) states. Never leave `ModuleStub` in a finished page.
- Lists of a CRUD module: `<CrudTable module="fee_structures" urlState viewTo={(r)=>`/x/${r.id}`} rowMenu={...}
  bulkActions={...} header={({summary})=>...} />` — gives search, filters, sort, pagination, column control, bulk,
  export CSV/Excel/PDF/print, import, add/edit/view modals, delete confirm. Use `scope` for embedded tables (module
  must declare `scopes`). Full-page forms: `useCrudForm` + `CrudFormFields`, or custom forms with `Field/Input/Select/
  Combobox/FileUpload/RichText`.
- Data: `useApi<T>(['key', params], 'fees/summary', params)`, `useCrudList`, `useLookup('programs')`,
  `api.post/put/del` (CSRF handled), `toFormData()` for uploads, `downloadFile()` for exports, invalidate with
  `useInvalidate()` / `useInvalidateCrud()`. Feedback: `useToast()` (`toast.success(msg)`), `useConfirm()`.
- Permissions in UI: `const { can } = useAuth(); can('fees', 'create')` — hide/disable actions the user can't do
  (server enforces anyway). Current session: `useAcademicSession()`.
- UI kit (`@/components/ui`): Button/IconButton, Card/CardHeader/CardBody/CardFooter, Badge/StatusBadge, Modal/Drawer,
  Dropdown, Field/Input/Textarea/Select/Checkbox/Toggle/RadioGroup/SearchInput, Combobox (async, multi), FileUpload,
  Toast/Confirm/Alert, PageHeader/StatCard/StatTile/Avatar/PersonCell/ProgressBar/EmptyState/Skeleton/CardSkeleton/
  DescriptionList/Timeline/Tabs/Stepper, DataTable/Pagination, RichText, Spinner/PageLoader,
  Motion: `CountUp` (numbers count up on view), `Reveal` (fade/lift in on scroll, `delay`), `Stagger` (children reveal
  in sequence), `useInView`. StatCard/StatTile count up numeric `value`s automatically.
  Charts (`@/components/charts`): BarChart, LineChart, DoughnutChart, RadarChart, `chartColor(i)`.
  Format (`@/lib/format`): formatMoney, formatMoneyShort, formatNumber, formatPercent, formatDate, formatDateTime,
  timeAgo, labelize, fullName, initials, isoDate. Status colours: `@/lib/status`. Icons: `lucide-react`.
- Style: Tailwind with the shared preset (brand/accent palettes), `dark:` variants on everything (dark mode is a
  feature), responsive (tables scroll / stack on mobile, forms single column on mobile), accessible labels/focus.
  Match `docs/DESIGN.md`. No new npm dependencies.
- Premium, *subtle* motion (client asked for "premium animation" + fast loading): `Stagger` KPI rows and card grids,
  `Reveal` below-the-fold sections, `CountUp` for headline numbers, hover lift on clickable cards
  (`transition hover:-translate-y-0.5 hover:shadow-soft`), skeletons while loading, chart.js default animations.
  Only opacity/transform, ≤500 ms, respect reduced motion — never flashy. Routes are lazy-loaded; keep page bundles
  lean (no huge static data in components).
- Do not edit shared files under `components/`, `lib/`, `navigation.ts`, `router.tsx`, `App.tsx`, `main.tsx`,
  `index.css` unless your task explicitly says so; build module-specific components inside your module folder.

## Public website conventions
- Page skeleton: `require __DIR__ . '/includes/config.php'; site_header(['title'=>..., 'description'=>..., 'active'=>'about',
  'seo'=>['route','/about']]); page_hero(...); ... site_footer();`. Helpers: `site_url, cms_link, site_image, site_menu,
  site_section, site_banners, site_announcements, section_heading, social_links, setting`. CSS components in
  `assets/css/src/style.css` (`container-site, section, eyebrow, section-title, section-lead, btn btn-primary|
  btn-accent|btn-outline|btn-outline-light, card card-hover, chip chip-active, form-label form-input, badge-*`,
  `reveal`, `bg-hero-overlay`, `bg-dots`). JS behaviours in `assets/js/main.js` via data attributes (`data-count`,
  `data-filter`/`data-filter-item`, `data-slider`, mobile menu, search overlay, `.reveal`).
- Content comes from the database (CMS tables + settings) with sensible fallbacks — pages must render even with empty
  tables. Pretty URLs handled by `tools/router.php` / `.htaccess` (`/programs/{slug}`, `/blog/{slug}`, `/events/{slug}`,
  `/notices/{slug}`, `/gallery/{slug}`, `/page/{slug}`, `/{slug}` → `page.php`, `/verify-certificate/{no}`).
- Public form posts go to `api/routes/public.php` (`['auth' => false]`, CSRF from `<meta name="csrf-token">`,
  `rate_limit()`, server-side validation, store + notify admins).

## Website (React islands + CSS3 motion)
Full reference: `docs/WEBSITE.md`; live examples of every island/effect/helper: `/site-kit.php` (dev only — copy from it).
- **Architecture**: PHP renders every page (SEO, instant paint); `frontend/site` (React 18 + TS, built by
  `frontend/vite.site.config.ts` → `assets/site`) enhances it. Entry `site/main.tsx` (~11 KB gzip, no React) runs the
  vanilla enhancements in `site/core/*` on every page and mounts **islands**: `<div data-island="Name"
  data-props="{json}">server fallback</div>` → lazy chunk from `site/islands/index.ts`, mounted near the viewport
  (`eager` = immediately), error-isolated (fallback kept on failure). React is only downloaded on pages with islands.
- **Commands**: `cd frontend && npm run dev:site` (Vite on :5174 with HMR — PHP uses it automatically when
  `config/local.php` → `app.site_dev_server` is set and the port answers, else the built manifest, else
  `assets/js/main.js` = no-bundle fallback); `npm run build:site` (writes only `assets/site`, safe to run);
  `npm run typecheck:site`. Styles live only in `assets/css/src/style.css` → run `bash tools/build-css.sh` after adding
  classes in PHP **or** `frontend/site/**` (it also appends `assets/css/src/keyframes-webkit.css`).
- **PHP helpers** (`includes/config.php`, `includes/components.php`): `island($name, $props, $fallbackHtml,
  ['eager'=>true,'tag'=>'span',...])`; `page_hero(..., $features, $opts)` (`parallax`, `pattern`, `glass`, `script`,
  `actions`, `eyebrow`, `size`, `align`); `site_header` keys `cursor`, `motion`, `loader`, `preload`; blocks
  `section_open/section_close`, `reveal_attr`, `button_link`, `program_card`, `feature_tile`, `stat_card`, `cta_band`,
  `overlay_card`, `check_list`, `glass_chip`; island wrappers that render fallback + island in one call:
  `hero_slider`, `program_explorer`, `carousel`, `testimonial_slider`, `logo_marquee`, `stat_counter`, `typing_text`,
  `faq_accordion`, `content_tabs`, `video_lightbox`, `gallery_grid`, `enquiry_form`, `skeleton_list`,
  `auto_scroll_cards`, `progress_steps`. Fallback children of `[data-slot="x"]` reach the island as `slots.x`.
- **Data attributes** (vanilla, any page): `data-reveal="fade-up|zoom-in|slide-left|…"` + `data-reveal-delay`,
  `data-stagger="80"`, `data-parallax="0.15"`, `data-magnetic`, `data-tilt`, `data-count` (+prefix/suffix/decimals),
  `data-progress`, `data-lightbox[="group"]`, `data-modal-open="#id"`/`data-modal`/`data-modal-close`,
  `data-accordion` on `<details>`, `data-filter`/`data-filter-item`, `data-slider`, `data-marquee`, `form data-ajax-form`.
- **CSS utilities**: `.glass(-dark|-light) .bg-animated-gradient .pattern-dots|grid(-light) .animate-pattern-drift
  .float-slow .float-rotate .blob .glow .pulse-ring .img-zoom .overlay-gradient .text-gradient .card-lift .btn-shine
  .underline-grow .tilt-hover .skeleton .typing-dots .progress-track/.progress-fill .animate-fade-up …` (each keyframe
  also as `@-webkit-keyframes`). Dynamically built class names must be safelisted (`tailwind.config.cjs`) or appear
  literally in a scanned file.
- **Rules**: animate transform/opacity only; ≤ 700 ms UI motion; everything static under prefers-reduced-motion and
  in automated browsers (Playwright screenshots show final states); islands must render the same footprint as their
  fallback (no CLS); LCP image eager + `preload`, everything else `loading="lazy"`; autoplay pauses on hover/focus,
  hidden tab and off-screen, with a pause control; keyboard + ARIA for menus, sliders, tabs, accordions, modals (focus
  trap, Esc). Don't put `data-reveal` on React-owned elements (islands animate themselves). Keep header/footer hook
  ids/attributes (`#site-header`, `#mobile-menu`, `#site-search`, `data-back-to-top`, …) — `main.js` mirrors them.
- **Add an island**: `frontend/site/islands/Name.tsx` (default export, typed props, `useStaticMode()`), register in
  `islands/index.ts`, render via `island()` (or a wrapper in `includes/components.php`) with a same-sized fallback,
  document props in `docs/WEBSITE.md`, demo in `site-kit.php`, then `npm run typecheck:site` + `bash tools/build-css.sh`.

## Database rules
- Never edit the existing `database/schema/01–12_*.sql` files. Schema changes go in your own new file
  `database/schema/NN_<unit>.sql` (number assigned in your task) with plain, portable `ALTER TABLE … ADD COLUMN …` /
  `CREATE TABLE …` statements that run correctly after 01–12 on a fresh install; apply them once to the dev DB with
  the mysql CLI. Keep FKs and indexes consistent with the existing style (InnoDB, utf8mb4_unicode_ci).
- Demo data: your own seeder `database/seed/NN_<unit>.php` (number assigned) returning `function (array $opts): void`.
  Deterministic (`require_once __DIR__ . '/lib/demo_data.php'; demo_seed(<int>)`), realistic Indian institute data
  tied to existing students/faculty/programs, dates around the 2026-27 session (today is 2026-10-07), bulk inserts
  (`demo_bulk_insert`). Must be re-runnable: first delete rows of the tables it owns (children before parents).
  Volumes: enough for charts/pagination to look real, not millions of rows.
- **Never** run `tools/install.php` (it drops every table) and never truncate tables you don't own.

## Working in parallel with other agents (shared working tree)
- Edit only the files your task assigns to you; create new files only inside your owned paths. If something outside
  your scope is broken or missing, work around it locally and report it in your final summary.
- Never run `npm run build` / `vite build` (it wipes `admin/build`; the integrator builds once). Type-check instead.
- Never use git commands that change state (commit, checkout, stash, reset, clean). Never kill processes you did not
  start; the PHP server on :8000 is shared.
- `npx tsc -b --noEmit` checks the whole SPA — fix errors in your files only; errors in other modules are someone
  else's work in progress.

## Definition of done (per screen)
Every button works against the real backend (no fake buttons, no placeholders, no TODOs); create/edit/delete/
search/filter/sort/export/import/print paths exercised via `tools/api.php` and screenshots; validation errors shown
inline; permissions enforced server-side; activity logged; empty/loading/error states; desktop + mobile + dark mode
look right; zero console errors; `php -l` clean; type-check clean for your files.
