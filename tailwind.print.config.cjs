/**
 * Tailwind build for PRINTED DOCUMENTS (fee receipts, marksheets, ID cards, certificates, admission forms …).
 *   tools/build-css.sh  ->  assets/css/print.css   (linked by print_layout_start() in app/components.php)
 *
 * Prints keep the original GIMT navy/green look (tailwind.preset.cjs: brand = navy, accent = green, display font =
 * Plus Jakarta Sans) even though the public website (tailwind.config.cjs) re-maps accent to orange-red.
 * Same source stylesheet (assets/css/src/style.css); the named Design v3 tokens are added only so that @apply rules
 * referencing them compile here too.
 */
const site = require('./tailwind.config.cjs');
// eslint-disable-next-line no-unused-vars
const { accent, ...named } = site.designV3.colors;

module.exports = {
  presets: [require('./tailwind.preset.cjs')],
  content: ['./admin/print/**/*.php', './app/**/*.php'],
  theme: {
    extend: {
      colors: named,
      boxShadow: { flame: '0 12px 26px -12px rgba(208,62,22,.55)' },
      borderRadius: { '5xl': '2.5rem' },
      letterSpacing: { display: '-0.025em' },
    },
  },
  safelist: [
    'lg:grid-cols-3', 'lg:grid-cols-4', 'md:grid-cols-2', 'md:grid-cols-3',
    {
      pattern:
        /^(btn-(primary|accent|success|navy|secondary|outline|white|ghost|danger|sm|lg)|tint-(blue|green|cyan|amber|violet|rose|navy)|badge(-(green|red|amber|blue|purple|cyan|slate))?|form-(message|message-success|message-error|alert|check|required))$/,
    },
  ],
  plugins: [],
};
