import { bindOnce, finePointer, rafThrottle, reducedMotion, staticMode, within } from './dom';

/**
 * Design v3 motion additions (all transform/opacity, static under reduced motion and in automated browsers):
 *  - initTextReveal: text-mask heading reveal — [data-text-reveal] headings slide up line by line
 *  - initBlobs:      freeze the SMIL-morphing blob() SVGs in static mode
 *  - initSpotlight:  restrained cursor-following light on [data-spotlight] elements (desktop fine pointers)
 *  - initDismiss:    dismissible bars ([data-dismiss="key"] + [data-dismiss-close]), remembered in localStorage
 *  - viewTransition: run a DOM update inside document.startViewTransition where supported (no-op elsewhere)
 *  - initAttention / initGleam: idle CTA glow / light streak, paused off-screen
 */

/* ------------------------------------------------------------------ Text-mask heading reveal */

const tmOriginal = new WeakMap<HTMLElement, string>();
let tmObserver: IntersectionObserver | null = null;

/**
 * Split headings into their rendered lines (words measured by offsetTop; inline elements such as .scribble stay intact).
 * Batched for performance: all headings get their word spans first (writes), then every word is measured in ONE layout
 * pass (reads), then the lines are built (writes) — splitting one heading at a time forced a full-page layout per
 * heading (1–2 s on a slow phone for a page with many section titles).
 */
function splitLinesBatch(els: HTMLElement[]): void {
  // 1. writes: original markup back, words wrapped in .tm-w spans
  const prepared = els.map((el) => {
    try {
      const html = tmOriginal.get(el) ?? el.innerHTML;
      tmOriginal.set(el, html);
      el.innerHTML = html;
      const tokens: Node[] = [];
      Array.from(el.childNodes).forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE) {
          (node.textContent ?? '').split(/(\s+)/).forEach((part) => {
            if (!part) return;
            if (/^\s+$/.test(part)) tokens.push(document.createTextNode(' '));
            else {
              const w = document.createElement('span');
              w.className = 'tm-w';
              w.textContent = part;
              tokens.push(w);
            }
          });
        } else tokens.push(node);
      });
      el.textContent = '';
      tokens.forEach((t) => el.appendChild(t));
      return { el, tokens };
    } catch {
      return null; // keep the original markup
    }
  });
  // 2. reads (one layout for the whole batch)
  const measured = prepared.map((p) => {
    if (!p) return null;
    try {
      // a new rendered line moves down by about a line-height; inline-block words (.scribble) sit a few px off the text
      // words of the same line at display sizes, which a fixed 4 px tolerance mistook for line breaks ("B.Tech (" / "CSE" / ")")
      const tolerance = Math.max(4, (parseFloat(getComputedStyle(p.el).fontSize) || 16) * 0.5);
      const pos = p.tokens.map((t) => (t instanceof HTMLElement ? { block: getComputedStyle(t).display === 'block', top: t.offsetTop } : null));
      return { ...p, tolerance, pos };
    } catch {
      return null;
    }
  });
  // 3. writes: lines
  measured.forEach((m) => {
    if (!m) return;
    const lines: Node[][] = [];
    let lastTop = Number.NaN;
    m.tokens.forEach((t, i) => {
      const p = m.pos[i];
      if (!p) {
        if (lines.length) lines[lines.length - 1].push(t);
        return;
      }
      if (p.block || !lines.length || Math.abs(p.top - lastTop) > m.tolerance) lines.push([]);
      lines[lines.length - 1].push(t);
      lastTop = p.block ? Number.NaN : p.top;
    });
    m.el.textContent = '';
    lines.forEach((line, i) => {
      while (line.length && line[line.length - 1].nodeType === Node.TEXT_NODE) line.pop();
      const outer = document.createElement('span');
      outer.className = 'tm-line';
      const inner = document.createElement('span');
      inner.className = 'tm-inner';
      inner.style.setProperty('--l', String(i));
      line.forEach((n) => inner.appendChild(n));
      outer.appendChild(inner);
      m.el.appendChild(outer);
    });
  });
}

let tmPrepObserver: IntersectionObserver | null = null;

/** Split + arm the reveal of headings that came near the viewport (batched per observer callback). */
function prepareReveal(els: HTMLElement[]): void {
  splitLinesBatch(els);
  els.forEach((el) => {
    el.classList.add('tm-ready');
    tmObserver?.observe(el);
    let width = el.clientWidth;
    window.addEventListener(
      'resize',
      rafThrottle(() => {
        if (Math.abs(el.clientWidth - width) < 2) return;
        width = el.clientWidth;
        splitLinesBatch([el]);
      }),
      { passive: true },
    );
  });
}

/**
 * <h2 data-text-reveal>…</h2> — lines slide up from behind an overflow-hidden mask when the heading scrolls into view;
 * re-split on width changes. CSS keeps [data-text-reveal] invisible until .tm-ready (html.js only; html.no-motion and
 * the no-bundle fallback show it). A heading is only split when it comes within ~one screen of the viewport (nothing
 * is measured at start-up).
 */
export function initTextReveal(root: ParentNode = document): void {
  const all = within('[data-text-reveal]', root).filter((el) => bindOnce(el, 'text-reveal'));
  // a page title (h1) is painted with the first frame and never masked: it is the LCP element of most heroes
  all.filter((el) => el.tagName === 'H1').forEach((el) => el.classList.add('tm-ready', 'is-revealed', 'tm-done'));
  const els = all.filter((el) => el.tagName !== 'H1');
  if (!els.length) return;
  if (staticMode() || !('IntersectionObserver' in window)) {
    els.forEach((el) => el.classList.add('tm-ready', 'is-revealed', 'tm-done'));
    return;
  }
  tmObserver ??= new IntersectionObserver(
    (entries) =>
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        const el = en.target as HTMLElement;
        tmObserver?.unobserve(el);
        el.classList.add('is-revealed');
        const lines = el.querySelectorAll('.tm-line').length || 1;
        window.setTimeout(() => el.classList.add('tm-done'), 900 + lines * 90);
      }),
    { rootMargin: '0px 0px -6% 0px', threshold: 0.2 },
  );
  tmPrepObserver ??= new IntersectionObserver(
    (entries) => {
      const near = entries.filter((en) => en.isIntersecting).map((en) => en.target as HTMLElement);
      if (!near.length) return;
      near.forEach((el) => tmPrepObserver?.unobserve(el));
      prepareReveal(near);
    },
    { rootMargin: '25% 0px 60% 0px' },
  );
  els.forEach((el) => tmPrepObserver!.observe(el));
}

/* ------------------------------------------------------------------ Morphing SVG blobs */

/** blob() SVGs morph with SMIL <animate>; freeze them in static mode (reduced motion / automation). */
export function initBlobs(root: ParentNode = document): void {
  if (!staticMode()) return;
  within<SVGSVGElement>('svg[data-blob]', root).forEach((svg) => {
    try {
      svg.pauseAnimations();
      svg.setCurrentTime(0);
    } catch {
      /* not supported */
    }
  });
}

/* ------------------------------------------------------------------ Cursor-following light */

/**
 * <div class="card" data-spotlight> — a soft orange-red radial light follows the pointer inside the element (CSS
 * ::before reads --mx/--my). Desktop fine pointers only, never in static mode. Use on selected elements only.
 */
export function initSpotlight(root: ParentNode = document): void {
  if (!finePointer() || reducedMotion() || staticMode()) return;
  within('[data-spotlight]', root).forEach((el) => {
    if (!bindOnce(el, 'spotlight')) return;
    const move = rafThrottle((e: PointerEvent) => {
      const r = el.getBoundingClientRect();
      el.style.setProperty('--mx', `${(e.clientX - r.left).toFixed(0)}px`);
      el.style.setProperty('--my', `${(e.clientY - r.top).toFixed(0)}px`);
    });
    el.addEventListener('pointerenter', (e) => {
      if (e.pointerType === 'mouse') el.classList.add('is-lit');
    });
    el.addEventListener('pointermove', (e) => {
      if (e.pointerType === 'mouse') move(e);
    });
    el.addEventListener('pointerleave', () => el.classList.remove('is-lit'));
  });
}

/* ------------------------------------------------------------------ Dismissible bars */

/**
 * <div data-dismiss="storage-key"> … <button data-dismiss-close> — hides the element and remembers it per key in
 * localStorage (admission notification bar). Storage failures are ignored (the bar simply shows again).
 */
export function initDismiss(root: ParentNode = document): void {
  within('[data-dismiss]', root).forEach((el) => {
    if (!bindOnce(el, 'dismiss')) return;
    const key = el.getAttribute('data-dismiss') || '';
    try {
      if (key && window.localStorage.getItem(key) === '1') {
        el.classList.add('is-dismissed');
        return;
      }
    } catch {
      /* storage blocked */
    }
    el.querySelector('[data-dismiss-close]')?.addEventListener('click', () => {
      el.classList.add('is-dismissed');
      try {
        if (key) window.localStorage.setItem(key, '1');
      } catch {
        /* storage blocked */
      }
    });
  });
}

/* ------------------------------------------------------------------ View Transitions API */

interface VTHandle {
  ready?: Promise<unknown>;
  finished?: Promise<unknown>;
  updateCallbackDone?: Promise<unknown>;
}
type DocWithVT = Document & { startViewTransition?: (cb: () => void) => VTHandle | undefined };

let lastResize = -Infinity;
let resizeWatched = false;
const swallow = (): void => undefined;

/**
 * Run a DOM update inside a same-document view transition where supported (and not in static mode); else directly.
 * The browser aborts a transition when the viewport changes size, the tab is hidden or another transition starts
 * ("InvalidStateError: Transition was aborted because of invalid state" / AbortError) — the DOM update still runs, so
 * the rejected ready / finished / updateCallbackDone promises are handled here (never an uncaught error in the console),
 * and no transition is started while the window is being resized or the tab is in the background.
 */
export function viewTransition(update: () => void): void {
  if (!resizeWatched) {
    resizeWatched = true;
    window.addEventListener('resize', () => { lastResize = performance.now(); }, { passive: true });
  }
  const doc = document as DocWithVT;
  const resizing = performance.now() - lastResize < 500;
  if (typeof doc.startViewTransition === 'function' && !staticMode() && !resizing && doc.visibilityState === 'visible') {
    try {
      const t = doc.startViewTransition(update);
      t?.ready?.catch(swallow);
      t?.finished?.catch(swallow);
      t?.updateCallbackDone?.catch(swallow);
      return;
    } catch {
      /* fall through */
    }
  }
  update();
}

/* ------------------------------------------------------------------ Idle attention glow (main hero CTA only) */

/** .btn-attention glows every few seconds (CSS); pause it while the button is off-screen. */
export function initAttention(root: ParentNode = document): void {
  const els = within('.btn-attention', root).filter((el) => bindOnce(el, 'attention'));
  if (!els.length || !('IntersectionObserver' in window)) return;
  const io = new IntersectionObserver((entries) => entries.forEach((en) => en.target.classList.toggle('is-offscreen', !en.isIntersecting)));
  els.forEach((el) => io.observe(el));
}

/* ------------------------------------------------------------------ Idle CTA gleam (a few primary CTAs) */

/* turns for buttons entering the view together (ms); with the 2.4 s × 2 CSS cycle a screen's last sweep ends within 5 s */
const GLEAM_SLOTS = [500, 1300, 2100];
let gleamObserver: IntersectionObserver | null = null;

/** Site chrome CTAs (sticky header, drawer, mobile bar) gleam once per page view, not after every scroll-up. */
const gleamOnce = (el: Element): boolean => el.closest('#site-header, #mobile-menu, .mobile-cta') !== null;

/**
 * .btn-gleam (button_link 'gleam' => true): when the button comes into view it gets .is-live and the CSS lets the
 * .btn-shine streak cross it on its own twice. .is-live is dropped once the two sweeps end, on hover / focus (the hover
 * streak takes over, and leaving never restarts the gleam) and when the button leaves the view; the next entry re-arms it
 * (chrome CTAs only once per page). Only buttons entering the view together share the turns (--gleam-delay), hidden
 * ones take none. Never in static mode or without JS.
 */
export function initGleam(root: ParentNode = document): void {
  if (staticMode()) return;
  const els = within('.btn-gleam', root).filter((el) => bindOnce(el, 'gleam'));
  if (!els.length) return;
  els.forEach((el) => {
    el.addEventListener('animationend', (e) => {
      if (e.animationName === 'btn-gleam') el.classList.remove('is-live');
    });
    el.addEventListener('pointerenter', () => el.classList.remove('is-live'));
    el.addEventListener('focusin', () => el.classList.remove('is-live'));
  });
  if (!('IntersectionObserver' in window)) {
    els.forEach((el, i) => {
      el.style.setProperty('--gleam-delay', `${GLEAM_SLOTS[Math.min(i, GLEAM_SLOTS.length - 1)]}ms`);
      el.classList.add('is-live');
    });
    return;
  }
  gleamObserver ??= new IntersectionObserver((entries) => {
    let slot = 0;
    entries.forEach((en) => {
      const el = en.target as HTMLElement;
      if (!en.isIntersecting) {
        el.classList.remove('is-live');
        return;
      }
      el.style.setProperty('--gleam-delay', `${GLEAM_SLOTS[Math.min(slot++, GLEAM_SLOTS.length - 1)]}ms`);
      el.classList.add('is-live');
      if (gleamOnce(el)) gleamObserver?.unobserve(el);
    });
  });
  els.forEach((el) => gleamObserver?.observe(el));
}
