import { $, $$, focusables } from './dom';

/**
 * Desktop navigation dropdowns + mega menus (disclosure pattern).
 *
 * Markup (includes/navbar.php):
 *   <li class="nav-item" data-nav-item>
 *     <a class="nav-link" href="/programs">Programs</a>
 *     <button class="nav-toggle" aria-expanded="false" aria-controls="nav-panel-programs" data-nav-toggle>…</button>
 *     <div class="nav-panel" id="nav-panel-programs" data-nav-panel>…links…</div>
 *   </li>
 *
 * Mouse: hover intent (opens after 80ms, closes 180ms after leaving). Keyboard: Enter/Space/ArrowDown on the toggle
 * opens and focuses the first link; Arrow keys/Home/End move inside the panel; Esc closes and returns focus.
 * Without the bundle the panels still open on :hover / :focus-within (CSS, see .nav-panel in style.css).
 */
export function initMenus(): void {
  const items = $$('[data-nav-item]');
  if (!items.length) return;
  const timers = new WeakMap<HTMLElement, number>();

  const panelOf = (li: HTMLElement) => $('[data-nav-panel]', li);
  const toggleOf = (li: HTMLElement) => $('[data-nav-toggle]', li);

  const setOpen = (li: HTMLElement, open: boolean) => {
    window.clearTimeout(timers.get(li));
    const panel = panelOf(li);
    if (!panel) return;
    if (open) {
      items.forEach((other) => other !== li && setOpen(other, false));
      li.setAttribute('data-open', '');
    } else {
      li.removeAttribute('data-open');
    }
    toggleOf(li)?.setAttribute('aria-expanded', open ? 'true' : 'false');
  };

  const isOpen = (li: HTMLElement) => li.hasAttribute('data-open');

  const panelLinks = (li: HTMLElement) => {
    const panel = panelOf(li);
    return panel ? focusables(panel).filter((el) => el.tagName === 'A' || el.tagName === 'BUTTON') : [];
  };

  items.forEach((li) => {
    if (!panelOf(li)) return;
    const toggle = toggleOf(li);

    li.addEventListener('pointerenter', (e) => {
      if (e.pointerType !== 'mouse') return;
      window.clearTimeout(timers.get(li));
      timers.set(li, window.setTimeout(() => setOpen(li, true), isAnyOpen() ? 0 : 80));
    });
    li.addEventListener('pointerleave', (e) => {
      if (e.pointerType !== 'mouse') return;
      window.clearTimeout(timers.get(li));
      timers.set(li, window.setTimeout(() => setOpen(li, false), 180));
    });

    toggle?.addEventListener('click', (e) => {
      const opening = !isOpen(li);
      setOpen(li, opening);
      // keyboard activation (detail 0) moves focus into the panel
      if (opening && e.detail === 0) panelLinks(li)[0]?.focus();
    });

    toggle?.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        setOpen(li, true);
        requestAnimationFrame(() => panelLinks(li)[0]?.focus());
      }
    });

    li.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && isOpen(li)) {
        e.stopPropagation();
        setOpen(li, false);
        (toggle ?? $('a', li))?.focus();
        return;
      }
      const panel = panelOf(li);
      if (!panel || !panel.contains(document.activeElement)) return;
      const links = panelLinks(li);
      const idx = links.indexOf(document.activeElement as HTMLElement);
      let next = -1;
      if (e.key === 'ArrowDown' || e.key === 'ArrowRight') next = (idx + 1) % links.length;
      else if (e.key === 'ArrowUp' || e.key === 'ArrowLeft') next = (idx - 1 + links.length) % links.length;
      else if (e.key === 'Home') next = 0;
      else if (e.key === 'End') next = links.length - 1;
      if (next >= 0) {
        e.preventDefault();
        links[next]?.focus();
      }
    });

    li.addEventListener('focusout', (e) => {
      const to = e.relatedTarget as Node | null;
      if (to && !li.contains(to)) setOpen(li, false);
    });
  });

  function isAnyOpen() {
    return items.some(isOpen);
  }

  document.addEventListener('pointerdown', (e) => {
    const target = e.target as Node;
    items.forEach((li) => isOpen(li) && !li.contains(target) && setOpen(li, false));
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') items.forEach((li) => isOpen(li) && setOpen(li, false));
  });
}
