import clsx from 'clsx';
import { ArrowDown, ArrowUp, ChevronDown, Phone, PhoneCall, RotateCcw, RotateCw, ThumbsDown, ThumbsUp, X } from 'lucide-react';
import { useCallback, useEffect, useId, useLayoutEffect, useRef, useState, type KeyboardEvent, type RefObject } from 'react';
import { lockScroll, trapFocus } from '../../core/dom';
import { getJson, loadChat, postJson, saveChat, streamMessage, utmParams } from './api';
import { BotAvatar } from './Avatar';
import { WA_PILL, WhatsAppBadge } from './icons';
import LeadCard from './LeadCard';
import { Markdown, plainText, safeHref } from './markdown';
import type { ChatConfig, ChatMessage, ChatReply, ChatSuggestion, StoredChat } from './types';

interface Props {
  cfg: ChatConfig;
  open: boolean;
  staticMode: boolean;
  onClose: () => void;
  /** Message queued by a "data-chat-open" trigger / quick action before the window loaded. */
  initialMessage?: string | null;
  onInitialMessageSent?: () => void;
  returnFocusRef: RefObject<HTMLElement>;
}

let seq = 0;
const newKey = () => `m${Date.now().toString(36)}${(seq++).toString(36)}`;
const fresh = (): StoredChat => ({ v: 1, cid: null, messages: [], open: true, leadDone: false });
const timeLabel = (t: number) => new Date(t).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
const LINK = 'font-semibold text-accent-700 underline decoration-accent-300 decoration-1 underline-offset-2 hover:text-accent-800 hover:decoration-accent-600';
const CHIP =
  'inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-3 py-1.5 text-[13px] font-medium text-brand-900 shadow-sm transition hover:-translate-y-px hover:border-accent-400 hover:text-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-1 disabled:pointer-events-none disabled:opacity-50';
const ICON_BTN = 'inline-flex h-7 w-7 items-center justify-center rounded-full text-white/85 transition hover:bg-white/15 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white/70';

/** The chat window (lazy chunk): header, conversation, quick replies, lead card, composer. */
export default function ChatWindow({ cfg, open, staticMode, onClose, initialMessage, onInitialMessageSent, returnFocusRef }: Props) {
  const uid = useId();
  const [chat, setChat] = useState<StoredChat>(() => loadChat() ?? fresh());
  const [input, setInput] = useState('');
  const [busy, setBusy] = useState(false);
  const [leadAt, setLeadAt] = useState<string | null>(null);
  const [leadPhone, setLeadPhone] = useState<string | undefined>();
  const [reveal, setReveal] = useState<{ key: string; shown: number } | null>(null);
  const [announce, setAnnounce] = useState('');
  const [phase, setPhase] = useState<'hidden' | 'enter' | 'open' | 'leave'>(open ? 'enter' : 'hidden');
  const [atBottom, setAtBottom] = useState(true);
  const [unseen, setUnseen] = useState(false);
  const root = useRef<HTMLElement>(null);
  const list = useRef<HTMLDivElement>(null);
  const field = useRef<HTMLTextAreaElement>(null);
  const honeypot = useRef<HTMLInputElement>(null);
  const cidRef = useRef<string | null>(chat.cid);
  cidRef.current = chat.cid;

  /* ---------------------------------------------------------------- open / close transitions, focus trap, scroll lock */
  useEffect(() => {
    if (open) {
      setPhase('enter');
      const r = requestAnimationFrame(() => requestAnimationFrame(() => setPhase('open')));
      return () => cancelAnimationFrame(r);
    }
    setPhase((p) => (p === 'hidden' ? p : 'leave'));
    const t = window.setTimeout(() => setPhase('hidden'), staticMode ? 0 : 230);
    return () => window.clearTimeout(t);
  }, [open, staticMode]);

  useEffect(() => {
    if (!open || !root.current) return;
    const release = trapFocus(root.current, field.current);
    const unlock = window.matchMedia('(max-width: 639px)').matches ? lockScroll() : null;
    const returnTo = returnFocusRef.current;
    return () => {
      release();
      unlock?.();
      window.requestAnimationFrame(() => returnTo?.focus({ preventScroll: true }));
    };
  }, [open, returnFocusRef]);

  /* ---------------------------------------------------------------- persistence + resume */
  useEffect(() => {
    saveChat({ ...chat, open });
  }, [chat, open]);

  useEffect(() => {
    // stored token but no stored messages (storage trimmed / other tab): resume from the server
    if (chat.cid && chat.messages.length === 0) {
      void getJson<{ messages: { id: number; role: 'user' | 'assistant'; content: string; feedback: number | null; sources: ChatMessage['sources']; created_at: string }[] }>(
        `${cfg.endpoints.history}?conversation_id=${encodeURIComponent(chat.cid)}`,
      ).then((res) => {
        if (!res.ok || !res.data) {
          setChat(fresh());
          return;
        }
        setChat((c) => ({
          ...c,
          messages: res.data!.messages.map((m) => ({
            key: newKey(), id: m.id, role: m.role, text: m.content, at: Date.parse(m.created_at.replace(' ', 'T')) || Date.now(), sources: m.sources,
            feedback: m.feedback === 1 ? 'up' : m.feedback === -1 ? 'down' : null,
          })),
        }));
      });
    }
    // run once on mount
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  /* ---------------------------------------------------------------- typewriter reveal (also smooths streamed text) */
  useEffect(() => {
    if (!reveal) return;
    const msg = chat.messages.find((m) => m.key === reveal.key);
    if (!msg) {
      setReveal(null);
      return;
    }
    if (reveal.shown >= msg.text.length) {
      if (!msg.pending) setReveal(null);
      return;
    }
    const step = Math.max(3, Math.ceil(msg.text.length / 70));
    const t = window.setTimeout(() => setReveal((r) => (r && r.key === msg.key ? { ...r, shown: Math.min(msg.text.length, r.shown + step) } : r)), 16);
    return () => window.clearTimeout(t);
  }, [reveal, chat.messages]);

  /* ---------------------------------------------------------------- scrolling */
  const scrollToEnd = useCallback(
    (smooth = true) => {
      const el = list.current;
      if (el) el.scrollTo({ top: el.scrollHeight, behavior: smooth && !staticMode ? 'smooth' : 'auto' });
      setUnseen(false);
    },
    [staticMode],
  );
  useLayoutEffect(() => {
    if (atBottom) scrollToEnd(false);
    else setUnseen(true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [chat.messages, reveal?.shown, leadAt]);
  useEffect(() => {
    if (phase === 'open') scrollToEnd(false);
  }, [phase, scrollToEnd]);
  const onScroll = () => {
    const el = list.current;
    if (!el) return;
    const bottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
    setAtBottom(bottom);
    if (bottom) setUnseen(false);
  };

  /* ---------------------------------------------------------------- messages */
  const patch = (key: string, p: Partial<ChatMessage>) => setChat((c) => ({ ...c, messages: c.messages.map((m) => (m.key === key ? { ...m, ...p } : m)) }));

  const send = async (raw: string, retryKey?: string) => {
    const text = raw.trim().slice(0, cfg.maxLength);
    if (!text || busy) return;
    const userKey = retryKey ?? newKey();
    const botKey = newKey();
    setBusy(true);
    setAtBottom(true);
    setChat((c) => {
      const messages = retryKey
        ? c.messages.map((m) => (m.key === retryKey ? { ...m, failed: undefined } : m))
        : [...c.messages, { key: userKey, role: 'user' as const, text, at: Date.now() }];
      return { ...c, messages: [...messages, { key: botKey, role: 'assistant' as const, text: '', at: Date.now(), pending: true }] };
    });
    setAnnounce('The assistant is typing…');
    if (!retryKey) setInput('');
    const body: Record<string, unknown> = {
      conversation_id: cidRef.current, message: text, page: window.location.pathname + window.location.search, referrer: document.referrer.slice(0, 300),
      website: honeypot.current?.value ?? '', ...utmParams(),
    };
    if (!staticMode) setReveal({ key: botKey, shown: 0 });
    const res = cfg.streaming
      ? await streamMessage(cfg, body, {
          onMeta: (cid) => setChat((c) => ({ ...c, cid })),
          onDelta: (t) => setChat((c) => ({ ...c, messages: c.messages.map((m) => (m.key === botKey ? { ...m, text: m.text + t } : m)) })),
        })
      : await postJson<ChatReply>(cfg, cfg.endpoints.message, body);
    setBusy(false);
    if (res.ok && res.data) {
      const d = res.data;
      setChat((c) => ({
        ...c, cid: d.conversation_id,
        messages: c.messages.map((m) => (m.key === botKey ? { ...m, id: d.message_id, text: d.reply, pending: false, sources: d.sources, suggestions: d.suggestions, at: Date.now() } : m)),
      }));
      if (d.lead_prompt && cfg.leadCapture && !chat.leadDone) {
        setLeadAt(botKey);
        setLeadPhone(d.lead_prefill?.phone);
      }
      setAnnounce(`${cfg.botName}: ${plainText(d.reply)}`);
    } else {
      setReveal(null);
      setChat((c) => ({ ...c, messages: c.messages.filter((m) => m.key !== botKey).map((m) => (m.key === userKey ? { ...m, failed: res.message || 'Message not sent.' } : m)) }));
      setAnnounce(`Message not sent. ${res.message}`);
      if (!retryKey && res.status === 422) setInput(text);
    }
  };

  useEffect(() => {
    if (open && initialMessage && phase === 'open') {
      void send(initialMessage);
      onInitialMessageSent?.();
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialMessage, phase]);

  const pick = (s: ChatSuggestion) => {
    if (s.action === 'lead') {
      setLeadAt('end');
      setAtBottom(true);
    } else if (s.action === 'whatsapp' && cfg.whatsapp) {
      window.open(cfg.whatsapp, '_blank', 'noopener');
    } else if (s.action === 'link' && s.url) {
      const link = safeHref(s.url, cfg.basePath);
      if (link) window.location.href = link.href;
    } else {
      void send(s.message || s.label);
    }
  };

  const rate = async (m: ChatMessage, value: 'up' | 'down') => {
    if (!m.id || !chat.cid) return;
    const next = m.feedback === value ? null : value;
    patch(m.key, { feedback: next });
    const res = await postJson(cfg, cfg.endpoints.feedback, { conversation_id: chat.cid, message_id: m.id, value: next ?? 'none' });
    if (!res.ok) patch(m.key, { feedback: m.feedback ?? null });
    else if (next) setAnnounce('Thanks for your feedback!');
  };

  const leadDone = (message: string) => {
    setLeadAt(null);
    setChat((c) => ({ ...c, leadDone: true, messages: [...c.messages, { key: newKey(), role: 'event', text: message, at: Date.now() }] }));
    setAnnounce(message);
    window.setTimeout(() => field.current?.focus({ preventScroll: true }), 50);
  };

  const newChat = () => {
    setChat(fresh());
    setLeadAt(null);
    setReveal(null);
    setInput('');
    setAnnounce('Started a new chat.');
    field.current?.focus();
  };

  /* ---------------------------------------------------------------- composer */
  const grow = () => {
    const el = field.current;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 120)}px`;
  };
  useLayoutEffect(grow, [input]);
  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault();
      void send(input);
    }
  };

  const last = chat.messages[chat.messages.length - 1];
  const suggestions = !busy && last && last.role === 'assistant' && !last.pending && (!reveal || reveal.key !== last.key) ? last.suggestions ?? [] : [];
  const anim = staticMode ? '' : 'animate-[card-in_320ms_cubic-bezier(.2,.8,.2,1)_both]';
  const shown = phase === 'open';
  const leadCard = (autoFocus: boolean) => (
    <div className={clsx('pl-9', anim)}>
      <LeadCard cfg={cfg} conversationId={chat.cid} prefillPhone={leadPhone} onDone={leadDone} onDismiss={() => setLeadAt(null)} autoFocus={autoFocus} />
    </div>
  );

  return (
    <>
      <div
        className={clsx('fixed inset-0 z-[57] bg-brand-950/45 sm:hidden', !staticMode && 'transition-opacity duration-200', shown ? 'opacity-100' : 'pointer-events-none opacity-0', phase === 'hidden' && 'hidden')}
        onClick={onClose}
        aria-hidden="true"
      />
      <section
        ref={root}
        role="dialog"
        aria-modal="true"
        aria-labelledby={`${uid}-title`}
        aria-describedby={`${uid}-status`}
        hidden={phase === 'hidden'}
        onKeyDown={(e) => {
          if (e.key === 'Escape') {
            e.stopPropagation();
            onClose();
          }
        }}
        className={clsx(
          'gcb-window fixed inset-x-0 bottom-[var(--mobile-cta-h,0px)] z-[58] flex h-[min(86vh,680px)] flex-col overflow-hidden rounded-t-[1.6rem] bg-white shadow-[0_-18px_60px_-20px_rgba(7,26,59,.55)] supports-[height:100dvh]:h-[min(86dvh,680px)]',
          'sm:inset-x-auto sm:bottom-5 sm:left-5 sm:h-[min(580px,calc(100vh-40px))] sm:w-[380px] sm:rounded-[1.6rem] sm:shadow-[0_28px_70px_-24px_rgba(7,26,59,.6)] sm:ring-1 sm:ring-brand-900/10 sm:supports-[height:100dvh]:h-[min(580px,calc(100dvh-40px))] lg:bottom-6 lg:left-6',
          'origin-bottom sm:origin-bottom-left',
          !staticMode && 'transition-[opacity,transform] duration-[260ms] ease-[cubic-bezier(.2,.8,.2,1)] motion-reduce:transition-none',
          shown ? 'translate-y-0 scale-100 opacity-100' : 'translate-y-4 scale-[.94] opacity-0',
        )}
      >
        {/* Header */}
        <header className="relative shrink-0 overflow-hidden bg-gradient-to-br from-brand-900 via-brand-900 to-[#16407F] px-3.5 pb-3.5 pt-3 text-white">
          <span className="pointer-events-none absolute -right-10 -top-14 h-36 w-36 rounded-full bg-accent-500/25 blur-2xl" aria-hidden="true" />
          <span className="pointer-events-none absolute right-0 top-0 h-full w-24 bg-[radial-gradient(circle_at_1px_1px,rgba(255,255,255,.18)_1px,transparent_0)] [background-size:10px_10px] opacity-60" aria-hidden="true" />
          <div className="relative flex items-center gap-2.5">
            <BotAvatar src={cfg.avatar} size={40} online={cfg.online} ring="ring-2 ring-white/80" />
            <div className="min-w-0 flex-1">
              <h2 id={`${uid}-title`} className="truncate font-display text-[14px] font-bold leading-tight tracking-[-0.01em] text-white sm:text-[14.5px]">{cfg.botName}</h2>
              <p id={`${uid}-status`} className="mt-0.5 flex items-center gap-1.5 text-[12px] text-white/80">
                <span className={clsx('h-2 w-2 rounded-full', cfg.online ? 'bg-emerald-400' : 'bg-slate-300')} aria-hidden="true" />
                {cfg.statusText}
              </p>
            </div>
            <div className="-mr-1 flex items-center">
              {chat.messages.length > 0 && (
                <button type="button" onClick={newChat} className={ICON_BTN} aria-label="Start a new chat" title="New chat">
                  <RotateCcw className="h-4 w-4" aria-hidden="true" />
                </button>
              )}
              {cfg.tel && (
                <a href={cfg.tel} className={ICON_BTN} aria-label={`Call admissions: ${cfg.phone}`} title={`Call ${cfg.phone}`}>
                  <Phone className="h-4 w-4" aria-hidden="true" />
                </a>
              )}
              <button type="button" onClick={onClose} className={clsx(ICON_BTN, 'hidden sm:inline-flex')} aria-label="Minimise chat" title="Minimise">
                <ChevronDown className="h-[18px] w-[18px]" aria-hidden="true" />
              </button>
              <button type="button" onClick={onClose} className={ICON_BTN} aria-label="Close chat" title="Close">
                <X className="h-[18px] w-[18px]" aria-hidden="true" />
              </button>
            </div>
          </div>
        </header>

        {/* Conversation */}
        <div className="relative min-h-0 flex-1">
          <div ref={list} onScroll={onScroll} className="h-full overflow-y-auto overscroll-contain bg-[#FAF8F5] px-3.5 pb-4 pt-4 [scrollbar-width:thin]" role="log" aria-live="off" aria-label="Conversation">
            {/* welcome */}
            <div className="flex items-end gap-2">
              <BotAvatar src={cfg.avatar} size={28} />
              <div className="max-w-[85%] rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 shadow-sm ring-1 ring-slate-900/5">
                <p className="font-display text-[15px] font-bold text-brand-900">{cfg.welcome}</p>
                {cfg.welcomeSub && <p className="mt-1 text-[13px] leading-snug text-slate-600">{cfg.welcomeSub}</p>}
              </div>
            </div>
            {cfg.offlineMessage && (
              <p className="mt-2.5 rounded-xl border border-amber-200 bg-amber-50 px-3 py-2 text-[12.5px] leading-snug text-amber-900">{cfg.offlineMessage}</p>
            )}
            {chat.messages.length === 0 && cfg.quickReplies.length > 0 && (
              <div className="mt-3 flex flex-wrap gap-1.5 pl-9" role="group" aria-label="Suggested topics">
                {cfg.quickReplies.map((q) => (
                  <SuggestionChip key={q.label} s={q} onPick={pick} disabled={busy} />
                ))}
              </div>
            )}

            <ul className="mt-3 space-y-3">
              {chat.messages.map((m) => {
                const text = reveal && reveal.key === m.key ? m.text.slice(0, reveal.shown) : m.text;
                if (m.role === 'event') {
                  return (
                    <li key={m.key} className={clsx('flex justify-center', anim)}>
                      <p className="max-w-[92%] rounded-xl bg-emerald-50 px-3 py-2 text-center text-[12.5px] font-medium leading-snug text-emerald-900 ring-1 ring-emerald-200">✓ {m.text}</p>
                    </li>
                  );
                }
                if (m.role === 'user') {
                  return (
                    <li key={m.key} className={clsx('flex flex-col items-end', anim)}>
                      <div className="max-w-[85%] whitespace-pre-wrap rounded-2xl rounded-br-md bg-accent-600 px-3.5 py-2.5 text-[14px] leading-relaxed text-white shadow-sm [overflow-wrap:anywhere]">{m.text}</div>
                      {m.failed ? (
                        <p className="mt-1 flex flex-wrap items-center justify-end gap-x-2 text-[12px] text-red-700" role="alert">
                          <span>{m.failed}</span>
                          <button type="button" onClick={() => void send(m.text, m.key)} disabled={busy} className="inline-flex items-center gap-1 font-semibold underline underline-offset-2 hover:text-red-900 disabled:opacity-50">
                            <RotateCw className="h-3 w-3" aria-hidden="true" /> Retry
                          </button>
                        </p>
                      ) : (
                        <span className="mt-1 text-[11px] text-slate-500">{timeLabel(m.at)}</span>
                      )}
                    </li>
                  );
                }
                const typing = m.pending && m.text === '';
                return (
                  <li key={m.key} className={clsx(anim)}>
                    <div className="flex items-end gap-2">
                      <BotAvatar src={cfg.avatar} size={28} />
                      <div className="max-w-[85%] rounded-2xl rounded-bl-md bg-white px-3.5 py-2.5 text-[14px] leading-relaxed text-slate-800 shadow-sm ring-1 ring-slate-900/5">
                        {typing ? (
                          <span className="typing-dots py-1.5 text-slate-400" role="status" aria-label="The assistant is typing">
                            <span />
                            <span />
                            <span />
                          </span>
                        ) : (
                          <Markdown text={text} basePath={cfg.basePath} linkClass={LINK} />
                        )}
                      </div>
                    </div>
                    {!m.pending && !(reveal && reveal.key === m.key) && (
                      <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 pl-9 text-[11px] text-slate-500">
                        <span>{timeLabel(m.at)}</span>
                        {m.sources && m.sources.length > 0 && (
                          <span className="flex min-w-0 flex-wrap items-center gap-1">
                            <span aria-hidden="true">·</span> Source:
                            {m.sources.slice(0, 2).map((s) => {
                              const link = safeHref(s.url, cfg.basePath);
                              return link ? (
                                <a key={s.url} href={link.href} className="max-w-[11rem] truncate font-medium text-brand-700 underline-offset-2 hover:underline">{s.title}</a>
                              ) : null;
                            })}
                          </span>
                        )}
                        {m.id && (
                          <span className="ml-auto flex items-center gap-0.5">
                            <button type="button" onClick={() => void rate(m, 'up')} aria-pressed={m.feedback === 'up'} aria-label="Helpful answer" title="Helpful" className={clsx('inline-flex h-6 w-6 items-center justify-center rounded-full transition hover:bg-emerald-50 hover:text-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500', m.feedback === 'up' ? 'bg-emerald-100 text-emerald-700' : 'text-slate-400')}>
                              <ThumbsUp className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                            <button type="button" onClick={() => void rate(m, 'down')} aria-pressed={m.feedback === 'down'} aria-label="Not helpful" title="Not helpful" className={clsx('inline-flex h-6 w-6 items-center justify-center rounded-full transition hover:bg-red-50 hover:text-red-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-red-500', m.feedback === 'down' ? 'bg-red-100 text-red-700' : 'text-slate-400')}>
                              <ThumbsDown className="h-3.5 w-3.5" aria-hidden="true" />
                            </button>
                          </span>
                        )}
                      </div>
                    )}
                    {leadAt === m.key && <div className="mt-2.5">{leadCard(false)}</div>}
                  </li>
                );
              })}
            </ul>
            {leadAt === 'end' && <div className="mt-3">{leadCard(true)}</div>}
            {suggestions.length > 0 && (
              <div className={clsx('mt-2.5 flex flex-wrap gap-1.5 pl-9', anim)} role="group" aria-label="Suggested follow-ups">
                {suggestions.filter((s) => s.action !== 'lead' || (cfg.leadCapture && !chat.leadDone && !leadAt)).map((s) => (
                  <SuggestionChip key={s.label} s={s} onPick={pick} disabled={busy} />
                ))}
              </div>
            )}
          </div>
          {unseen && !atBottom && (
            <button type="button" onClick={() => scrollToEnd()} className="absolute bottom-3 left-1/2 inline-flex -translate-x-1/2 items-center gap-1 rounded-full bg-brand-900 px-3 py-1.5 text-[12px] font-semibold text-white shadow-soft hover:bg-brand-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500">
              <ArrowDown className="h-3.5 w-3.5" aria-hidden="true" /> New messages
            </button>
          )}
        </div>

        {/* Composer */}
        <div className="shrink-0 border-t border-slate-200/80 bg-white px-3 pb-[calc(10px+env(safe-area-inset-bottom))] pt-2.5 sm:pb-2.5">
          <form
            onSubmit={(e) => {
              e.preventDefault();
              void send(input);
            }}
            className="flex items-end gap-2 rounded-2xl border border-slate-200 bg-[#FAF8F5] py-1.5 pl-3 pr-1.5 transition focus-within:border-accent-400 focus-within:bg-white focus-within:ring-2 focus-within:ring-accent-500/25"
          >
            <label htmlFor={`${uid}-input`} className="sr-only">Type your question</label>
            <textarea
              ref={field}
              id={`${uid}-input`}
              rows={1}
              value={input}
              maxLength={cfg.maxLength}
              onChange={(e) => setInput(e.target.value)}
              onKeyDown={onKey}
              placeholder="Type your question…"
              aria-describedby={`${uid}-count`}
              className="max-h-[120px] min-h-[36px] flex-1 resize-none bg-transparent py-1.5 text-[14.5px] leading-snug text-slate-900 placeholder:text-slate-400 focus:outline-none focus:ring-0 focus-visible:outline-none focus-visible:ring-0 focus-visible:ring-offset-0"
            />
            <input ref={honeypot} type="text" name="website" tabIndex={-1} autoComplete="off" aria-hidden="true" className="absolute -left-[9999px] h-px w-px opacity-0" />
            <button
              type="submit"
              disabled={busy || !input.trim()}
              className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-600 text-white shadow-flame transition hover:bg-accent-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500 focus-visible:ring-offset-2 disabled:bg-slate-300 disabled:shadow-none"
              aria-label="Send message"
            >
              <ArrowUp className="h-[18px] w-[18px]" strokeWidth={2.4} aria-hidden="true" />
            </button>
          </form>
          <div className="mt-1.5 grid grid-cols-[1fr_auto_1fr] items-center gap-2 px-1 text-[10.5px] leading-snug">
            <span aria-hidden="true" />
            <Credit credit={cfg.credit} />
            <span id={`${uid}-count`} className={clsx('justify-self-end tabular-nums text-slate-500', input.length > cfg.maxLength * 0.9 && 'font-semibold text-accent-700')} aria-label={`${input.length} of ${cfg.maxLength} characters`}>
              {input.length}/{cfg.maxLength}
            </span>
          </div>
        </div>
        <div className="sr-only" aria-live="polite" aria-atomic="true">{announce}</div>
      </section>
    </>
  );
}

/** "Powered by Appsgain Technologies" — the words after "by " link to the configured URL (new tab). */
function Credit({ credit }: { credit: ChatConfig['credit'] }) {
  if (!credit) return <span aria-hidden="true" />;
  const i = credit.text.toLowerCase().lastIndexOf('by ');
  const prefix = i >= 0 ? credit.text.slice(0, i + 3) : '';
  const name = i >= 0 ? credit.text.slice(i + 3) : credit.text;
  let host = '';
  try {
    host = credit.url ? new URL(credit.url).host : '';
  } catch {
    host = '';
  }
  return (
    <p className="text-center text-slate-600 opacity-[.65] transition-opacity hover:opacity-100 focus-within:opacity-100">
      {prefix}
      {credit.url ? (
        <a href={credit.url} target="_blank" rel="noopener" className="font-semibold underline-offset-2 hover:underline focus-visible:underline focus-visible:outline-none" aria-label={`${name} (opens ${host || 'the website'} in a new tab)`}>
          {name}
        </a>
      ) : (
        <span className="font-semibold">{name}</span>
      )}
    </p>
  );
}

function SuggestionChip({ s, onPick, disabled }: { s: ChatSuggestion; onPick: (s: ChatSuggestion) => void; disabled?: boolean }) {
  if (s.action === 'whatsapp') {
    return (
      <button type="button" onClick={() => onPick(s)} className={WA_PILL} aria-label={`${s.label} (opens WhatsApp in a new tab)`}>
        <WhatsAppBadge />
        {s.label}
      </button>
    );
  }
  return (
    <button type="button" onClick={() => onPick(s)} disabled={disabled && !s.action} className={clsx(CHIP, s.action === 'lead' && 'border-accent-200 bg-accent-50 text-accent-800 hover:bg-white')}>
      {s.action === 'lead' && <PhoneCall className="h-3.5 w-3.5" aria-hidden="true" />}
      {s.label}
      {s.action === 'link' && <span aria-hidden="true">→</span>}
    </button>
  );
}
