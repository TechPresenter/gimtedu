import type { SiteRuntime } from '../lib/types';
import { onReady, yieldToMain } from './dom';
import { initHeader } from './header';
import { initIslands } from './islands';
import { initLightbox, openLightbox } from './lightbox';
import { initLoader } from './loader';
import { initAttention, initBlobs, initDismiss, initGleam, initSpotlight, initTextReveal } from './effects';
import { initMega } from './mega';
import { initTheme } from './theme';
import { initViewModes } from './viewmode';
import { initMenus } from './menu';
import { initCounters, initLazyImages, initMarquee, initParallax, initProgress, initReveal, initScribbles } from './motion';
import { initOverlays } from './overlays';
import { initEnquiryPopup } from './popup';
import { initCursor, initMagnetic, initRipple, initTilt } from './pointer';
import { initVoiceInputs } from './voice';
import { initAccordions, initFilters, initForms, initSliders } from './widgets';

/** Enhancement steps that can run on any subtree (initial page, island content, injected HTML). Idempotent. */
const ENHANCE_STEPS: [string, (r: ParentNode) => void][] = [
  ['lazy', initLazyImages],
  ['reveal', initReveal],
  ['text-reveal', initTextReveal],
  ['scribbles', initScribbles],
  ['blobs', initBlobs],
  ['dismiss', initDismiss],
  ['mega', initMega],
  ['theme', initTheme],
  ['view-modes', initViewModes],
  ['counters', initCounters],
  ['progress', initProgress],
  ['accordions', initAccordions],
  ['filters', initFilters],
  ['sliders', initSliders],
  ['forms', initForms],
  ['voice', initVoiceInputs],
  ['marquee', initMarquee],
  ['magnetic', initMagnetic],
  ['tilt', initTilt],
  ['spotlight', initSpotlight],
  ['attention', initAttention],
  ['gleam', initGleam],
];

/** Run one step, error-isolated, with a User Timing measure "gimt:<name>" (visible in DevTools / lab traces). */
function runStep(name: string, fn: () => void, kind: string): void {
  const t0 = performance.now();
  try {
    fn();
  } catch (err) {
    console.error(`[site] ${name} ${kind} failed`, err);
  }
  try {
    performance.measure(`gimt:${name}`, { start: t0, end: performance.now() });
  } catch {
    /* User Timing L3 unsupported */
  }
}

/** Enhancements on a subtree, synchronously (island content, injected HTML — small subtrees). */
export function enhance(root: ParentNode = document): void {
  for (const [name, fn] of ENHANCE_STEPS) runStep(name, () => fn(root), 'enhancement');
}

/**
 * Page-level start-up, split into slices of at most ~40 ms with a yield in between: on a slow phone the whole
 * start-up used to be one task of 1–3 s (blocking taps and delaying the first paint of late content). Steps that read
 * layout then find it already computed by the browser's own rendering step instead of forcing it.
 */
async function runSliced(steps: [string, () => void, string][]): Promise<void> {
  let slice = performance.now();
  for (const [name, fn, kind] of steps) {
    if (performance.now() - slice > 40) {
      await yieldToMain();
      slice = performance.now();
    }
    runStep(name, fn, kind);
  }
}

/** Page-level behaviours (run once). */
export function boot(): void {
  const html = document.documentElement;
  const runtime: SiteRuntime = {
    version: '2.0.0',
    enhance,
    mountIslands: (root?: ParentNode) => initIslands(root),
    openLightbox,
  };
  window.__gimtSite = runtime;
  html.classList.add('js');
  html.classList.remove('no-motion');

  onReady(() => {
    // islands first: their chunks start downloading while the enhancements below run
    runStep('islands', () => initIslands(), 'start-up');
    const once: [string, () => void, string][] = [
      ['loader', initLoader, 'start-up'],
      ['header', initHeader, 'start-up'],
      ['menus', initMenus, 'start-up'],
      ['overlays', initOverlays, 'start-up'],
      ['enquiry-popup', initEnquiryPopup, 'start-up'],
      ['ripple', initRipple, 'start-up'],
      ['lightbox', initLightbox, 'start-up'],
      ['cursor', initCursor, 'start-up'],
      ['parallax', () => initParallax(), 'start-up'],
    ];
    const enhanceSteps = ENHANCE_STEPS.map(([name, fn]) => [name, () => fn(document), 'enhancement'] as [string, () => void, string]);
    // what makes content visible first (lazy images, scroll reveal, heading reveal), then .site-ready (releases the
    // [data-stagger] start state, switches the header menus from CSS hover to JS), then everything else
    const first = enhanceSteps.filter(([name]) => ['lazy', 'reveal', 'text-reveal'].includes(name));
    const later = enhanceSteps.filter(([name]) => !['lazy', 'reveal', 'text-reveal'].includes(name));
    void runSliced([...first, ['site-ready', () => html.classList.add('site-ready'), 'start-up'], ...once, ...later]);
  });
}
