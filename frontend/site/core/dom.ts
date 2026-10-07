/**
 * Tiny DOM + environment helpers shared by the vanilla enhancements and the React islands.
 * Keep this file dependency-free: it is part of the always-loaded entry chunk.
 */

export const $ = <T extends Element = HTMLElement>(sel: string, root: ParentNode = document): T | null =>
  root.querySelector<T>(sel);

export const $$ = <T extends Element = HTMLElement>(sel: string, root: ParentNode = document): T[] =>
  Array.from(root.querySelectorAll<T>(sel));

/** Elements matching `sel` inside root, including root itself when it matches. */
export function within<T extends Element = HTMLElement>(sel: string, root: ParentNode = document): T[] {
  const list = $$<T>(sel, root);
  if (root instanceof Element && root.matches(sel)) list.unshift(root as unknown as T);
  return list;
}

const mq = (q: string) => typeof window !== 'undefined' && !!window.matchMedia && window.matchMedia(q).matches;

/** User asked the OS for less motion. */
export const reducedMotion = (): boolean => mq('(prefers-reduced-motion: reduce)');

/** Precise pointer that can hover (mouse / trackpad) — cursor, magnetic and tilt effects only run here. */
export const finePointer = (): boolean => mq('(hover: hover) and (pointer: fine)');

/**
 * "Static" mode: render final states immediately (no entrance animations, counters at their end value, no loader).
 * Used for reduced motion and for automated browsers (Playwright screenshots / crawlers), and when the page
 * opts out with <html data-motion="off">.
 */
export function staticMode(): boolean {
  return (
    reducedMotion() ||
    (typeof navigator !== 'undefined' && (navigator as Navigator & { webdriver?: boolean }).webdriver === true) ||
    document.documentElement.getAttribute('data-motion') === 'off'
  );
}

/** CSRF token printed by includes/header.php. */
export const csrfToken = (): string => $<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';

export const clamp = (v: number, min: number, max: number) => Math.min(max, Math.max(min, v));

export const easeOutCubic = (t: number) => 1 - Math.pow(1 - t, 3);
export const easeOutExpo = (t: number) => (t === 1 ? 1 : 1 - Math.pow(2, -10 * t));

/** Run fn once the DOM is parsed. */
export function onReady(fn: () => void): void {
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn, { once: true });
  else fn();
}

/** Throttle a handler to one call per animation frame. */
export function rafThrottle<A extends unknown[]>(fn: (...args: A) => void): (...args: A) => void {
  let queued = false;
  let last: A;
  return (...args: A) => {
    last = args;
    if (queued) return;
    queued = true;
    requestAnimationFrame(() => {
      queued = false;
      fn(...last);
    });
  };
}

const FOCUSABLE =
  'a[href],area[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),iframe,[tabindex]:not([tabindex="-1"]),[contenteditable="true"]';

export function focusables(root: ParentNode): HTMLElement[] {
  return $$<HTMLElement>(FOCUSABLE, root).filter((el) => el.offsetParent !== null || el === document.activeElement);
}

/**
 * Keep Tab focus inside `root` while it is open. Returns a release function that also restores focus to the
 * element that was focused before.
 */
export function trapFocus(root: HTMLElement, initial?: HTMLElement | null): () => void {
  const previous = document.activeElement as HTMLElement | null;
  const onKey = (e: KeyboardEvent) => {
    if (e.key !== 'Tab') return;
    const items = focusables(root);
    if (!items.length) {
      e.preventDefault();
      return;
    }
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && (document.activeElement === first || !root.contains(document.activeElement))) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  };
  document.addEventListener('keydown', onKey);
  requestAnimationFrame(() => (initial ?? focusables(root)[0] ?? root).focus({ preventScroll: true }));
  return () => {
    document.removeEventListener('keydown', onKey);
    if (previous && document.contains(previous)) previous.focus({ preventScroll: true });
  };
}

let scrollLocks = 0;
/** Lock page scroll (nested-safe) without layout jump: compensates for the scrollbar width. */
export function lockScroll(): () => void {
  const html = document.documentElement;
  if (scrollLocks++ === 0) {
    const sbw = window.innerWidth - html.clientWidth;
    html.style.setProperty('--scrollbar-comp', `${sbw}px`);
    html.classList.add('scroll-locked');
  }
  let released = false;
  return () => {
    if (released) return;
    released = true;
    if (--scrollLocks === 0) {
      html.classList.remove('scroll-locked');
      html.style.removeProperty('--scrollbar-comp');
    }
  };
}

/**
 * Show/hide an overlay element with CSS transitions: removes `hidden`, then adds `is-open` on the next frame
 * (so the transition runs); on close removes `is-open` and re-adds `hidden` after the transition.
 */
export function toggleLayer(el: HTMLElement, open: boolean, duration = 320): void {
  window.clearTimeout(Number(el.dataset.layerTimer || 0));
  if (open) {
    el.classList.remove('hidden');
    el.removeAttribute('hidden');
    // force a style flush so the transition starts from the closed state
    void el.offsetWidth;
    el.classList.add('is-open');
  } else {
    el.classList.remove('is-open');
    const t = window.setTimeout(() => el.classList.add('hidden'), staticMode() ? 0 : duration);
    el.dataset.layerTimer = String(t);
  }
}

/** Parse a number from a data attribute. */
export const numAttr = (el: Element, name: string, fallback: number): number => {
  const v = parseFloat(el.getAttribute(name) ?? '');
  return Number.isFinite(v) ? v : fallback;
};

const bound = new WeakMap<Element, Set<string>>();
/**
 * True the first time it is called for (element, key). Used instead of data-* "bound" flags so that HTML copied
 * from a server fallback into an island (slots) is enhanced again rather than looking already-initialised.
 */
export function bindOnce(el: Element, key: string): boolean {
  let keys = bound.get(el);
  if (!keys) bound.set(el, (keys = new Set()));
  if (keys.has(key)) return false;
  keys.add(key);
  return true;
}
