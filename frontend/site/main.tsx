/**
 * GIMT public website bundle — entry point (kept tiny, no React here).
 *
 *  1. Vanilla TypeScript enhancements for every page (sticky header, menus, drawer, search, reveal, parallax,
 *     counters, ripple, magnetic buttons, cursor, lightbox, accordions, forms …) — see ./core.
 *  2. React "islands": server-rendered placeholders (<div data-island="Name" data-props='{…}'>fallback</div>)
 *     are replaced by lazily-loaded React components — see ./core/islands.ts and ./islands/index.ts.
 *
 * Loaded by includes/header.php + footer.php through site_assets_head()/site_assets_footer().
 */
import { boot } from './core/boot';

boot();
