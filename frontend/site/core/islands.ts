import { islandRegistry } from '../islands';
import type { FallbackState, SlotItem } from '../lib/types';
import { within, yieldToMain } from './dom';

/**
 * Island mounter. PHP renders (via island() in includes/config.php):
 *   <div data-island="HeroSlider" data-props='{"slides":[…]}' data-eager>…server-rendered fallback…</div>
 * This scans the DOM, lazy-loads the island's chunk from the registry when the placeholder comes within 200px of
 * the viewport (immediately for [data-eager]) and replaces the fallback with the React component (createRoot).
 * Each island is error-isolated: a failure logs to the console and leaves the server-rendered fallback in place.
 *
 * Slots: children of any `[data-slot]` element inside the fallback are passed to the component as
 * `slots.name` ({ html, data, node }[]) — e.g. PHP-rendered program cards that ProgramExplorer filters and animates.
 *
 * When islands mount (performance — the first screen must not wait for React):
 *   - [data-eager]: right away; others when they come near the viewport;
 *   - [data-hydrate="idle"] (+ near the viewport): once the browser is idle after the page load, or at the first
 *     pointer / touch / focus inside it — for islands whose server fallback already works (ProgramExplorer: the
 *     fallback filters with the vanilla [data-filter] buttons); what the visitor did there is handed over
 *     (props.fallbackState);
 *   - the chat assistant (#gimt-chat, includes/partials/chatbot.php): only when the visitor opens it (launcher click,
 *     [data-chat-open], the gimt:chat-open event), when it was open on the previous page, or when its greeting bubble
 *     becomes due (scroll share / time from its props) — hovering / touching the launcher preloads its code.
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
      node: child as HTMLElement,
    }));
  });
  return slots;
}

/** Filter / search state of a server fallback the visitor may already have used. */
function fallbackState(host: HTMLElement): FallbackState | undefined {
  const state: FallbackState = {};
  const pressed = host.querySelector('[data-filter][aria-pressed="true"]');
  const first = host.querySelector('[data-filter]');
  if (pressed && pressed !== first) state.filter = pressed.getAttribute('data-filter') || undefined;
  const search = host.querySelector<HTMLInputElement>('input[type="search"]');
  if (search?.value) state.query = search.value.slice(0, 100);
  if (search && document.activeElement === search) state.searchFocused = true;
  return Object.keys(state).length ? state : undefined;
}

async function mount(host: HTMLElement): Promise<void> {
  const name = host.getAttribute('data-island') || '';
  const state = host.getAttribute('data-island-state');
  if (state && state !== 'pending' && state !== 'deferred') return; // already loading / mounted (mountIslandsNow() raced the observer)
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
    const [mod, runtime] = await Promise.all([load(), import('../runtime/mount')]);
    // islands whose chunks arrive together each render in their own task (one long task per page otherwise)
    await yieldToMain();
    // read the fallback only now: the visitor may have used it while the chunks were loading
    const slots = collectSlots(host);
    const fb = fallbackState(host);
    if (fb) props.fallbackState = fb;
    runtime.mountIsland(host, name, mod.default, props, slots);
  } catch (err) {
    console.error(`[site] Island "${name}" failed to load.`, err);
    host.setAttribute('data-island-state', 'error');
  }
}

/** Download an island's code (no render) — on intent, so a later mount is instant. */
function preload(name: string): void {
  const load = islandRegistry[name];
  if (!load) return;
  void load().catch(() => undefined);
  void import('../runtime/mount').catch(() => undefined);
}

const idle = (fn: () => void, timeout: number): void => {
  const ric = (window as Window & { requestIdleCallback?: (cb: () => void, o?: { timeout: number }) => number }).requestIdleCallback;
  if (ric) ric(fn, { timeout });
  else window.setTimeout(fn, Math.min(timeout, 1200));
};
const afterLoad = (fn: () => void): void => {
  if (document.readyState === 'complete') fn();
  else window.addEventListener('load', fn, { once: true });
};

/**
 * Islands whose server fallback is complete and usable (ProgramExplorer: category tiles are links / vanilla filters,
 * the cards are all there) hydrate when idle even when rendered eager — unless the URL asks for something only the
 * island does (?q= search term).
 */
const IDLE_ISLANDS = new Set(['ProgramExplorer']);

function hydratesWhenIdle(host: HTMLElement): boolean {
  if (host.getAttribute('data-hydrate') === 'idle') return true;
  if (!IDLE_ISLANDS.has(host.getAttribute('data-island') || '')) return false;
  try {
    const props = JSON.parse(host.getAttribute('data-props') || '{}') as { searchParam?: string };
    return !(props.searchParam && new URLSearchParams(window.location.search).get(props.searchParam));
  } catch {
    return true;
  }
}

/** Idle hydration: mount when the browser is idle after the load event, or at the first interaction inside it. */
function mountWhenIdle(host: HTMLElement): void {
  host.setAttribute('data-island-state', 'deferred');
  const go = () => {
    ['pointerover', 'pointerdown', 'touchstart', 'focusin', 'keydown'].forEach((t) => host.removeEventListener(t, go, true));
    void mount(host);
  };
  ['pointerover', 'pointerdown', 'touchstart', 'focusin', 'keydown'].forEach((t) => host.addEventListener(t, go, { capture: true, passive: true, once: true }));
  afterLoad(() => idle(go, 4000));
}

/* ---------------------------------------------------------------- chat assistant: mounted on demand */

const CHAT_NO_GREETING = /^\/(apply|contact|portal|student-portal|faculty-portal|admin)(\/|$)/i;

/** True when the chat's greeting bubble could still appear on this page view (mirrors islands/ChatWidget.tsx). */
function chatGreetingDue(cfg: Record<string, unknown>): { afterMs: number; share: number } | null {
  if (!cfg.greeting || cfg.greetingMode === 'off') return null;
  const base = String(cfg.basePath || '').replace(/\/$/, '');
  const path = window.location.pathname.slice(base.length) || '/';
  if (CHAT_NO_GREETING.test(path)) return null;
  try {
    const dismissed = Number(window.localStorage.getItem('gimt-chat:greet-dismissed') || 0);
    if (dismissed && Date.now() - dismissed < 7 * 864e5) return null;
    if (window.sessionStorage.getItem('gimt-chat:greet-shown') === '1' || window.sessionStorage.getItem('gimt-chat:greeted') === '1') return null;
    const chat = JSON.parse(window.sessionStorage.getItem('gimt-chat:v1') || 'null') as { messages?: unknown[] } | null;
    if (chat?.messages?.length) return null;
  } catch {
    /* storage blocked: the widget decides once mounted */
  }
  const afterMs = Math.max(Number(cfg.greetingDelay) || 0, cfg.greetingAfter == null ? 20 : Number(cfg.greetingAfter) || 0) * 1000;
  const share = Math.min(1, Math.max(0, cfg.greetingScroll == null ? 0.3 : Number(cfg.greetingScroll) || 0));
  return { afterMs, share };
}

function chatWasOpen(): boolean {
  try {
    const chat = JSON.parse(window.sessionStorage.getItem('gimt-chat:v1') || 'null') as { open?: boolean } | null;
    return !!chat?.open && window.matchMedia('(min-width: 640px)').matches;
  } catch {
    return false;
  }
}

function armChat(host: HTMLElement): void {
  if (host.hasAttribute('data-chat-armed')) return;
  host.setAttribute('data-chat-armed', '');
  host.setAttribute('data-island-state', 'deferred');
  const name = host.getAttribute('data-island') || host.getAttribute('data-chat-island') || 'ChatWidget';
  let started = false;
  const start = (open: boolean, message?: string) => {
    if (open) host.setAttribute('data-open', '1');
    if (started) return;
    started = true;
    host.setAttribute('data-island', name);
    void mount(host).then(() => {
      // a message queued by a [data-chat-open] trigger / the event before the widget existed (after its effects ran)
      if (message) window.setTimeout(() => window.dispatchEvent(new CustomEvent('gimt:chat-open', { detail: { message } })), 0);
    });
  };
  const launcher = host.querySelector<HTMLElement>('[data-chat-launcher], a, button');
  // the inline loader (chatbot.php) has already cancelled the link navigation and set data-open on this click
  launcher?.addEventListener('click', () => start(true));
  ['pointerenter', 'focusin', 'touchstart'].forEach((t) => launcher?.addEventListener(t, () => preload(name), { passive: true, once: true }));
  // open from anywhere before the widget exists
  document.addEventListener('click', (e) => {
    if (started) return;
    const el = (e.target as Element | null)?.closest?.('[data-chat-open]');
    if (!el) return;
    e.preventDefault();
    start(true, el.getAttribute('data-chat-message') || undefined);
  });
  window.addEventListener('gimt:chat-open', (e) => {
    if (!started) start(true, ((e as CustomEvent).detail as { message?: string } | undefined)?.message);
  });
  if (chatWasOpen()) {
    start(false); // the widget reopens itself (it was open on the previous page)
    return;
  }
  let cfg: Record<string, unknown> = {};
  try {
    cfg = JSON.parse(host.getAttribute('data-props') || '{}') as Record<string, unknown>;
  } catch {
    /* keep defaults */
  }
  const due = chatGreetingDue(cfg);
  if (!due) return;
  // greeting bubble: mount (quietly) once it is due — the widget then waits for a clear spot itself
  const quiet = () => {
    window.removeEventListener('scroll', onScroll);
    if (!started) idle(() => start(false), 2000);
  };
  let rq = 0;
  const onScroll = () => {
    if (rq) return;
    rq = window.requestAnimationFrame(() => {
      rq = 0;
      const max = document.documentElement.scrollHeight - window.innerHeight;
      if (max > 0 && window.scrollY / max >= due.share) quiet();
    });
  };
  window.addEventListener('scroll', onScroll, { passive: true });
  window.setTimeout(quiet, Math.max(0, due.afterMs - performance.now()));
}

let observer: IntersectionObserver | null = null;

/**
 * Mount the islands inside `root` that are still waiting to come near the viewport — e.g. the form of a hidden popup,
 * so it is ready (no fallback → island swap) by the time the popup opens. Safe to call repeatedly.
 */
export function mountIslandsNow(root: ParentNode): void {
  within('[data-island][data-island-state="pending"], [data-island][data-island-state="deferred"]:not([data-chat-island])', root).forEach((host) => {
    observer?.unobserve(host);
    void mount(host);
  });
}

export function initIslands(root: ParentNode = document): void {
  const candidates = within('[data-island]', root).filter((el) => !el.hasAttribute('data-island-state'));
  // Nested islands (an island inside another island's slot HTML, e.g. a Countdown in a Tabs panel) are mounted by the
  // parent after it renders (runtime/mount.tsx calls mountIslands(host)) — mounting them now would target fallback
  // DOM that React is about to replace.
  const hosts = candidates.filter((el) => !candidates.some((other) => other !== el && other.contains(el)) && !el.parentElement?.closest('[data-island]:not([data-island-state="mounted"])'));
  if (!hosts.length) return;
  // the chat assistant: on demand only (see the header comment); the click that opened it mounts it at once
  const chat = hosts.filter((h) => h.hasAttribute('data-chat-island'));
  chat.forEach((h) => (h.hasAttribute('data-open') ? void mount(h) : armChat(h)));
  const rest = hosts.filter((h) => !h.hasAttribute('data-chat-island'));
  rest.forEach((host) => host.setAttribute('data-island-state', 'pending'));
  const eager = rest.filter((h) => h.hasAttribute('data-eager'));
  const lazy = rest.filter((h) => !h.hasAttribute('data-eager'));
  eager.forEach((h) => (hydratesWhenIdle(h) ? mountWhenIdle(h) : void mount(h)));
  if (!lazy.length) return;
  if (!('IntersectionObserver' in window)) {
    lazy.forEach((h) => void mount(h));
    return;
  }
  observer ??= new IntersectionObserver(
    (entries) =>
      entries.forEach((en) => {
        if (!en.isIntersecting) return;
        const host = en.target as HTMLElement;
        observer?.unobserve(host);
        if (hydratesWhenIdle(host)) mountWhenIdle(host);
        else void mount(host);
      }),
    { rootMargin: '200px 0px 200px 0px' },
  );
  lazy.forEach((h) => observer!.observe(h));
}
