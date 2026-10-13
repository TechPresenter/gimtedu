/**
 * Supports classic PHP-style admin URLs (/admin/students.php, /admin/index.php) by rewriting them to the
 * SPA routes (/admin/students, /admin/) before the router initialises. Imported first in main.tsx.
 */
const path = window.location.pathname;
const m = path.match(/^(.*\/admin)\/([a-z0-9-]+)\.php$/i);
if (m) {
  const route = m[2].toLowerCase() === 'index' ? '' : m[2];
  window.history.replaceState(window.history.state, '', `${m[1]}/${route}${window.location.search}${window.location.hash}`);
}
export {};
