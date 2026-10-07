import { islandRegistry } from '../islands';
import type { SlotItem } from '../lib/types';
import { within } from './dom';

/**
 * Island mounter. PHP renders (via island() in includes/config.php):
 *   <div data-island="HeroSlider" data-props='{"slides":[…]}' data-eager>…server-rendered fallback…</div>
 * This scans the DOM, lazy-loads the island's chunk from the registry when the placeholder comes within 200px of
 * the viewport (immediately for [data-eager]) and replaces the fallback with the React component (createRoot).
 * Each island is error-isolated: a failure logs to the console and leaves the server-rendered fallback in place.
 *
 * Slots: children of any `[data-slot="name"]` element inside the fallback are passed to the component as
 * `slots.name` ({ html, data }[]) — e.g. PHP-rendered program cards that ProgramExplorer filters and animates.
 */

function collectSlots(host: HTMLElement): Record<string, SlotItem[]> {
  const slots: Record<string, SlotItem[]> = {};
  host.querySelectorAll<HTMLElement>('[data-slot]').forEach((container) => {
    const parentSlot = container.parentElement?.closest('[data-slot]');
    if (parentSlot && host.contains(parentSlot)) return; // nested slot: belongs to its parent's HTML
    const name = container.getAttribute('data-slot') || 'default';
    slots[name] = Array.from(container.children).map((child) => ({
      html: child.outerHTML,
      data: { ...(child as HTMLElement).dataset } as Record<string, string>,
    }));
  });
  return slots;
}

async function mount(host: HTMLElement): Promise<void> {
  const name = host.getAttribute('data-island') || '';
  host.setAttribute('data-island-state', 'loading');
  const load = islandRegistry[name];
  if (!load) {
    console.warn(`[site] Unknown island "${name}" — keeping the server-rendered fallback.`);
    host.setAttribute('data-island-state', 'error');
    return;
  }
  try {
    const raw = host.getAttribute('data-props');
    const props = raw ? (JSON.parse(raw) as Record<string, unknown>) : {};
    const slots = collectSlots(host);
    const [mod, runtime] = await Promise.all([load(), import('../runtime/mount')]);
    runtime.mountIsland(host, name, mod.default, props, slots);
  } catch (err) {
    console.error(`[site] Island "${name}" failed to load.`, err);
    host.setAttribute('data-island-state', 'error');
  }
}

let observer: IntersectionObserver | null = null;

export function initIslands(root: ParentNode = document): void {
  const hosts = within('[data-island]', root).filter((el) => !el.hasAttribute('data-island-state'));
  if (!hosts.length) return;
  hosts.forEach((host) => host.setAttribute('data-island-state', 'pending'));
  const eager = hosts.filter((h) => h.hasAttribute('data-eager'));
  const lazy = hosts.filter((h) => !h.hasAttribute('data-eager'));
  eager.forEach((h) => void mount(h));
  if (!lazy.length) return;
  if (!('IntersectionObserver' in window)) {
    lazy.forEach((h) => void mount(h));
    return;
  }
  observer ??= new IntersectionObserver(
    (entries) =>
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        observer?.unobserve(en.target);
        void mount(en.target as HTMLElement);
      }),
    { rootMargin: '200px 0px 200px 0px' },
  );
  lazy.forEach((h) => observer!.observe(h));
}
