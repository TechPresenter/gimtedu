import type { SiteRuntime } from '../lib/types';
import { onReady } from './dom';
import { initHeader } from './header';
import { initIslands } from './islands';
import { initLightbox, openLightbox } from './lightbox';
import { initLoader } from './loader';
import { initMenus } from './menu';
import { initCounters, initLazyImages, initMarquee, initParallax, initProgress, initReveal } from './motion';
import { initOverlays } from './overlays';
import { initCursor, initMagnetic, initRipple, initTilt } from './pointer';
import { initAccordions, initFilters, initForms, initSliders } from './widgets';

/** Enhancements that can run on any subtree (initial page, island content, injected HTML). Idempotent. */
export function enhance(root: ParentNode = document): void {
  const steps: [string, (r: ParentNode) => void][] = [
    ['lazy', initLazyImages],
    ['reveal', initReveal],
    ['counters', initCounters],
    ['progress', initProgress],
    ['accordions', initAccordions],
    ['filters', initFilters],
    ['sliders', initSliders],
    ['forms', initForms],
    ['marquee', initMarquee],
    ['magnetic', initMagnetic],
    ['tilt', initTilt],
  ];
  // error isolation: one failing behaviour never blocks the others
  for (const [name, fn] of steps) {
    try {
      fn(root);
    } catch (err) {
      console.error(`[site] ${name} enhancement failed`, err);
    }
  }
}

/** Page-level behaviours (run once). */
export function boot(): void {
  const html = document.documentElement;
  const runtime: SiteRuntime = {
    version: '1.0.0',
    enhance,
    mountIslands: (root?: ParentNode) => initIslands(root),
    openLightbox,
  };
  window.__gimtSite = runtime;
  html.classList.add('js');
  html.classList.remove('no-motion');

  onReady(() => {
    const once: [string, () => void][] = [
      ['loader', initLoader],
      ['header', initHeader],
      ['menus', initMenus],
      ['overlays', initOverlays],
      ['ripple', initRipple],
      ['lightbox', initLightbox],
      ['cursor', initCursor],
      ['parallax', () => initParallax()],
    ];
    for (const [name, fn] of once) {
      try {
        fn();
      } catch (err) {
        console.error(`[site] ${name} failed`, err);
      }
    }
    enhance(document);
    html.classList.add('site-ready');
    try {
      initIslands();
    } catch (err) {
      console.error('[site] islands failed', err);
    }
  });
}
