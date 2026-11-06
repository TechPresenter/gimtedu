import { within } from './dom';
import { viewTransition } from './effects';

/**
 * Website Light / Dark / System theme — enhancements on top of the inline scripts printed by
 * includes/partials/theme.php:
 *   • site_theme_head() (pre-paint) resolved the stored preference (localStorage "gimt.theme" → cookie "gimt_theme" →
 *     admin default) and "system" (prefers-color-scheme, followed live) into
 *     <html data-theme="light|dark" data-theme-pref="light|dark|system"> and exposes window.__gimtTheme {get, theme, apply};
 *   • site_theme_toggle_script() (next to the first toggle) added set / sync and delegated clicks on [data-theme-set],
 *     so the control also works with the no-bundle fallback.
 *     plus the radiogroup keyboard pattern (←/→/↑/↓, Home/End, roving tabindex), so the control also works with the
 *     no-bundle fallback; the head script adds <html class="theme-js"> (the toggle's CSS gate) before the first paint.
 * This module adds: a cross-fade between themes (View Transitions API where supported, never in static/reduced
 * motion), syncing other tabs (storage event) and a polite screen-reader announcement of the new theme.
 */

interface ThemeApi {
  get: () => string;
  theme: () => string;
  apply: (pref?: string) => void;
  set?: (pref: string) => void;
  sync?: () => void;
}

declare global {
  interface Window {
    __gimtTheme?: ThemeApi;
  }
}

const LABELS: Record<string, string> = { light: 'Light theme', dark: 'Dark theme', system: 'System theme (follows your device)' };
let wrapped = false;
let live: HTMLElement | null = null;

/** Polite status message, written after the (possibly asynchronous) view-transition update has run. */
function announce(api: ThemeApi, pref: string): void {
  if (!LABELS[pref]) return;
  if (!live) {
    live = document.createElement('span');
    live.className = 'sr-only';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    document.body.appendChild(live);
  }
  live.textContent = '';
  window.setTimeout(() => {
    if (live) live.textContent = `${LABELS[pref]}${pref === 'system' ? ` — currently ${api.theme()}` : ''}`;
  }, 150);
}

/** Wrap __gimtTheme.set once: cross-fade the switch + announce it; follow choices made in other tabs. */
function wrapApi(api: ThemeApi): void {
  if (wrapped || !api.set) return;
  wrapped = true;
  const original = api.set;
  api.set = (pref: string) => {
    if (pref === api.get()) {
      api.sync?.();
      return;
    }
    viewTransition(() => original(pref));
    announce(api, pref);
  };
  // setting the same value fires no storage event, so there is no write-back loop between tabs
  window.addEventListener('storage', (e) => {
    if (e.key === 'gimt.theme' && e.newValue && e.newValue !== api.get()) original(e.newValue);
  });
}

export function initTheme(root: ParentNode = document): void {
  const api = window.__gimtTheme;
  if (!api?.set) return;
  wrapApi(api);
  // toggles added later (injected HTML) only need their aria-checked / tabindex synced — clicks + keys are delegated
  if (root === document || within('[data-theme-toggle]', root).length) api.sync?.();
}
