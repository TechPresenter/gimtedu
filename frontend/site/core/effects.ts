import { bindOnce, finePointer, rafThrottle, reducedMotion, staticMode, within } from './dom';

/**
 * Design v3 motion additions (all transform/opacity, static under reduced motion and in automated browsers):
 *  - initTextReveal: text-mask heading reveal — [data-text-reveal] headings slide up line by line
 *  - initBlobs:      freeze the SMIL-morphing blob() SVGs in static mode
 *  - initSpotlight:  restrained cursor-following light on [data-spotlight] elements (desktop fine pointers)
 *  - initDismiss:    dismissible bars ([data-dismiss="key"] + [data-dismiss-close]), remembered in localStorage
 *  - viewTransition: run a DOM update inside document.startViewTransition where supported (no-op elsewhere)
 */

/* ------------------------------------------------------------------ Text-mask heading reveal */

const tmOriginal = new WeakMap<HTMLElement, string>();
let tmObserver: IntersectionObserver | null = null;

/** Split a heading into its rendered lines (words measured by offsetTop; inline elements such as .scribble stay intact). */
function splitLines(el: HTMLElement): void {
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
  const lines: Node[][] = [];
  let lastTop = Number.NaN;
  tokens.forEach((t) => {
    if (!(t instanceof HTMLElement)) {
      if (lines.length) lines[lines.length - 1].push(t);
      return;
    }
    const block = getComputedStyle(t).display === 'block';
    const top = t.offsetTop;
    if (block || !lines.length || Math.abs(top - lastTop) > 4) lines.push([]);
    lines[lines.length - 1].push(t);
    lastTop = block ? Number.NaN : top;
  });
  el.textContent = '';
  lines.forEach((line, i) => {
    while (line.length && line[line.length - 1].nodeType === Node.TEXT_NODE) line.pop();
    const outer = document.createElement('span');
    outer.className = 'tm-line';
    const inner = document.createElement('span');
    inner.className = 'tm-inner';
    inner.style.setProperty('--l', String(i));
    line.forEach((n) => inner.appendChild(n));
    outer.appendChild(inner);
    el.appendChild(outer);
  });
}

/**
 * <h2 data-text-reveal>…</h2> — lines slide up from behind an overflow-hidden mask when the heading scrolls into view;
 * re-split on width changes. CSS keeps [data-text-reveal] invisible until .tm-ready (html.js only; html.no-motion and
 * the no-bundle fallback show it).
 */
export function initTextReveal(root: ParentNode = document): void {
  const els = within('[data-text-reveal]', root).filter((el) => bindOnce(el, 'text-reveal'));
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
  els.forEach((el) => {
    try {
      splitLines(el);
    } catch {
      /* keep the original markup */
    }
    el.classList.add('tm-ready');
    tmObserver!.observe(el);
    let width = el.clientWidth;
    window.addEventListener(
      'resize',
      rafThrottle(() => {
        if (Math.abs(el.clientWidth - width) < 2) return;
        width = el.clientWidth;
        try {
          splitLines(el);
        } catch {
          /* ignore */
        }
      }),
      { passive: true },
    );
  });
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

type DocWithVT = Document & { startViewTransition?: (cb: () => void) => unknown };

/** Run a DOM update inside a same-document view transition where supported (and not in static mode); else directly. */
export function viewTransition(update: () => void): void {
  const doc = document as DocWithVT;
  if (typeof doc.startViewTransition === 'function' && !staticMode()) {
    try {
      doc.startViewTransition(update);
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
