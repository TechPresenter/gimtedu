import { $, $$ } from './dom';
import { mountIslandsNow } from './islands';
import { closeLayer, openLayer } from './overlays';

/**
 * Enquiry popup (enquiry_modal() in includes/components.php — markup [data-enquiry-popup], opened like every other
 * data-modal by [data-modal-open="#id"] triggers). This module adds the automatic opening on the homepage:
 *
 *  - only when the server marked the popup with data-popup-auto="<seconds>" (homepage + setting enquiry_popup_auto;
 *    delay = setting enquiry_popup_delay, 3–60 s, never on /apply or /contact);
 *  - once per browser session (sessionStorage "gimt.popup.seen" — set when it opens, automatically or by a click),
 *    never again for 30 days after the visitor sent an enquiry (localStorage "gimt.popup.done": any lead form with a
 *    phone field that fires form:success, and every island lead — `gimt:lead` from forms/security.ts pushLead(): the
 *    homepage Smart Enquiry, the Apply wizard, the chat callback card);
 *  - warmed up before it shows (photo loaded, EnquiryForm island mounted while still hidden): ~2 s after load when it
 *    will open by itself, and on intent (pointer over / focus on / touch of a [data-modal-open] trigger) for clicks;
 *  - phones / touch screens (< 768 px or coarse pointer): only after ≥ 35 % of the page was scrolled or 20 s passed,
 *    and never before the delay;
 *  - never on top of another open layer (modal, drawer, search, cookie banner / preferences, chat window) — it waits
 *    until the screen is clear, and never in a background tab;
 *  - never in automated browsers (navigator.webdriver) — EXCEPT with ?popup=1 in the URL, which opens the first popup
 *    on the page immediately on any page (previews, screenshots, QA).
 * Focus moves to the dialog itself (not into a field, so phones do not raise the keyboard uninvited); Esc, the ✕
 * button and the backdrop close it and focus returns to where it was (core/overlays.ts).
 *
 * Notice popup (notice_modal() — [data-notice-popup]: CMS "Home popup" banner on the homepage, "Popup" announcement on
 * other pages): opens by itself once per browser session (sessionStorage data-notice-key) data-notice-delay seconds
 * after load (phones: after 35 % scroll or 20 s), with the same "screen must be clear" rules. At most ONE automatic
 * popup per page view: when the enquiry popup is going to open by itself here (homepage), the notice waits for a later
 * page view. ?popup=notice opens it immediately (previews); a click on one of its links closes it.
 */

const SEEN = 'gimt.popup.seen';
const DONE = 'gimt.popup.done';
const DONE_DAYS = 30;

function read(store: 'local' | 'session', key: string): string | null {
  try {
    return (store === 'local' ? window.localStorage : window.sessionStorage).getItem(key);
  } catch {
    return null;
  }
}

function write(store: 'local' | 'session', key: string, value: string): void {
  try {
    (store === 'local' ? window.localStorage : window.sessionStorage).setItem(key, value);
  } catch {
    /* storage blocked: the popup simply may show again */
  }
}

function recentlyDone(): boolean {
  const t = Number(read('local', DONE) || 0);
  return t > 0 && Date.now() - t < DONE_DAYS * 864e5;
}

/** Something else is on screen that the popup must not cover. */
function busy(): boolean {
  const html = document.documentElement;
  if (html.classList.contains('scroll-locked')) return true; // drawer, search, a modal, lightbox …
  if ($('.modal.is-open, .drawer.is-open, #site-search.is-open, .modal-shell.is-open')) return true;
  if ($$('#cc-banner, #cc-modal').some((el) => !el.hidden && el.offsetParent !== null)) return true; // cookie consent first
  if ($('#gimt-chat [aria-expanded="true"]')) return true; // chat window open
  const active = document.activeElement;
  // the visitor is typing into a form on the page: don't interrupt
  if (active instanceof HTMLElement && active.matches('input, select, textarea, [contenteditable="true"]') && !active.closest('[data-enquiry-popup]')) return true;
  return false;
}

/**
 * Get a popup ready before it is shown: load its photo (a lazy image inside a hidden dialog only starts loading when the
 * dialog opens — it would pop in) and mount its EnquiryForm island (no visible fallback → island swap after opening).
 * Called on intent (pointer / focus / touch on a trigger), while waiting for the automatic opening, and on open.
 */
function warm(modal: HTMLElement): void {
  $$<HTMLImageElement>('img[loading="lazy"]', modal).forEach((img) => (img.loading = 'eager'));
  mountIslandsNow(modal);
}

function open(modal: HTMLElement, trigger: 'auto' | 'preview'): void {
  warm(modal);
  write('session', SEEN, '1');
  const card = $<HTMLElement>('.epop', modal) ?? modal;
  openLayer(modal, { focus: card });
  modal.setAttribute('data-opened-by', trigger);
  const w = window as Window & { dataLayer?: unknown[] };
  if (Array.isArray(w.dataLayer)) w.dataLayer.push({ event: 'enquiry_popup_open', trigger });
}

export function initEnquiryPopup(): void {
  const enquiryFirst = initEnquiry();
  initNoticePopup(enquiryFirst);
}

const touchScreen = () => window.matchMedia('(max-width: 767px), (pointer: coarse)').matches;
const scrolledShare = () => {
  const max = document.documentElement.scrollHeight - window.innerHeight;
  return max > 0 ? window.scrollY / max : 0;
};
const automated = () => !!(navigator as Navigator & { webdriver?: boolean }).webdriver;

/** Sets up the enquiry popup; true when it opens (or will open) by itself on this page view. */
function initEnquiry(): boolean {
  // a sent lead (any form with a phone field) stops the automatic popup for 30 days
  document.addEventListener('form:success', (e) => {
    const form = e.target;
    if (form instanceof HTMLFormElement && form.querySelector('[name="phone"], input[type="tel"]')) write('local', DONE, String(Date.now()));
  });
  // islands report every sent lead (EnquiryForm, SmartEnquiry, ApplyWizard, chat callback card — forms/security.ts pushLead())
  window.addEventListener('gimt:lead', () => write('local', DONE, String(Date.now())));

  const popups = $$('[data-enquiry-popup]');
  if (!popups.length) return false;
  // intent: a pointer over / focus on / touch of a trigger warms its popup up, so it opens complete
  const onIntent = (e: Event) => {
    const opener = (e.target as Element | null)?.closest?.('[data-modal-open]');
    const target = opener ? $(opener.getAttribute('data-modal-open') || '') : null;
    if (target && popups.includes(target)) warm(target);
  };
  document.addEventListener('pointerover', onIntent, { passive: true });
  document.addEventListener('focusin', onIntent);
  document.addEventListener('touchstart', onIntent, { passive: true });
  // opened by a click (Enquire Now tab, Request a Callback …) also counts as seen for this session
  popups.forEach((m) => m.addEventListener('layer:open', () => write('session', SEEN, '1')));

  if (new URLSearchParams(window.location.search).get('popup') === '1') {
    // previews / screenshots: open right away (after the first paint, so the page behind is laid out) — once its form
    // island and photo are ready, or after 1.5 s at the latest
    const first = popups[0];
    requestAnimationFrame(() =>
      window.setTimeout(() => {
        warm(first);
        const t0 = performance.now();
        const ready = () =>
          $$('[data-island]', first).every((h) => ['mounted', 'error'].includes(h.getAttribute('data-island-state') || '')) &&
          $$<HTMLImageElement>('img', first).every((i) => i.complete);
        const wait = () => (ready() || performance.now() - t0 > 1500 ? open(first, 'preview') : window.setTimeout(wait, 50));
        wait();
      }, 60),
    );
    return true;
  }

  const modal = popups.find((m) => Number(m.dataset.popupAuto) > 0);
  if (!modal) return false;
  if (automated()) return false;
  if (read('session', SEEN) || recentlyDone()) return false;

  const delayMs = Math.min(60, Math.max(3, Number(modal.dataset.popupAuto) || 8)) * 1000;
  // it will open by itself: prepare it in the background once the page has loaded (well before the delay)
  const prepare = () => window.setTimeout(() => warm(modal), Math.min(2000, delayMs / 3));
  if (document.readyState === 'complete') prepare();
  else window.addEventListener('load', prepare, { once: true });
  const touch = touchScreen();
  const start = performance.now();
  let done = false;
  let timer = 0;

  const stop = () => {
    done = true;
    window.clearInterval(timer);
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('visibilitychange', check);
  };
  function check(): void {
    if (done) return;
    if (read('session', SEEN) || recentlyDone()) return stop(); // opened by a click meanwhile, or a lead was sent
    const elapsed = performance.now() - start;
    if (elapsed < delayMs) return;
    if (touch && !(scrolledShare() >= 0.35 || elapsed >= 20000)) return;
    if (document.hidden || busy()) return; // try again on the next tick
    stop();
    open(modal!, 'auto');
  }
  function onScroll(): void {
    check();
  }
  timer = window.setInterval(check, 1000);
  window.addEventListener('scroll', onScroll, { passive: true });
  document.addEventListener('visibilitychange', check);
  return true;
}

/** CMS notice popup ([data-notice-popup]) — see the header comment. */
function initNoticePopup(enquiryFirst: boolean): void {
  const modal = $<HTMLElement>('[data-notice-popup]');
  if (!modal) return;
  const key = modal.dataset.noticeKey || '';
  const card = $<HTMLElement>('.npop', modal) ?? modal;
  const show = (trigger: 'auto' | 'preview') => {
    if (key) write('session', key, '1');
    $$<HTMLImageElement>('img[loading="lazy"]', modal).forEach((img) => (img.loading = 'eager'));
    openLayer(modal, { focus: card });
    const w = window as Window & { dataLayer?: unknown[] };
    if (Array.isArray(w.dataLayer)) w.dataLayer.push({ event: 'notice_popup_open', trigger, notice: key });
  };
  // following one of its links (same-page anchors included) closes it
  modal.addEventListener('click', (e) => {
    if ((e.target as Element | null)?.closest?.('a[href]')) closeLayer(modal);
  });

  if (new URLSearchParams(window.location.search).get('popup') === 'notice') {
    const img = $<HTMLImageElement>('img', modal);
    if (img) img.loading = 'eager';
    requestAnimationFrame(() => window.setTimeout(() => show('preview'), 60));
    return;
  }
  if (enquiryFirst || automated() || !key || read('session', key)) return;

  const delayMs = Math.min(60, Math.max(1, Number(modal.dataset.noticeDelay) || 3)) * 1000;
  const touch = touchScreen();
  const start = performance.now();
  // one popup per page view: a visitor who opened another dialog here (Enquire Now, Request a Callback …) is not
  // interrupted by the notice afterwards — it waits for the next page
  let otherOpened = false;
  $$<HTMLElement>('[data-modal]').forEach((m) => m !== modal && m.addEventListener('layer:open', () => (otherOpened = true)));
  // load the image in the background before it shows (a lazy image in a hidden dialog would pop in)
  window.setTimeout(() => $$<HTMLImageElement>('img[loading="lazy"]', modal).forEach((img) => (img.loading = 'eager')), Math.max(0, delayMs - 1500));
  let timer = 0;
  const stop = () => {
    window.clearInterval(timer);
    window.removeEventListener('scroll', check);
    document.removeEventListener('visibilitychange', check);
  };
  function check(): void {
    if (read('session', key) || otherOpened) return stop();
    const elapsed = performance.now() - start;
    if (elapsed < delayMs) return;
    if (touch && !(scrolledShare() >= 0.35 || elapsed >= 20000)) return;
    if (document.hidden || busy()) return;
    stop();
    show('auto');
  }
  timer = window.setInterval(check, 1000);
  window.addEventListener('scroll', check, { passive: true });
  document.addEventListener('visibilitychange', check);
}
