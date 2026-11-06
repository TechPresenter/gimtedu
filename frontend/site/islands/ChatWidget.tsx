import clsx from 'clsx';
import { X } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';
import { dismissGreeting, greetingDismissed, greetingShown, loadChat, markGreetingShown } from './chat/api';
import { BotAvatar } from './chat/Avatar';
import type { ChatConfig } from './chat/types';

/**
 * Website AI chat assistant (bottom-left). Rendered by includes/partials/chatbot.php (chatbot_widget()), which prints
 * the same launcher as a plain link and mounts this island only after idle / first interaction. The chat window
 * itself (./chat/ChatWindow) is a separate lazy chunk loaded on first open (prefetched when the greeting shows or the
 * launcher is hovered / focused). Props = chatbot_public_config() (app/services/chatbot.php) + greetingMode.
 *
 * Greeting bubble ("Let's chat!") — deliberately unobtrusive:
 *   - appears only after the visitor has scrolled >= 30% of the page or spent 20 s on it (greetingScroll / greetingAfter),
 *   - never on /apply, /contact or the portal pages (greetingMode 'off'), and on campaign landing pages never while a
 *     lead form is on screen (greetingMode 'forms'),
 *   - appears only once scrolling has settled and nothing sits under it (headings, any text, links, images, buttons,
 *     form fields, tab/filter rows — AVOID), never overlaps the floating Enquire tab, WhatsApp button / tab, call or
 *     back-to-top button, never shows on top of an open dialog (e.g. the enquiry popup), and
 *     steps aside while the page rests with such content under it,
 *   - compact (2 lines), once per visit, folds away by itself after 15 s, and a dismissal (close, Esc or opening the
 *     chat) is remembered for 7 days.
 *
 * Open from anywhere: an element with [data-chat-open] (optional data-chat-message="…"), or
 *   window.dispatchEvent(new CustomEvent('gimt:chat-open', { detail: { message: 'Fees for MBA?' } }))
 */
export type ChatWidgetProps = ChatConfig & IslandBaseProps;

const loadWindow = () => import('./chat/ChatWindow');
const ChatWindow = lazy(loadWindow);

/** Pages where the bubble never appears (the launcher itself stays available). */
const NO_GREETING_PATHS = /^\/(apply|contact|portal|student-portal|faculty-portal|admin)(\/|$)/i;
/** Content the bubble must not cover: headings, controls and (since Stage C) any readable text, links or images — at
 * 1280–1440 px the left gutter is narrow, so the bubble waits for a genuinely empty spot (or never shows). */
const AVOID = 'h1, h2, h3, h4, h5, p, li, dt, dd, blockquote, figcaption, label, a[href], img, table, form, input, select, textarea, button, .btn, [role="tablist"], [role="tab"], .chip, .pill-tabs, [data-filter], [data-chat-avoid]';
/** Other floating widgets it must never overlap. */
const FLOATING = '.enquiry-tab, .wa-fab, .whatsapp-fab, .chat-tab, .call-fab, .back-to-top.is-visible, .cc-banner, #cc-modal, .cookie-consent';
/** Lead forms that block the bubble entirely on campaign landing pages. */
const LEAD_FORMS = 'form, [data-island="EnquiryForm"], [data-island="ApplyWizard"], [data-island="SmartEnquiry"], [data-chat-avoid]';
const BUBBLE_W = 256; // px (phones: 100vw - 170px, so it clears the back-to-top / WhatsApp buttons on the right)
const BUBBLE_LEFT = 72; // px from the launcher's left edge
const BUBBLE_H = 96; // px, generous estimate of the 2-line bubble
const AUTO_HIDE_MS = 15000;
const SETTLE_MS = 350; // scrolling has stopped

interface Box {
  left: number;
  right: number;
  top: number;
  bottom: number;
}
const overlaps = (a: Box, b: Box, pad = 6) => a.left < b.right + pad && a.right > b.left - pad && a.top < b.bottom + pad && a.bottom > b.top - pad;

/** Viewport box the bubble occupies next to the launcher. */
function bubbleBox(dock: HTMLElement): Box {
  const r = dock.getBoundingClientRect();
  const width = Math.min(BUBBLE_W, window.innerWidth - 170);
  return { left: r.left, right: r.left + BUBBLE_LEFT + width, top: Math.min(r.top, r.bottom - BUBBLE_H), bottom: r.bottom };
}

function onScreen(el: Element): DOMRect | null {
  const r = el.getBoundingClientRect();
  // > 2px: screen-reader-only headings (.sr-only, clipped to 1×1 px) are not visible content and must not block the bubble
  return r.width > 2 && r.height > 2 && r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth ? r : null;
}

/** True when the bubble can appear without covering content, another floating widget or (landing pages) a lead form. */
function placeIsClear(dock: HTMLElement, strictForms: boolean): boolean {
  if (!dock.getClientRects().length) return false; // launcher hidden (e.g. chat switched off on phones)
  if (document.documentElement.classList.contains('scroll-locked')) return false; // a dialog / drawer is open (enquiry popup …)
  const box = bubbleBox(dock);
  const host = dock.closest('#gimt-chat');
  const outside = (el: Element) => !host?.contains(el) && !el.closest('.mobile-cta, .site-header, [role="dialog"], [aria-modal="true"]');
  for (const el of Array.from(document.querySelectorAll(FLOATING))) {
    const r = onScreen(el);
    if (r && overlaps(r, box)) return false;
  }
  if (strictForms) {
    for (const el of Array.from(document.querySelectorAll(LEAD_FORMS))) {
      if (outside(el) && onScreen(el)) return false;
    }
  }
  for (const el of Array.from(document.querySelectorAll(AVOID))) {
    if (!outside(el)) continue;
    const r = onScreen(el);
    if (r && overlaps(r, box)) return false;
  }
  return true;
}

function scrolledShare(): number {
  const max = document.documentElement.scrollHeight - window.innerHeight;
  return max > 0 ? window.scrollY / max : 0;
}

export default function ChatWidget(props: ChatWidgetProps) {
  const cfg = props;
  const staticMode = useStaticMode();
  const [open, setOpen] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [greet, setGreet] = useState<'hidden' | 'typing' | 'shown'>('hidden');
  const [pulse, setPulse] = useState(false);
  /** Temporarily stepped aside (something important is under it) - the bubble's 15 s lifetime keeps running. */
  const [veiled, setVeiled] = useState(false);
  const [queued, setQueued] = useState<string | null>(null);
  const dock = useRef<HTMLDivElement>(null);
  const launcher = useRef<HTMLButtonElement>(null);
  const bubble = useRef<HTMLDivElement>(null);
  const openRef = useRef(false);
  openRef.current = open;

  const openChat = useCallback((message?: string | null) => {
    setLoaded(true);
    setOpen(true);
    setGreet('hidden');
    setPulse(false);
    dismissGreeting();
    if (message) setQueued(message);
  }, []);
  const closeChat = useCallback(() => setOpen(false), []);

  // opened by a click on the server-rendered launcher while this island was loading, or open on the previous page
  useEffect(() => {
    const host = dock.current?.closest('#gimt-chat');
    const clicked = host?.hasAttribute('data-open');
    host?.removeAttribute('data-open');
    const wasOpen = loadChat()?.open && window.matchMedia('(min-width: 640px)').matches;
    if (clicked || wasOpen) openChat();
  }, [openChat]);

  // greeting bubble: scroll / time trigger, then wait (after scrolling has settled) for a clear spot - see the docs above
  const mode = cfg.greetingMode ?? 'auto';
  useEffect(() => {
    const path = window.location.pathname.slice((cfg.basePath || '').replace(/\/$/, '').length) || '/';
    if (!cfg.greeting || mode === 'off' || NO_GREETING_PATHS.test(path) || greetingDismissed() || greetingShown() || (loadChat()?.messages.length ?? 0) > 0) return;
    const afterMs = Math.max(cfg.greetingDelay || 0, cfg.greetingAfter ?? 20) * 1000;
    const share = Math.min(1, Math.max(0, cfg.greetingScroll ?? 0.3));
    const strict = mode === 'forms';
    let triggered = false;
    let done = false;
    let scrolling = false;
    let settle = 0;
    const timers: number[] = [];
    const check = () => {
      if (done || scrolling || openRef.current || document.hidden || !dock.current) return;
      if (!triggered) triggered = performance.now() >= afterMs || scrolledShare() >= share;
      if (!triggered || !placeIsClear(dock.current, strict)) return;
      done = true;
      markGreetingShown();
      setVeiled(false);
      setGreet(staticMode ? 'shown' : 'typing');
      setPulse(!staticMode);
      void loadWindow();
      if (!staticMode) {
        timers.push(window.setTimeout(() => setGreet((g) => (g === 'typing' ? 'shown' : g)), 1100));
        timers.push(window.setTimeout(() => setPulse(false), 6000));
      }
    };
    // only while the page is at rest - never pop up over content that is still moving under the visitor's eyes
    const onScroll = () => {
      scrolling = true;
      window.clearTimeout(settle);
      settle = window.setTimeout(() => {
        scrolling = false;
        check();
      }, SETTLE_MS);
    };
    timers.push(window.setTimeout(check, Math.max(0, afterMs - performance.now()) + 50));
    const poll = window.setInterval(check, 1500); // time trigger in a background tab / waiting for a clear spot without scrolling
    window.addEventListener('scroll', onScroll, { passive: true });
    return () => {
      done = true;
      timers.forEach((t) => window.clearTimeout(t));
      window.clearTimeout(settle);
      window.clearInterval(poll);
      window.removeEventListener('scroll', onScroll);
    };
  }, [cfg.greeting, cfg.greetingDelay, cfg.greetingAfter, cfg.greetingScroll, cfg.basePath, mode, staticMode]);

  // while visible: fold away after 15 s (paused while hovered / focused); when the page comes to rest with a heading,
  // control, another floating widget or (landing pages) a lead form under the bubble, it steps aside until the spot is
  // clear again
  const visible = greet !== 'hidden';
  useEffect(() => {
    if (!visible) return;
    let hold = false;
    let left = AUTO_HIDE_MS;
    let since = Date.now();
    let timer = window.setTimeout(() => setGreet('hidden'), left);
    const el = bubble.current;
    const pause = () => {
      if (hold) return;
      hold = true;
      window.clearTimeout(timer);
      left -= Date.now() - since;
    };
    const resume = () => {
      if (!hold) return;
      hold = false;
      since = Date.now();
      timer = window.setTimeout(() => setGreet('hidden'), Math.max(4000, left));
    };
    el?.addEventListener('pointerenter', pause);
    el?.addEventListener('pointerleave', resume);
    el?.addEventListener('focusin', pause);
    el?.addEventListener('focusout', resume);
    let settle = 0;
    const onScroll = () => {
      window.clearTimeout(settle);
      settle = window.setTimeout(() => {
        if (dock.current && !el?.contains(document.activeElement)) setVeiled(!placeIsClear(dock.current, mode === 'forms'));
      }, SETTLE_MS);
    };
    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll);
    return () => {
      window.clearTimeout(timer);
      window.clearTimeout(settle);
      el?.removeEventListener('pointerenter', pause);
      el?.removeEventListener('pointerleave', resume);
      el?.removeEventListener('focusin', pause);
      el?.removeEventListener('focusout', resume);
      window.removeEventListener('scroll', onScroll);
      window.removeEventListener('resize', onScroll);
    };
  }, [visible, mode]);

  // global triggers
  useEffect(() => {
    const onEvent = (e: Event) => openChat((e as CustomEvent<{ message?: string }>).detail?.message ?? null);
    const onClick = (e: MouseEvent) => {
      const el = (e.target as Element | null)?.closest?.('[data-chat-open]');
      if (!el) return;
      e.preventDefault();
      openChat(el.getAttribute('data-chat-message'));
    };
    window.addEventListener('gimt:chat-open', onEvent);
    document.addEventListener('click', onClick);
    return () => {
      window.removeEventListener('gimt:chat-open', onEvent);
      document.removeEventListener('click', onClick);
    };
  }, [openChat]);

  const closeGreeting = () => {
    setGreet('hidden');
    setPulse(false);
    dismissGreeting();
  };

  if (!cfg.enabled) return null;
  const label = `Chat with the ${cfg.botName}${cfg.online ? ' (online)' : ''}`;

  return (
    <>
      <div ref={dock} className={clsx('gcb-dock fixed bottom-[calc(var(--mobile-cta-h,0px)+14px)] left-3 z-[44] sm:left-5 lg:bottom-6', open && 'pointer-events-none')}>
        {greet !== 'hidden' && !open && (
          <div
            ref={bubble}
            hidden={veiled}
            className={clsx(
              'absolute origin-bottom-left rounded-2xl rounded-bl-[6px] bg-white py-2.5 pl-3 pr-8 text-left shadow-[0_18px_44px_-16px_rgba(7,26,59,.45)] ring-1 ring-brand-900/5',
              !staticMode && 'animate-[pop-in_420ms_cubic-bezier(.2,.8,.2,1)_both]',
            )}
            // inline position + size so the bubble never collapses onto the launcher, whatever the CSS build state
            style={{ left: BUBBLE_LEFT, bottom: 6, width: `min(${BUBBLE_W}px, calc(100vw - 170px))` }}
            role="status"
            onKeyDown={(e) => {
              if (e.key === 'Escape') {
                e.stopPropagation();
                closeGreeting();
                launcher.current?.focus();
              }
            }}
          >
            <span className="absolute h-3 w-3 rotate-45 rounded-[2px] bg-white" style={{ left: -6, bottom: 12 }} aria-hidden="true" />
            <button type="button" onClick={() => openChat()} className="relative block w-full text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 rounded-lg" aria-label={`${cfg.greeting} — open the chat`}>
              <span className="block font-display text-[13px] font-bold leading-tight text-brand-900">Let&apos;s chat!</span>
              {greet === 'typing' ? (
                <span className="typing-dots mt-1 text-slate-400" aria-label="typing">
                  <span />
                  <span />
                  <span />
                </span>
              ) : (
                <span className={clsx('mt-0.5 line-clamp-2 block text-[12.5px] leading-snug text-slate-600', !staticMode && 'animate-[fade-in_300ms_ease-out_both]')}>{cfg.greeting}</span>
              )}
            </button>
            <button
              type="button"
              onClick={closeGreeting}
              style={{ right: 6, top: 6 }}
              className="absolute inline-flex h-7 w-7 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500"
              aria-label="Dismiss the chat greeting"
            >
              <X className="h-3.5 w-3.5" aria-hidden="true" />
            </button>
          </div>
        )}
        <button
          ref={launcher}
          type="button"
          onClick={() => (open ? closeChat() : openChat())}
          onPointerEnter={() => void loadWindow()}
          onFocus={() => void loadWindow()}
          aria-label={label}
          aria-haspopup="dialog"
          aria-expanded={open}
          className={clsx(
            'group relative grid h-[60px] w-[60px] place-items-center rounded-full bg-white shadow-[0_14px_32px_-12px_rgba(7,26,59,.55)] ring-[3px] focus-visible:outline-none focus-visible:ring-offset-2',
            cfg.online ? 'ring-emerald-500' : 'ring-slate-300',
            !staticMode && 'transition-[transform,opacity] duration-200 ease-out hover:scale-105 active:scale-95',
            open ? 'scale-75 opacity-0' : 'opacity-100',
          )}
          tabIndex={open ? -1 : 0}
        >
          {pulse && <span className={clsx('pulse-ring absolute inset-0 rounded-full', cfg.online ? 'text-emerald-400' : 'text-slate-300')} style={{ ['--pulse-scale' as string]: '1.45' }} aria-hidden="true" />}
          <BotAvatar src={cfg.avatar} size={52} />
          <span className={clsx('absolute bottom-0 right-0 h-3.5 w-3.5 rounded-full border-2 border-white', cfg.online ? 'bg-emerald-500' : 'bg-slate-400')} aria-hidden="true" />
        </button>
      </div>
      {loaded && (
        <Suspense fallback={null}>
          <ChatWindow cfg={cfg} open={open} staticMode={staticMode} onClose={closeChat} initialMessage={queued} onInitialMessageSent={() => setQueued(null)} returnFocusRef={launcher} />
        </Suspense>
      )}
    </>
  );
}
