import { $, $$, lockScroll, toggleLayer, trapFocus } from './dom';

/**
 * Layered overlays with enter/exit transitions, focus trap, Esc and scroll lock:
 *  - mobile drawer    #mobile-menu   opened by [data-menu-open], closed by [data-menu-close]
 *  - search overlay   #site-search   opened by [data-search-open] or Ctrl/⌘+K, closed by [data-search-close]
 *  - generic modals   <div class="modal hidden" id="x" data-modal>  opened by [data-modal-open="#x"], closed by
 *                     [data-modal-close] inside it (or clicking the backdrop element marked [data-modal-close])
 * CSS: `.is-open` drives the transition (see .drawer / .search-overlay / .modal in style.css).
 */

interface Layer {
  el: HTMLElement;
  release: () => void;
  onClose?: () => void;
}
const stack: Layer[] = [];

export function openLayer(el: HTMLElement, opts: { focus?: HTMLElement | null; onClose?: () => void } = {}): void {
  if (stack.some((l) => l.el === el)) return;
  toggleLayer(el, true);
  const unlock = lockScroll();
  const untrap = trapFocus(el, opts.focus);
  stack.push({
    el,
    onClose: opts.onClose,
    release: () => {
      untrap();
      unlock();
    },
  });
  el.dispatchEvent(new CustomEvent('layer:open'));
}

export function closeLayer(el?: HTMLElement): void {
  const idx = el ? stack.findIndex((l) => l.el === el) : stack.length - 1;
  if (idx < 0) return;
  const [layer] = stack.splice(idx, 1);
  toggleLayer(layer.el, false);
  layer.release();
  layer.onClose?.();
  layer.el.dispatchEvent(new CustomEvent('layer:close'));
}

export function initOverlays(): void {
  /* Mobile drawer */
  const menu = $('#mobile-menu');
  const openBtns = $$('[data-menu-open]');
  if (menu) {
    const setExpanded = (v: boolean) => openBtns.forEach((b) => b.setAttribute('aria-expanded', v ? 'true' : 'false'));
    openBtns.forEach((btn) =>
      btn.addEventListener('click', () => {
        openLayer(menu, { focus: $<HTMLElement>('[data-menu-close].drawer-close, [data-menu-close]:not(.drawer-backdrop)', menu), onClose: () => setExpanded(false) });
        setExpanded(true);
      }),
    );
    $$('[data-menu-close]', menu).forEach((el) => el.addEventListener('click', () => closeLayer(menu)));
    // close when a same-page anchor is followed
    $$('a[href*="#"]', menu).forEach((a) => a.addEventListener('click', () => closeLayer(menu)));
    // closing the drawer when the viewport grows to desktop
    window.matchMedia('(min-width: 1024px)').addEventListener?.('change', (e) => e.matches && closeLayer(menu));
  }

  /* Search overlay */
  const search = $('#site-search');
  const openSearch = () => search && openLayer(search, { focus: $<HTMLElement>('#site-search-input', search) });
  if (search) {
    $$('[data-search-open]').forEach((el) => el.addEventListener('click', openSearch));
    $$('[data-search-close]', search).forEach((el) => el.addEventListener('click', () => closeLayer(search)));
  }

  /* Generic modals */
  document.addEventListener('click', (e) => {
    const opener = (e.target as Element).closest<HTMLElement>('[data-modal-open]');
    if (opener) {
      const target = $(opener.getAttribute('data-modal-open') || '');
      if (target) {
        e.preventDefault();
        openLayer(target);
      }
      return;
    }
    const closer = (e.target as Element).closest<HTMLElement>('[data-modal-close]');
    const modal = closer?.closest<HTMLElement>('[data-modal]');
    if (closer && modal) {
      e.preventDefault();
      closeLayer(modal);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && stack.length) {
      e.preventDefault();
      closeLayer();
    }
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && search) {
      e.preventDefault();
      if (stack.some((l) => l.el === search)) closeLayer(search);
      else openSearch();
    }
  });
}
