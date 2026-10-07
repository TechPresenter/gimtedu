/**
 * Tailwind build for the public website, print views and server-rendered PHP pages.
 *   tools/build-css.sh   ->  assets/css/style.css
 * (The admin SPA has its own build in frontend/.)
 */
module.exports = {
  presets: [require('./tailwind.preset.cjs')],
  content: ['./*.php', './includes/**/*.php', './app/**/*.php', './admin/print/**/*.php', './install/**/*.php', './assets/js/**/*.js'],
  safelist: ['lg:grid-cols-3', 'lg:grid-cols-4', 'md:grid-cols-2', 'md:grid-cols-3'],
  plugins: [],
};
