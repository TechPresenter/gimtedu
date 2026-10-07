/**
 * Tailwind build for the public website, print views and server-rendered PHP pages.
 *   tools/build-css.sh   ->  assets/css/style.css
 * Also scans the website React islands / TS enhancements (frontend/site) so their Tailwind classes are compiled.
 * (The admin SPA has its own build in frontend/.)
 */
module.exports = {
  presets: [require('./tailwind.preset.cjs')],
  content: ['./*.php', './includes/**/*.php', './app/**/*.php', './admin/print/**/*.php', './install/**/*.php', './assets/js/**/*.js', './frontend/site/**/*.{ts,tsx}'],
  safelist: [
    'lg:grid-cols-3', 'lg:grid-cols-4', 'md:grid-cols-2', 'md:grid-cols-3',
    // Website motion library + component classes (assets/css/src/style.css) documented in docs/WEBSITE.md —
    // always shipped so page builders (and CMS content) can use them even before a PHP file mentions them.
    {
      pattern:
        /^(animate-(fade-up|fade-down|fade-in|zoom-in|slide-in-right|pop-in|float|ken-burns|pattern-drift|blob)|delay-[1-4]|float-(slow|delayed|rotate)|spin-slow|bounce-subtle|glow|glass(-dark|-light)?|bg-animated-gradient(-light)?|pattern-(dots|grid)(-light)?|pattern-(fade|layer)|overlay-(gradient|navy)|text-gradient(-light)?|card-lift|img-zoom|tilt-hover|underline-grow|btn-shine|ripple|progress-(track|fill)|skeleton|typing-dots|pulse-ring|draw-line|blob|scrollbar-none|text-balance)$/,
    },
    {
      pattern:
        /^(btn-(primary|accent|success|navy|secondary|outline|outline-light|white|ghost|danger|sm|lg)|tint-(blue|green|cyan|amber|violet|rose|navy)|section-(light|navy|tight)|eyebrow-line|link-arrow|stretched-link|chip-count|date-tile(-day|-mon)?|form-(message|message-success|message-error|alert|check|required))$/,
    },
  ],
  plugins: [],
};
