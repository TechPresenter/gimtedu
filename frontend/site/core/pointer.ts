import { $$, bindOnce, clamp, finePointer, numAttr, reducedMotion, within } from './dom';

/* ------------------------------------------------------------------ Ripple */

/**
 * Material-style ripple from the pointer position on every `.btn` and `[data-ripple]` element
 * (opt out with data-no-ripple). The host needs `position:relative; overflow:hidden` (`.btn` has it).
 */
export function initRipple(): void {
  document.addEventListener('pointerdown', (e) => {
    if (e.button !== 0 || reducedMotion()) return;
    const host = (e.target as Element).closest<HTMLElement>('.btn, [data-ripple]');
    if (!host || host.hasAttribute('data-no-ripple') || (host as HTMLButtonElement).disabled) return;
    const r = host.getBoundingClientRect();
    const size = Math.max(r.width, r.height) * 2.2;
    const dot = document.createElement('span');
    dot.className = 'ripple';
    dot.setAttribute('aria-hidden', 'true');
    dot.style.width = dot.style.height = `${size}px`;
    dot.style.left = `${e.clientX - r.left - size / 2}px`;
    dot.style.top = `${e.clientY - r.top - size / 2}px`;
    host.appendChild(dot);
    const remove = () => dot.remove();
    dot.addEventListener('animationend', remove, { once: true });
    window.setTimeout(remove, 1000);
  });
}

/* ------------------------------------------------------------------ Magnetic buttons */

/**
 * <a class="btn btn-accent" data-magnetic>…</a>   (optional strength: data-magnetic="0.4", default 0.3)
 * The element leans towards the pointer while hovered and springs back on leave. Fine pointers only.
 */
export function initMagnetic(root: ParentNode = document): void {
  if (!finePointer() || reducedMotion()) return;
  within('[data-magnetic]', root).forEach((el) => {
    if (!bindOnce(el, 'magnetic')) return;
    const strength = numAttr(el, 'data-magnetic', 0.3) || 0.3;
    let raf = 0;
    el.addEventListener('pointermove', (e) => {
      if (e.pointerType !== 'mouse') return;
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => {
        const r = el.getBoundingClientRect();
        const dx = clamp(e.clientX - (r.left + r.width / 2), -r.width, r.width) * strength;
        const dy = clamp(e.clientY - (r.top + r.height / 2), -r.height, r.height) * strength;
        el.classList.add('is-magnetic');
        el.style.transform = `translate3d(${dx.toFixed(1)}px, ${dy.toFixed(1)}px, 0)`;
      });
    });
    el.addEventListener('pointerleave', () => {
      cancelAnimationFrame(raf);
      el.style.transform = '';
      window.setTimeout(() => el.classList.remove('is-magnetic'), 400);
    });
  });
}

/* ------------------------------------------------------------------ Tilt */

/** <div class="card" data-tilt="6"> — subtle 3D tilt towards the pointer (max degrees, default 6). */
export function initTilt(root: ParentNode = document): void {
  if (!finePointer() || reducedMotion()) return;
  within('[data-tilt]', root).forEach((el) => {
    if (!bindOnce(el, 'tilt')) return;
    const max = numAttr(el, 'data-tilt', 6) || 6;
    let raf = 0;
    el.addEventListener('pointermove', (e) => {
      cancelAnimationFrame(raf);
      raf = requestAnimationFrame(() => {
        const r = el.getBoundingClientRect();
        const px = (e.clientX - r.left) / r.width - 0.5;
        const py = (e.clientY - r.top) / r.height - 0.5;
        el.style.transform = `perspective(900px) rotateX(${(-py * max).toFixed(2)}deg) rotateY(${(px * max).toFixed(2)}deg) translateZ(0)`;
        el.style.setProperty('--glare-x', `${((px + 0.5) * 100).toFixed(1)}%`);
        el.style.setProperty('--glare-y', `${((py + 0.5) * 100).toFixed(1)}%`);
      });
    });
    el.addEventListener('pointerleave', () => {
      cancelAnimationFrame(raf);
      el.style.transform = '';
    });
  });
}

/* ------------------------------------------------------------------ Cursor follower */

/**
 * Custom cursor ring that trails the native cursor and grows over interactive elements.
 * Enabled with <body data-cursor> (site_header option 'cursor' => true, default on); fine pointers only,
 * never on touch devices or with reduced motion. `data-cursor-label="View"` on an element shows a label in the ring.
 */
export function initCursor(): void {
  if (!document.body.hasAttribute('data-cursor') || !finePointer() || reducedMotion()) return;
  const ring = document.createElement('div');
  ring.className = 'cursor-ring';
  ring.setAttribute('aria-hidden', 'true');
  const label = document.createElement('span');
  label.className = 'cursor-label';
  ring.appendChild(label);
  const dot = document.createElement('div');
  dot.className = 'cursor-dot';
  dot.setAttribute('aria-hidden', 'true');
  document.body.append(ring, dot);
  document.documentElement.classList.add('has-cursor');

  let x = -100;
  let y = -100;
  let rx = x;
  let ry = y;
  let running = false;
  const loop = () => {
    rx += (x - rx) * 0.2;
    ry += (y - ry) * 0.2;
    ring.style.transform = `translate3d(${rx.toFixed(1)}px, ${ry.toFixed(1)}px, 0)`;
    if (Math.abs(x - rx) > 0.1 || Math.abs(y - ry) > 0.1) requestAnimationFrame(loop);
    else running = false;
  };
  const INTERACTIVE = 'a, button, [role="button"], summary, label, select, .btn, [data-cursor-hover], [data-cursor-label]';
  document.addEventListener(
    'pointermove',
    (e) => {
      if (e.pointerType !== 'mouse') return;
      x = e.clientX;
      y = e.clientY;
      dot.style.transform = `translate3d(${x}px, ${y}px, 0)`;
      ring.classList.add('is-active');
      dot.classList.add('is-active');
      if (!running) {
        running = true;
        requestAnimationFrame(loop);
      }
      const t = e.target as Element;
      const textField = t.closest('input:not([type=checkbox]):not([type=radio]):not([type=submit]), textarea, [contenteditable="true"]');
      const hit = textField ? null : t.closest<HTMLElement>(INTERACTIVE);
      const text = hit?.getAttribute('data-cursor-label') ?? '';
      ring.classList.toggle('is-hover', !!hit);
      ring.classList.toggle('is-text', !!textField);
      ring.classList.toggle('has-label', text !== '');
      if (label.textContent !== text) label.textContent = text;
    },
    { passive: true },
  );
  document.addEventListener('pointerdown', () => ring.classList.add('is-pressed'));
  document.addEventListener('pointerup', () => ring.classList.remove('is-pressed'));
  document.documentElement.addEventListener('pointerleave', () => {
    ring.classList.remove('is-active');
    dot.classList.remove('is-active');
  });
  // hide while an iframe (video) has the pointer
  $$('iframe').forEach((f) => f.addEventListener('pointerenter', () => ring.classList.remove('is-active')));
}
