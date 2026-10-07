import { $, rafThrottle, staticMode } from './dom';

/**
 * Scroll-driven chrome:
 *  - #site-header: `.is-scrolled` (glass + shadow + compact logo) after 8px, `.is-hidden` when scrolling down past
 *    the fold (re-appears on scroll up). Never hides while a menu is open or focus is inside the header.
 *  - [data-read-progress]: top reading-progress bar (scaleX).
 *  - [data-back-to-top]: appears after 600px; its SVG ring (.btt-ring, pathLength=100) shows scroll progress.
 */
export function initHeader(): void {
  const header = $('#site-header');
  const progress = $('[data-read-progress]');
  const bar = progress ? $('[data-read-progress-bar]', progress) ?? (progress.firstElementChild as HTMLElement | null) : null;
  const toTop = $('[data-back-to-top]');
  const ring = toTop ? $<SVGCircleElement>('.btt-ring', toTop) : null;

  let lastY = window.scrollY;
  let downDistance = 0;
  let upDistance = 0;

  const update = () => {
    const y = Math.max(0, window.scrollY);
    const max = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    const pct = Math.min(1, y / max);

    if (header) {
      header.classList.toggle('is-scrolled', y > 8);
      const delta = y - lastY;
      if (delta > 0) {
        downDistance += delta;
        upDistance = 0;
      } else if (delta < 0) {
        upDistance -= delta;
        downDistance = 0;
      }
      const busy =
        header.querySelector('[data-open]') !== null ||
        header.querySelector(':focus-visible') !== null ||
        document.documentElement.classList.contains('scroll-locked');
      if (y < 320 || busy || upDistance > 8) header.classList.remove('is-hidden');
      else if (downDistance > 24 && header.dataset.autohide !== 'off') header.classList.add('is-hidden');
    }
    if (bar) {
      bar.style.transform = `scaleX(${pct})`;
      progress?.classList.toggle('is-active', y > 40 && max > window.innerHeight * 0.75);
    }
    if (toTop) {
      const show = y > 600;
      toTop.classList.toggle('is-visible', show);
      toTop.setAttribute('aria-hidden', show ? 'false' : 'true');
      toTop.tabIndex = show ? 0 : -1;
      if (ring) ring.style.strokeDashoffset = String(100 - pct * 100);
    }
    lastY = y;
  };

  const onScroll = rafThrottle(update);
  window.addEventListener('scroll', onScroll, { passive: true });
  window.addEventListener('resize', onScroll, { passive: true });
  update();

  toTop?.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: staticMode() ? 'auto' : 'smooth' });
    // move focus to the top of the document for keyboard users
    const skip = $<HTMLElement>('#main');
    if (skip) {
      skip.setAttribute('tabindex', '-1');
      skip.focus({ preventScroll: true });
    }
  });

  // Top bar announcement ticker: rotate [data-ticker] > [data-ticker-item] every 4.5s (fade)
  const ticker = $('[data-ticker]');
  if (ticker) {
    const items = Array.from(ticker.querySelectorAll<HTMLElement>('[data-ticker-item]'));
    if (items.length > 1 && !staticMode()) {
      let i = 0;
      let paused = false;
      ticker.addEventListener('mouseenter', () => (paused = true));
      ticker.addEventListener('mouseleave', () => (paused = false));
      window.setInterval(() => {
        if (paused || document.hidden) return;
        items[i].classList.remove('is-active');
        i = (i + 1) % items.length;
        items[i].classList.add('is-active');
      }, 4500);
    }
  }
}
