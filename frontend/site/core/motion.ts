import { $$, bindOnce, clamp, easeOutExpo, finePointer, numAttr, rafThrottle, reducedMotion, staticMode, within } from './dom';

/* ------------------------------------------------------------------ Scroll reveal */

/**
 * <div data-reveal>…</div>                       fade-up (default) when scrolled into view
 * <div data-reveal="zoom-in" data-reveal-delay="150">   variants: fade-up | fade-in | fade-down | zoom-in |
 *                                                  slide-left | slide-right | blur-in
 * <div data-stagger="90"> <article>…</article> … </div>  children reveal one after another (90ms apart)
 * Legacy `.reveal` class is supported too. Elements receive `.is-visible`.
 */
let revealObserver: IntersectionObserver | null = null;

function show(el: Element) {
  el.classList.add('is-visible');
}

export function initReveal(root: ParentNode = document): void {
  // stagger parents: number their children
  within('[data-stagger]', root).forEach((parent) => {
    const step = numAttr(parent, 'data-stagger', 80) || 80;
    const base = numAttr(parent, 'data-reveal-delay', 0);
    Array.from(parent.children).forEach((child, i) => {
      if (!(child instanceof HTMLElement)) return;
      if (!child.hasAttribute('data-reveal') && !child.classList.contains('reveal')) child.setAttribute('data-reveal', parent.getAttribute('data-stagger-effect') || 'fade-up');
      if (!child.style.getPropertyValue('--reveal-delay')) child.style.setProperty('--reveal-delay', `${base + Math.min(i, 12) * step}ms`);
    });
  });

  const targets = within('[data-reveal], .reveal', root).filter((el) => !el.classList.contains('is-visible'));
  targets.forEach((el) => {
    const d = el.getAttribute('data-reveal-delay');
    if (d && !el.hasAttribute('data-stagger')) el.style.setProperty('--reveal-delay', `${parseFloat(d) || 0}ms`);
  });

  if (staticMode() || !('IntersectionObserver' in window)) {
    targets.forEach(show);
    return;
  }
  revealObserver ??= new IntersectionObserver(
    (entries) =>
      entries.forEach((en) => {
        if (en.isIntersecting) {
          show(en.target);
          revealObserver?.unobserve(en.target);
        }
      }),
    { rootMargin: '0px 0px -7% 0px', threshold: 0.08 },
  );
  targets.forEach((el) => revealObserver!.observe(el));
}

/* ------------------------------------------------------------------ Animated counters */

/**
 * <span data-count="1248" data-suffix="+" data-prefix="₹" data-decimals="0" data-duration="1600">1,248+</span>
 * Server-render the final value (SEO / no-JS); the number counts up with an ease-out curve when visible.
 */
export function formatCount(v: number, decimals: number, locale = 'en-IN'): string {
  return v.toLocaleString(locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
}

export function initCounters(root: ParentNode = document): void {
  const els = within('[data-count]', root).filter((el) => bindOnce(el, 'count'));
  if (!els.length) return;
  const render = (el: HTMLElement, v: number) => {
    const raw = el.getAttribute('data-count') || '0';
    const decimals = el.hasAttribute('data-decimals') ? numAttr(el, 'data-decimals', 0) : (raw.split('.')[1] || '').length;
    el.textContent = `${el.dataset.prefix ?? ''}${formatCount(v, decimals)}${el.dataset.suffix ?? ''}`;
  };
  const run = (el: HTMLElement) => {
    const end = numAttr(el, 'data-count', 0);
    if (staticMode()) return render(el, end);
    const from = numAttr(el, 'data-count-from', 0);
    const dur = numAttr(el, 'data-duration', 1600);
    let start = 0;
    const step = (t: number) => {
      if (!start) start = t;
      const p = Math.min(1, (t - start) / dur);
      render(el, from + (end - from) * easeOutExpo(p));
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };
  if (staticMode() || !('IntersectionObserver' in window)) return els.forEach(run);
  const io = new IntersectionObserver(
    (entries) =>
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        io.unobserve(en.target);
        run(en.target as HTMLElement);
      }),
    { threshold: 0.35 },
  );
  els.forEach((el) => {
    render(el, numAttr(el, 'data-count-from', 0));
    io.observe(el);
  });
}

/* ------------------------------------------------------------------ Progress bars */

/** <div class="progress-track"><span data-progress="72"></span></div> — fills (scaleX) when visible. */
export function initProgress(root: ParentNode = document): void {
  const els = within('[data-progress]', root);
  if (!els.length) return;
  const fill = (el: HTMLElement) => el.style.setProperty('--progress', String(Math.min(100, numAttr(el, 'data-progress', 0)) / 100));
  if (staticMode() || !('IntersectionObserver' in window)) return els.forEach(fill);
  const io = new IntersectionObserver(
    (entries) =>
      entries.forEach((en) => {
        if (en.isIntersecting) {
          io.unobserve(en.target);
          fill(en.target as HTMLElement);
        }
      }),
    { threshold: 0.3 },
  );
  els.forEach((el) => io.observe(el));
}

/* ------------------------------------------------------------------ Lazy images */

/**
 * img[loading=lazy] / img[data-lazy] elements that are still loading get .lazy-pending (hidden) and fade + settle
 * (scale 1.04 -> 1) into .is-loaded once loaded. Only images seen here are ever hidden, so images rendered later by
 * React islands are never stuck invisible (copies of pending images inside island slots are re-checked).
 * data-src / data-srcset are swapped in when the image approaches the viewport (for non-native lazy cases).
 */
const lazySeen = new WeakSet<HTMLImageElement>();
export function initLazyImages(root: ParentNode = document): void {
  const imgs = within<HTMLImageElement>('img[loading="lazy"], img[data-lazy], img.lazy-pending', root);
  const done = (img: HTMLImageElement) => {
    img.classList.remove('lazy-pending');
    img.classList.add('is-loaded');
  };
  imgs.forEach((img) => {
    if (lazySeen.has(img)) return;
    lazySeen.add(img);
    if (img.complete && (img.naturalWidth > 0 || !img.currentSrc)) return done(img);
    if (!staticMode()) img.classList.add('lazy-pending');
    img.addEventListener('load', () => done(img), { once: true });
    img.addEventListener('error', () => done(img), { once: true });
  });
  const deferred = within<HTMLImageElement>('img[data-src], img[data-srcset]', root);
  if (!deferred.length) return;
  const swap = (img: HTMLImageElement) => {
    if (img.dataset.srcset) img.srcset = img.dataset.srcset;
    if (img.dataset.src) img.src = img.dataset.src;
    img.removeAttribute('data-src');
    img.removeAttribute('data-srcset');
  };
  if (!('IntersectionObserver' in window)) return deferred.forEach(swap);
  const io = new IntersectionObserver(
    (entries) =>
      entries.forEach((en) => {
        if (en.isIntersecting) {
          io.unobserve(en.target);
          swap(en.target as HTMLImageElement);
        }
      }),
    { rootMargin: '300px 0px' },
  );
  deferred.forEach((img) => io.observe(img));
}

/* ------------------------------------------------------------------ Parallax */

/**
 * <img data-parallax="0.15" class="parallax-media …">  moves at 15% of the scroll speed relative to the viewport
 * centre (negative values move the other way). Disabled for touch devices and reduced motion; only elements in
 * view are updated (IntersectionObserver), one rAF per frame.
 */
export function initParallax(root: ParentNode = document): void {
  const els = within('[data-parallax]', root);
  if (!els.length || reducedMotion() || staticMode() || !finePointer()) return;
  // measure the parent (the clipping frame) so the moving element's own transform never feeds back
  const hostOf = (el: HTMLElement) => el.parentElement ?? el;
  const active = new Set<HTMLElement>(els);
  const io = new IntersectionObserver((entries) =>
    entries.forEach((en) =>
      els.filter((el) => hostOf(el) === en.target).forEach((el) => (en.isIntersecting ? active.add(el) : active.delete(el))),
    ),
  );
  els.forEach((el) => {
    el.classList.add('parallax-on');
    io.observe(hostOf(el));
  });
  const update = () => {
    const h = window.innerHeight;
    active.forEach((el) => {
      const host = hostOf(el);
      const r = host.getBoundingClientRect();
      if (r.bottom < -100 || r.top > h + 100) return;
      const speed = numAttr(el, 'data-parallax', 0.15);
      let offset = (r.top + r.height / 2 - h / 2) * -speed;
      // media layers (.parallax-media, taller than their frame) never travel past their overscan, so no gaps show
      if (el.classList.contains('parallax-media')) {
        const slack = Math.max(0, (el.offsetHeight - host.clientHeight) / 2);
        offset = clamp(offset, -slack, slack);
      }
      el.style.transform = `translate3d(0, ${offset.toFixed(1)}px, 0)`;
    });
  };
  const onScroll = rafThrottle(update);
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  update();
}

/* ------------------------------------------------------------------ Marquee (CSS driven) */

/**
 * <div class="marquee" data-marquee="40"> <div class="marquee-track">…items…</div> </div>
 * Duplicates the track content once for a seamless loop and sets the duration from the content width
 * (data-marquee = pixels per second, default 40). Pauses on hover/focus (CSS) and when off-screen.
 */
export function initMarquee(root: ParentNode = document): void {
  within('[data-marquee]', root).forEach((el) => {
    const track = el.querySelector<HTMLElement>('.marquee-track');
    if (!track || !bindOnce(el, 'marquee')) return;
    // copies of a fallback marquee (island slots) already contain the aria-hidden clones
    const items = track.querySelector(':scope > [aria-hidden="true"]') ? [] : Array.from(track.children);
    items.forEach((child) => {
      const clone = child.cloneNode(true) as HTMLElement;
      clone.setAttribute('aria-hidden', 'true');
      clone.querySelectorAll('a,button').forEach((a) => a.setAttribute('tabindex', '-1'));
      track.appendChild(clone);
    });
    const speed = numAttr(el, 'data-marquee', 40) || 40;
    const setDuration = () => el.style.setProperty('--marquee-duration', `${Math.max(8, track.scrollWidth / 2 / speed)}s`);
    setDuration();
    window.addEventListener('resize', rafThrottle(setDuration), { passive: true });
    if ('IntersectionObserver' in window) {
      new IntersectionObserver((entries) => entries.forEach((en) => el.classList.toggle('is-offscreen', !en.isIntersecting))).observe(el);
    }
  });
  // generic pause toggles: <button data-marquee-toggle aria-pressed="false"> inside a .marquee host
  $$('[data-marquee-toggle]', root as ParentNode).forEach((btn) => {
    if (!bindOnce(btn, 'marquee-toggle')) return;
    btn.addEventListener('click', () => {
      const host = btn.closest('[data-marquee-host]') ?? btn.parentElement;
      const paused = btn.getAttribute('aria-pressed') !== 'true';
      btn.setAttribute('aria-pressed', paused ? 'true' : 'false');
      host?.querySelectorAll('.marquee').forEach((m) => m.classList.toggle('is-paused', paused));
    });
  });
}
