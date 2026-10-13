import { $$, bindOnce, within } from './dom';
import { viewTransition } from './effects';

/**
 * Listing view modes (Grid / List / Compact / Detailed) — view_mode_toggle() + view_mode_open() in
 * includes/components.php:
 *   <div class="view-toggle" role="radiogroup" data-view-toggle="programs">
 *     <button role="radio" data-view-mode="grid" aria-checked="true">…</button> …
 *   </div>
 *   <div class="view-host" data-view="grid" data-view-key="programs">…items (.view-item with .vm-media/.vm-body/
 *     .vm-text/.vm-extra parts)…</div>
 * The container's inline bootstrap script (from view_mode_open()) already applied the saved mode before its children
 * were parsed (no layout shift); this wires the segmented control, persists per key in localStorage and animates the
 * switch with the View Transitions API where available. Arrow keys move between modes (radiogroup pattern).
 */
const storeKey = (key: string) => `gimt.view.${key}`;

export function initViewModes(root: ParentNode = document): void {
  within('[data-view-toggle]', root).forEach((toggle) => {
    if (!bindOnce(toggle, 'view-toggle')) return;
    const key = toggle.getAttribute('data-view-toggle') || '';
    const hosts = () => $$(`[data-view-key="${CSS.escape(key)}"]`);
    const buttons = $$<HTMLButtonElement>('[data-view-mode]', toggle);
    const sync = (mode: string) =>
      buttons.forEach((b) => {
        const on = b.getAttribute('data-view-mode') === mode;
        b.setAttribute('aria-checked', on ? 'true' : 'false');
        b.tabIndex = on ? 0 : -1;
        b.classList.toggle('is-active', on);
      });
    const current = hosts()[0]?.getAttribute('data-view') || toggle.getAttribute('data-view-default') || 'grid';
    sync(current);
    const set = (mode: string, focus = false) => {
      viewTransition(() => hosts().forEach((h) => h.setAttribute('data-view', mode)));
      sync(mode);
      try {
        window.localStorage.setItem(storeKey(key), mode);
      } catch {
        /* storage blocked */
      }
      if (focus) buttons.find((b) => b.getAttribute('data-view-mode') === mode)?.focus();
    };
    buttons.forEach((b, i) => {
      b.addEventListener('click', () => set(b.getAttribute('data-view-mode') || 'grid'));
      b.addEventListener('keydown', (e) => {
        let n = -1;
        if (e.key === 'ArrowRight' || e.key === 'ArrowDown') n = (i + 1) % buttons.length;
        else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp') n = (i - 1 + buttons.length) % buttons.length;
        if (n >= 0) {
          e.preventDefault();
          set(buttons[n].getAttribute('data-view-mode') || 'grid', true);
        }
      });
    });
  });
}
