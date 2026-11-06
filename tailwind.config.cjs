/**
 * Tailwind build for the PUBLIC WEBSITE ("Design v3": premium academic institution meets modern technology
 * university — deep navy + orange-red + white + warm greys, sans-serif only; see docs/DESIGN.md).
 *   tools/build-css.sh   ->  assets/css/style.css   (this config)
 *                        ->  assets/css/print.css   (tailwind.print.config.cjs — printed documents keep the navy/green
 *                                                    GIMT preset; linked by print_layout_start() in app/components.php)
 * Scans the PHP pages + includes and the website React islands / TS enhancements (frontend/site).
 * The admin SPA has its own build (frontend/tailwind.config.js) and stays navy/green.
 *
 * Palette (WCAG-checked):
 *   brand-*  -> NAVY scale (preset: 900 = #0B2A5B headings, dark bands, active pills; 950 = #071A3B darkest bands)
 *   accent-* -> ORANGE-RED scale: 500 = #F05A28 vivid (decorative accents, large display text, icons, scribbles),
 *               600 = #D03E16 filled buttons (white text 4.79:1), 700 = #B83511 hover + small text/links on white
 *   named    -> navy, flame (vivid/fill/hover/active/text/tint), warm (#F5F2EE sections, #EDE8E2 cards/borders), body
 *               (#4A5568), muted, sun (urgency strips/tags only), whatsapp (official #25D366, WhatsApp controls only), ribbon, subtle pastels,
 *               hue-{ug|pg|diploma|cert|mgt|tech|ca|com|sci|skill|other|all}(-tint|-ink) colour map (e.g. bg-hue-pg-tint text-hue-pg-ink).
 * Mirrored as CSS custom properties on :root in assets/css/src/style.css (--c-navy, --c-flame …).
 * Fonts: font-display = Plus Jakarta Sans (bold editorial headings + stat numbers), font-sans = Inter. No serif.
 */
const designV3 = {
  colors: {
    accent: {
      50: '#FEF3EF', 100: '#FDE4DA', 200: '#FAC6B2', 300: '#F6A083', 400: '#F37A52', 500: '#F05A28',
      600: '#D03E16', 700: '#B83511', 800: '#962B0F', 900: '#7A2410', 950: '#431006',
    },
    navy: { DEFAULT: '#0B2A5B', deep: '#071A3B', 800: '#133266', soft: '#1F4A94', light: '#DBE6F6' },
    flame: { DEFAULT: '#F05A28', fill: '#D03E16', hover: '#B83511', active: '#962B0F', text: '#C93C14', tint: '#FDEDE7' },
    warm: { DEFAULT: '#F5F2EE', 2: '#EDE8E2', line: '#E2DAD0', 50: '#FAF8F5' },
    body: '#4A5568',
    muted: '#6B7280',
    sun: { DEFAULT: '#FDD900', light: '#FFF4B3' },
    // yellow-gold marker highlights (.hl-gold / .pill-gold); text on gold is always navy #0B2A5B (≈ 10:1)
    gold: { DEFAULT: '#FFC93C', deep: '#F5B300', soft: '#FFF3CF' },
    ribbon: { DEFAULT: '#C8202C', dark: '#951820' },
    whatsapp: { DEFAULT: '#25D366', hover: '#1EBE57', dark: '#128C7E' },
    // colour map (accents only) — mirrors the --hue-* custom properties / site_hues() / frontend/site/lib/hues.ts
    hue: {
      ug: { DEFAULT: '#0369A1', tint: '#E0F2FE', ink: '#0C4A6E' },
      pg: { DEFAULT: '#6D28D9', tint: '#EDE9FE', ink: '#4C1D95' },
      diploma: { DEFAULT: '#0F766E', tint: '#CCFBF1', ink: '#134E4A' },
      cert: { DEFAULT: '#B45309', tint: '#FEF3C7', ink: '#78350F' },
      mgt: { DEFAULT: '#D03E16', tint: '#FDEDE7', ink: '#7A2410' },
      tech: { DEFAULT: '#1D4ED8', tint: '#DBEAFE', ink: '#1E3A8A' },
      ca: { DEFAULT: '#7E22CE', tint: '#F3E8FF', ink: '#581C87' },
      com: { DEFAULT: '#047857', tint: '#D1FAE5', ink: '#064E3B' },
      sci: { DEFAULT: '#0E7490', tint: '#CFFAFE', ink: '#164E63' },
      skill: { DEFAULT: '#BE123C', tint: '#FFE4E6', ink: '#881337' },
      other: { DEFAULT: '#4338CA', tint: '#E0E7FF', ink: '#312E81' },
      all: { DEFAULT: '#0B2A5B', tint: '#EFF4FB', ink: '#0B2A5B' },
    },
    // subtle card accents only
    lavender: '#E7E1FF',
    sky: '#DCEBFB',
    aqua: '#D9F6F4',
    sand: '#F3E9DC',
    blush: '#FBE4EC',
    pistachio: '#ECF2CF',
  },
};

/* ------------------------------------------------------------------------------------------------------------------
 * DARK THEME colour map (website Light / Dark / System — includes/partials/theme.php sets <html data-theme="dark">).
 * Utilities read a CSS variable whose fallback is the normal light colour, e.g.
 *   .text-slate-600 { color: rgb(var(--dk-text-slate-600, 71 85 105) / var(--tw-text-opacity, 1)) }
 * so the light theme compiles to exactly the same colours, while the plugin below defines the dark values on
 * [data-theme="dark"] (screen only — printing is always light). Every variant (hover:, lg:, group-hover:, /opacity)
 * follows automatically. Maps are per CSS property because one light colour plays different roles in the dark:
 *   bg-white = card surface (dark navy) but text-white stays white; bg-brand-900 = navy fill (lifted navy) but
 *   text-brand-900 = heading (near-white); border-brand-900 = outline (light navy).
 * Translucent white (bg-white/10, border-white/15 …: highlights on navy bands) stays white in both themes.
 * Light scopes (always light inside, e.g. yellow urgency strips, recruiter logo tiles, .btn-white, .light-scope):
 * LIGHT_SCOPES below reset the variables to their light fallbacks. Component CSS: assets/css/src/style.css "Dark theme".
 * Contrast (dark): body #B4BFCF 8.9:1 · muted #98A4B8 6.5:1 · subtle #7F8CA2 4.7:1 on #111B2F; headings #F1F4FA 15:1;
 * orange-red text #FF9B72 7.6:1; filled CTA #D03E16 keeps white text 4.8:1; navy panels (bg-brand-900) #15336A keep
 * white/55 text ≥ 4.5:1 as in the light theme; navy buttons / active pills are lifted to #1F4683 in style.css.
 * ------------------------------------------------------------------------------------------------------------------ */
const brandScale = require('./tailwind.preset.cjs').theme.extend.colors.brand;
const tw = { // Tailwind default palette (light values of the shades the website uses)
  slate: { 50: '#f8fafc', 100: '#f1f5f9', 200: '#e2e8f0', 300: '#cbd5e1', 400: '#94a3b8', 500: '#64748b', 600: '#475569', 700: '#334155', 800: '#1e293b', 900: '#0f172a' },
  emerald: { 50: '#ecfdf5', 100: '#d1fae5', 200: '#a7f3d0', 300: '#6ee7b7', 600: '#059669', 700: '#047857', 800: '#065f46', 900: '#064e3b' },
  amber: { 50: '#fffbeb', 100: '#fef3c7', 200: '#fde68a', 300: '#fcd34d', 600: '#d97706', 700: '#b45309', 800: '#92400e', 900: '#78350f', 950: '#451a03' },
  red: { 50: '#fef2f2', 100: '#fee2e2', 200: '#fecaca', 300: '#fca5a5', 600: '#dc2626', 700: '#b91c1c', 800: '#991b1b', 900: '#7f1d1d' },
  blue: { 50: '#eff6ff', 100: '#dbeafe', 200: '#bfdbfe', 600: '#2563eb', 700: '#1d4ed8', 800: '#1e40af' },
  violet: { 50: '#f5f3ff', 100: '#ede9fe', 200: '#ddd6fe', 600: '#7c3aed', 700: '#6d28d9' },
  cyan: { 50: '#ecfeff', 100: '#cffafe', 200: '#a5f3fc', 600: '#0891b2', 700: '#0e7490' },
  rose: { 50: '#fff1f2', 100: '#ffe4e6', 200: '#fecdd3', 600: '#e11d48', 700: '#be123c', 800: '#9f1239' },
  orange: { 50: '#fff7ed', 100: '#ffedd5', 600: '#ea580c', 700: '#c2410c' },
  white: '#ffffff',
};
const light = { ...tw, brand: brandScale, ...designV3.colors };
const DARK = {
  bg: {
    white: '#111B2F',
    slate: { 50: '#152036', 100: '#1A263D', 200: '#233150', 300: '#2F3E5E', 400: '#55637E' },
    brand: { 50: '#172748', 100: '#1D2F55', 800: '#1D3F7C', 900: '#15336A', 950: '#0A1530' },
    accent: { 50: '#2C1D1D', 100: '#3A231F', 200: '#4A2B23' },
    warm: { DEFAULT: '#0B1426', 2: '#19243B', 50: '#0D1729', line: '#26344F' },
    navy: { DEFAULT: '#15336A', deep: '#050B19' },
    flame: { tint: '#33201B' },
    sun: { light: '#3A3313' },
    emerald: { 50: '#0D2925', 100: '#11352E' }, amber: { 50: '#2C2312', 100: '#3A2E15' }, red: { 50: '#2D171A', 100: '#3B1D21' },
    blue: { 50: '#142649', 100: '#1A305C' }, violet: { 50: '#211B44', 100: '#2A2356' }, cyan: { 50: '#0D2935', 100: '#113544' },
    rose: { 50: '#2D1622', 100: '#3B1B2C' }, orange: { 50: '#2E1E13', 100: '#3C2717' },
    lavender: '#221D44', sky: '#162B47', aqua: '#0E2D33', sand: '#2A2318', blush: '#2E1925', pistachio: '#232A14',
    hue: {
      ug: { tint: '#0E2A40' }, pg: { tint: '#221C48' }, diploma: { tint: '#0C2D2B' }, cert: { tint: '#32250E' }, mgt: { tint: '#33201A' },
      tech: { tint: '#162A55' }, ca: { tint: '#291B46' }, com: { tint: '#0D2D24' }, sci: { tint: '#0B2B36' }, skill: { tint: '#351725' },
      other: { tint: '#1C2050' }, all: { tint: '#16284A' },
    },
  },
  text: {
    brand: { 100: '#22365C', 500: '#8EAADF', 600: '#A3BAE3', 700: '#BCCDEA', 800: '#D7E0F1', 900: '#F1F4FA', 950: '#F1F4FA' },
    slate: { 300: '#58667F', 400: '#7F8CA2', 500: '#98A4B8', 600: '#B4BFCF', 700: '#CDD5E2', 800: '#E1E7F0', 900: '#F1F4F9' },
    body: '#B4BFCF', muted: '#98A4B8',
    accent: { 300: '#FFB091', 400: '#FF8A60', 500: '#FF7548', 600: '#FF865A', 700: '#FF9B72', 800: '#FFAF8E', 900: '#FFC2A8' },
    flame: { DEFAULT: '#FF7548', text: '#FF9B72' },
    navy: { DEFAULT: '#F1F4FA', deep: '#F1F4FA' },
    emerald: { 600: '#34D399', 700: '#6EE7B7', 800: '#A7F3D0', 900: '#D1FAE5' },
    amber: { 600: '#FBBF24', 700: '#FCD34D', 800: '#FDE68A', 900: '#FEF3C7', 950: '#FEF3C7' },
    red: { 600: '#F87171', 700: '#FCA5A5', 800: '#FECACA', 900: '#FEE2E2' },
    blue: { 600: '#60A5FA', 700: '#93C5FD', 800: '#BFDBFE' }, violet: { 600: '#A78BFA', 700: '#C4B5FD' },
    cyan: { 600: '#22D3EE', 700: '#67E8F9' }, rose: { 600: '#FB7185', 700: '#FDA4AF', 800: '#FECDD3' }, orange: { 600: '#FDBA74', 700: '#FED7AA' },
    hue: {
      ug: { DEFAULT: '#7DD3FC', ink: '#BAE6FD' }, pg: { DEFAULT: '#C4B5FD', ink: '#DDD6FE' }, diploma: { DEFAULT: '#5EEAD4', ink: '#99F6E4' },
      cert: { DEFAULT: '#FCD34D', ink: '#FDE68A' }, mgt: { DEFAULT: '#FF9B72', ink: '#FFC2A8' }, tech: { DEFAULT: '#93C5FD', ink: '#BFDBFE' },
      ca: { DEFAULT: '#D8B4FE', ink: '#E9D5FF' }, com: { DEFAULT: '#6EE7B7', ink: '#A7F3D0' }, sci: { DEFAULT: '#67E8F9', ink: '#A5F3FC' },
      skill: { DEFAULT: '#FDA4AF', ink: '#FECDD3' }, other: { DEFAULT: '#A5B4FC', ink: '#C7D2FE' }, all: { DEFAULT: '#DBE6F6', ink: '#DBE6F6' },
    },
  },
  border: {
    white: '#111B2F',
    slate: { 100: '#1D2A42', 200: '#263452', 300: '#33446A', 400: '#4B5B7E', 500: '#64749A' },
    brand: { 100: '#24365C', 200: '#2E4372', 300: '#3C5689', 400: '#5677B4', 500: '#6688C6', 800: '#8FA6D2', 900: '#A9BCE0' },
    warm: { DEFAULT: '#1A253B', 2: '#243251', line: '#2D3C5C' },
    accent: { 100: '#4A2A20', 200: '#5C3123', 300: '#7A3D28' },
    emerald: { 100: '#154236', 200: '#1B5444', 300: '#237058' }, amber: { 100: '#4A3815', 200: '#5C4416', 300: '#7A5A1A' },
    red: { 100: '#4A2024', 200: '#5C2428', 300: '#7A2E33' }, blue: { 200: '#24407A' }, cyan: { 200: '#155066' },
    violet: { 200: '#3B2F7A' }, rose: { 200: '#5C2338' },
  },
};
// gradients (from-/via-/to-) only remap the light washes; navy photo overlays keep their colours
const GRADIENT_KEYS = { white: true, slate: [50], brand: [50, 100], accent: [50, 100, 200], warm: ['DEFAULT', 2, 50] };
// subtrees that stay light in the dark theme (their own text colours must keep contrast on a light fill)
const LIGHT_SCOPES = ['.light-scope', '.btn-white', '.bg-sun', '.logo-tile', '.tag-popular', '.program-ribbon', '.scholar-value', '.enquiry-strip', '.sticker-sun', '.sticker-white', '.ribbon-light', '.pill-gold', '.hl-gold', '.bg-gold'];

// light-theme TEXT tweaks (WCAG AA for small text on every website surface): Tailwind's slate-500 #64748b is only
// 4.26:1 on warm grey #F5F2EE (4.4:1 on slate-100) → #5B677B (5.1:1 on warm, 4.7:1 on warm-2, 5.7:1 on white).
// slate-400 (#94a3b8, 2.6:1) is for icons / separators only; slate-400 placeholders are re-pointed to slate-500 in style.css.
const LIGHT_TEXT = { 'slate.500': '#5B677B' };
const channels = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16)).join(' ');
const lightHex = (path) => path.reduce((o, k) => (o && typeof o === 'object' ? o[k] : undefined), light);

/* ------------------------------------------------------------------------------------------------------------------
 * ADMIN BRAND COLOURS (Settings › Website › Brand colours & fonts, client request 2026-10-26). Every brand-* / accent-*
 * utility and the named navy / flame tokens read a channel variable (--brand-50…950, --accent-50…950, --flame-text,
 * --flame-tint) whose fallback is today's colour, so the default build renders exactly as before. When an admin picks
 * other colours, app/services/site_brand.php prints those variables (shades generated from the pick) right after
 * style.css. designV3 (exported for print.css) stays hex: printed documents never change.
 * ------------------------------------------------------------------------------------------------------------------ */
const VAR_OF = { // named tokens → the brand variable that plays their role
  'navy.DEFAULT': 'brand-900', 'navy.deep': 'brand-950', 'navy.800': 'brand-800', 'navy.soft': 'brand-600', 'navy.light': 'brand-100',
  'flame.DEFAULT': 'accent-500', 'flame.fill': 'accent-600', 'flame.hover': 'accent-700', 'flame.active': 'accent-800',
  'flame.text': 'flame-text', 'flame.tint': 'flame-tint', 'hue.mgt.DEFAULT': 'accent-600', 'hue.all.DEFAULT': 'brand-900', 'hue.all.ink': 'brand-900',
};
const brandVarName = (path) => (path[0] === 'brand' || path[0] === 'accent' ? `${path[0]}-${path[1]}` : VAR_OF[path.join('.')] || null);
const varColor = (name, hex) => `rgb(var(--${name}, ${channels(hex)}) / <alpha-value>)`;
const varScale = (name, scale) => Object.fromEntries(Object.entries(scale).map(([k, hex]) => [k, varColor(`${name}-${k}`, hex)]));
const named = (group) => Object.fromEntries(Object.entries(designV3.colors[group]).map(([k, hex]) => [k, VAR_OF[`${group}.${k}`] ? varColor(VAR_OF[`${group}.${k}`], hex) : hex]));
const siteColors = {
  ...designV3.colors,
  brand: varScale('brand', brandScale),
  accent: varScale('accent', designV3.colors.accent),
  navy: named('navy'),
  flame: named('flame'),
  hue: { ...designV3.colors.hue, mgt: { ...designV3.colors.hue.mgt, DEFAULT: varColor('accent-600', designV3.colors.hue.mgt.DEFAULT) },
    all: { ...designV3.colors.hue.all, DEFAULT: varColor('brand-900', designV3.colors.hue.all.DEFAULT), ink: varColor('brand-900', designV3.colors.hue.all.ink) } },
};
const darkVars = {};
function mapProp(prop, gradientOnly = false) {
  const walk = (node, path) => {
    if (typeof node === 'string') {
      let lh = (prop === 'text' && LIGHT_TEXT[path.join('.')]) || lightHex(path);
      if (typeof lh === 'object') lh = lh.DEFAULT;
      if (!lh) throw new Error(`tailwind.config.cjs dark map: no light colour for ${path.join('.')}`);
      const name = `--dk-${prop}-${path.filter((k) => k !== 'DEFAULT').join('-')}`;
      darkVars[name] = channels(node);
      // light fallback: the admin brand variable when this colour is a brand / accent role, else the fixed colour
      const vn = brandVarName(path);
      const fallback = vn ? `var(--${vn}, ${channels(lh)})` : channels(lh);
      const value = (op) => `rgb(var(${name}, ${fallback})${op === undefined ? '' : ` / ${op}`})`;
      if (path[0] === 'white') {
        // translucent white (highlights on navy) stays white; opaque white becomes the dark surface
        return ({ opacityValue }) => {
          const n = parseFloat(opacityValue);
          return !Number.isNaN(n) && n < 0.35 ? `rgb(255 255 255 / ${opacityValue})` : value(opacityValue);
        };
      }
      return ({ opacityValue }) => value(opacityValue);
    }
    const out = {};
    for (const [k, v] of Object.entries(node)) {
      if (gradientOnly) {
        const allow = GRADIENT_KEYS[path.length ? path[0] : k];
        if (!allow) continue;
        if (!path.length && Array.isArray(allow) && typeof v === 'object') {
          const sub = {};
          for (const s of allow) if (v[s] !== undefined) sub[s] = walk(v[s], [k, String(s)]);
          out[k] = sub;
          continue;
        }
      }
      out[k] = walk(v, [...path, k]);
    }
    return out;
  };
  return walk(DARK[prop], []);
}
const darkBg = mapProp('bg');
const darkText = mapProp('text');
const darkBorder = mapProp('border');
const darkGradient = mapProp('bg', true); // same --dk-bg-* variables
const darkPlugin = ({ addBase }) => {
  const reset = Object.fromEntries(Object.keys(darkVars).map((k) => [k, 'initial']));
  addBase({
    '@media screen': {
      '[data-theme="dark"]': darkVars,
      [LIGHT_SCOPES.map((s) => `[data-theme="dark"] ${s}`).join(', ')]: reset,
      // focus-ring offsets sit on the dark page (not white); bare `border` classes get a dark hairline (utilities win)
      '[data-theme="dark"] *, [data-theme="dark"] ::before, [data-theme="dark"] ::after': { '--tw-ring-offset-color': '#111B2F', 'border-color': '#263452' },
      // … except inside light scopes (same specificity, later in the base layer; utilities still win)
      [LIGHT_SCOPES.map((s) => `[data-theme="dark"] :where(${s}) *, [data-theme="dark"] :where(${s}) ::before, [data-theme="dark"] :where(${s}) ::after`).join(', ')]: { '--tw-ring-offset-color': '#fff', 'border-color': '#e5e7eb' },
    },
  });
};

module.exports = {
  presets: [require('./tailwind.preset.cjs')],
  content: ['./*.php', './includes/**/*.php', './app/**/*.php', './admin/print/**/*.php', './install/**/*.php', './assets/js/**/*.js', './frontend/site/**/*.{ts,tsx}'],
  // dark: variants follow the website theme attribute (screen only) instead of the admin preset's .dark class
  darkMode: ['variant', '@media screen { &:where([data-theme="dark"], [data-theme="dark"] *) }'],
  theme: {
    extend: {
      colors: siteColors,
      backgroundColor: darkBg,
      textColor: darkText,
      borderColor: darkBorder,
      ringColor: darkBorder,
      gradientColorStops: darkGradient,
      boxShadow: {
        card: '0 1px 2px rgba(11,42,91,.05), 0 6px 18px -8px rgba(11,42,91,.12)',
        soft: '0 14px 34px -16px rgba(11,42,91,.28)',
        pop: '0 26px 60px -20px rgba(7,26,59,.38)',
        flame: '0 12px 26px -12px rgba(208,62,22,.55)',
      },
      borderRadius: {
        '4xl': '2rem',
        '5xl': '2.5rem',
      },
      letterSpacing: {
        display: '-0.025em',
      },
      // metric-matched local fallbacks right after each web font (style.css "… Fallback" @font-face: size-adjust +
      // ascent/descent overrides measured against Arial) — the font-display: swap no longer shifts the layout
      // admin font choice (site_brand.php prints --font-sans / --font-display); the default stacks are the fallbacks
      fontFamily: {
        sans: ['var(--font-sans, Inter, "Inter Fallback", ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif)'],
        display: ['var(--font-display, "Plus Jakarta Sans", "Plus Jakarta Sans Fallback", Inter, "Inter Fallback", ui-sans-serif, system-ui, sans-serif)'],
      },
    },
  },
  safelist: [
    'lg:grid-cols-3', 'lg:grid-cols-4', 'md:grid-cols-2', 'md:grid-cols-3',
    // Website motion library + component classes (assets/css/src/style.css) documented in docs/WEBSITE.md —
    // always shipped so page builders (and CMS content) can use them even before a PHP file mentions them.
    {
      pattern:
        /^(animate-(fade-up|fade-down|fade-in|zoom-in|slide-in-right|pop-in|float|ken-burns|pattern-drift|blob)|delay-[1-4]|float-(slow|delayed|rotate)|spin-slow|bounce-subtle|glow|glass(-dark|-light|-panel)?|bg-animated-gradient(-light)?|pattern-(dots|grid)(-light)?|pattern-(fade|layer)|overlay-(gradient|navy|ink)|text-gradient(-light)?|card-lift|img-zoom|tilt-hover|underline-grow|btn-shine|ripple|progress-(track|fill)|skeleton|typing-dots|pulse-ring|draw-line|blob|scrollbar-none|text-balance|bg-grid-paper|bg-grid-ink|bg-grid-navy|bg-mesh-navy|bg-mesh-warm|grain|gradient-border|accent-line|twinkle|wiggle|spotlight)$/,
    },
    {
      pattern:
        /^(btn-(primary|accent|orange|apply|success|navy|ink|explore|secondary|outline|brochure|outline-light|white|ghost|danger|soft|callback|sm|lg)|tint-(blue|green|cyan|amber|violet|rose|navy|ink|orange|sky|aqua|sun|lavender|blush|pistachio|sand|cream|warm)|section-(light|navy|ink|tight|cream|sand|surface|warm)|pastel-(lavender|sky|aqua|cream|sand|blush|pistachio|sun|warm|navy)|eyebrow-line|link-arrow|stretched-link|chip-count|date-tile(-day|-mon)?|form-(message|message-success|message-error|alert|check|required))$/,
    },
  ],
  plugins: [darkPlugin],
  // shared with tailwind.print.config.cjs (named tokens only — prints keep the navy/green preset)
  designV3,
  darkMap: DARK,
};
