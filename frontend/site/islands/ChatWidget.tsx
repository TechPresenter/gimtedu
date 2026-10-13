import clsx from 'clsx';
import { X } from 'lucide-react';
import { lazy, Suspense, useCallback, useEffect, useRef, useState } from 'react';
import { useStaticMode } from '../lib/hooks';
import type { IslandBaseProps } from '../lib/types';
import { dismissGreeting, greetingDismissed, loadChat } from './chat/api';
import { BotAvatar } from './chat/Avatar';
import type { ChatConfig } from './chat/types';

/**
 * Website AI chat assistant (bottom-left). Rendered by includes/partials/chatbot.php (chatbot_widget()), which prints
 * the same launcher as a plain link and mounts this island only after idle / first interaction. The chat window
 * itself (./chat/ChatWindow) is a separate lazy chunk loaded on first open (prefetched when the greeting shows or the
 * launcher is hovered / focused). Props = chatbot_public_config() (app/services/chatbot.php).
 *
 * Open from anywhere: an element with [data-chat-open] (optional data-chat-message="…"), or
 *   window.dispatchEvent(new CustomEvent('gimt:chat-open', { detail: { message: 'Fees for MBA?' } }))
 */
export type ChatWidgetProps = ChatConfig & IslandBaseProps;

const loadWindow = () => import('./chat/ChatWindow');
const ChatWindow = lazy(loadWindow);

export default function ChatWidget(props: ChatWidgetProps) {
  const cfg = props;
  const staticMode = useStaticMode();
  const [open, setOpen] = useState(false);
  const [loaded, setLoaded] = useState(false);
  const [greet, setGreet] = useState<'hidden' | 'typing' | 'shown'>('hidden');
  const [pulse, setPulse] = useState(false);
  const [queued, setQueued] = useState<string | null>(null);
  const dock = useRef<HTMLDivElement>(null);
  const launcher = useRef<HTMLButtonElement>(null);

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

  // greeting bubble after the configured delay (counted from page load), once per session
  useEffect(() => {
    if (!cfg.greeting || greetingDismissed() || (loadChat()?.messages.length ?? 0) > 0) return;
    const wait = Math.max(0, cfg.greetingDelay * 1000 - performance.now());
    const timers: number[] = [];
    timers.push(
      window.setTimeout(() => {
        setGreet(staticMode ? 'shown' : 'typing');
        setPulse(!staticMode);
        void loadWindow();
        if (!staticMode) timers.push(window.setTimeout(() => setGreet((g) => (g === 'typing' ? 'shown' : g)), 1300));
        if (!staticMode) timers.push(window.setTimeout(() => setPulse(false), 9000));
      }, wait),
    );
    return () => timers.forEach((t) => window.clearTimeout(t));
  }, [cfg.greeting, cfg.greetingDelay, staticMode]);

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
      <div ref={dock} className={clsx('fixed bottom-[calc(var(--mobile-cta-h,0px)+14px)] left-3 z-[44] sm:left-5 lg:bottom-6', open && 'pointer-events-none')}>
        {greet !== 'hidden' && !open && (
          <div
            className={clsx(
              'absolute bottom-1.5 left-[72px] origin-bottom-left whitespace-normal rounded-2xl rounded-bl-[6px] bg-white py-3 pl-3.5 pr-9 text-left shadow-[0_18px_44px_-16px_rgba(7,26,59,.45)] ring-1 ring-brand-900/5',
              !staticMode && 'animate-[pop-in_420ms_cubic-bezier(.2,.8,.2,1)_both]',
            )}
            // inline size so the bubble never collapses to the launcher column, whatever the CSS build state
            style={{ width: 'min(288px, calc(100vw - 140px))', minWidth: 'min(240px, calc(100vw - 140px))' }}
            role="status"
          >
            <span className="absolute -left-[6px] bottom-3 h-3 w-3 rotate-45 rounded-[2px] bg-white" aria-hidden="true" />
            <button type="button" onClick={() => openChat()} className="relative block w-full text-left focus-visible:outline-none" aria-label={`${cfg.greeting} — open the chat`}>
              <span className="block font-display text-[14px] font-bold text-brand-900">Let&apos;s chat!</span>
              {greet === 'typing' ? (
                <span className="typing-dots mt-1.5 text-slate-400" aria-label="typing">
                  <span />
                  <span />
                  <span />
                </span>
              ) : (
                <span className={clsx('mt-0.5 block text-[13px] leading-snug text-slate-600', !staticMode && 'animate-[fade-in_300ms_ease-out_both]')}>{cfg.greeting}</span>
              )}
            </button>
            <button
              type="button"
              onClick={closeGreeting}
              className="absolute right-1.5 top-1.5 inline-flex h-7 w-7 items-center justify-center rounded-full text-slate-400 transition hover:bg-slate-100 hover:text-slate-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500"
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
