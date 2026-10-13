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

module.exports = {
  presets: [require('./tailwind.preset.cjs')],
  content: ['./*.php', './includes/**/*.php', './app/**/*.php', './admin/print/**/*.php', './install/**/*.php', './assets/js/**/*.js', './frontend/site/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: designV3.colors,
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
  plugins: [],
  // shared with tailwind.print.config.cjs (named tokens only — prints keep the navy/green preset)
  designV3,
};
