import { Fragment, useEffect, useLayoutEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import {
  ArrowLeft,
  Bot,
  CheckCircle2,
  ChevronDown,
  CircleDot,
  Clock3,
  PanelRightClose,
  PanelRightOpen,
  RefreshCw,
  RotateCcw,
  Trash2,
  UserCheck,
  UserMinus,
  UserPlus,
} from 'lucide-react';
import { Alert, Avatar, Button, Dropdown, IconButton, Skeleton, Spinner, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { WaBubble, WindowBadge } from '../shared';
import { Composer } from './Composer';
import { useApplyConversation, useThread } from './hooks';
import type { ConvStatus, InboxAgent, InboxDetail, InboxMessage, InboxSummary, SendPayload } from './types';
import { dayLabel, messageDayKey } from './utils';

interface Props {
  convId: number;
  detail: InboxDetail | undefined;
  detailError: unknown;
  agents: InboxAgent[];
  showInfo: boolean;
  onToggleInfo: () => void;
  onBack: () => void;
  onChanged: (extra?: ('notes' | 'activity' | 'tags' | 'agents')[]) => void;
  onManageQuickReplies: () => void;
}

const STATUS_META: Record<ConvStatus, { label: string; icon: typeof CircleDot; cls: string }> = {
  open: { label: 'Open', icon: CircleDot, cls: 'text-emerald-600 dark:text-emerald-400' },
  pending: { label: 'Pending', icon: Clock3, cls: 'text-amber-600 dark:text-amber-400' },
  resolved: { label: 'Resolved', icon: CheckCircle2, cls: 'text-slate-500 dark:text-slate-400' },
};

export function ChatWindow({ convId, detail, detailError, agents, showInfo, onToggleInfo, onBack, onChanged, onManageQuickReplies }: Props) {
  const toast = useToast();
  const { session } = useAuth();
  const thread = useThread(convId);
  const applyConversation = useApplyConversation();
  const scroller = useRef<HTMLDivElement>(null);
  const content = useRef<HTMLDivElement>(null);
  const nearBottom = useRef(true);
  const restore = useRef<{ height: number; top: number } | null>(null);
  const lastCount = useRef(0);
  const markedRead = useRef<number>(0);
  const [busy, setBusy] = useState<string | null>(null);

  const summary: InboxSummary | undefined = thread.summary ?? detail?.conversation;
  const perms = detail?.permissions;
  const meId = session?.user?.id ?? 0;

  // initial scroll to bottom when a conversation opens
  useEffect(() => {
    lastCount.current = 0;
    nearBottom.current = true;
    markedRead.current = 0;
  }, [convId]);

  useLayoutEffect(() => {
    const el = scroller.current;
    if (!el) return;
    if (restore.current) {
      el.scrollTop = el.scrollHeight - restore.current.height + restore.current.top;
      restore.current = null;
    } else if (thread.messages.length !== lastCount.current) {
      const lastMine = thread.messages[thread.messages.length - 1]?.pending === 'sending';
      if (nearBottom.current || lastCount.current === 0 || lastMine) el.scrollTop = el.scrollHeight;
    }
    lastCount.current = thread.messages.length;
  }, [thread.messages]);

  // images / media load after the first scroll and push the thread down: stay pinned to the newest message
  useEffect(() => {
    const el = scroller.current;
    const inner = content.current;
    if (!el || !inner || typeof ResizeObserver === 'undefined') return;
    const ro = new ResizeObserver(() => {
      if (nearBottom.current && !restore.current) el.scrollTop = el.scrollHeight;
    });
    ro.observe(inner);
    ro.observe(el); // the composer / banners change the viewport height too
    return () => ro.disconnect();
  }, [convId]);

  // reading the chat clears the unread badge
  useEffect(() => {
    if (!summary || summary.unread_count <= 0 || document.hidden) return;
    const stamp = summary.unread_count * 1000 + (thread.messages[thread.messages.length - 1]?.id ?? 0);
    if (markedRead.current === stamp) return;
    markedRead.current = stamp;
    void api.post(`whatsapp/inbox/conversations/${convId}/read`).then(() => onChanged()).catch(() => undefined);
  }, [summary, convId, onChanged, thread.messages]);

  const onScroll = () => {
    const el = scroller.current;
    if (!el) return;
    nearBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 140;
    if (el.scrollTop < 80 && thread.hasMore && !thread.loadingOlder) {
      restore.current = { height: el.scrollHeight, top: el.scrollTop };
      void thread.loadOlder().then((loaded) => {
        if (!loaded) restore.current = null;
      });
    }
  };

  const act = async (key: string, fn: () => Promise<{ message: string; data?: { conversation?: InboxSummary } | null }>, extra: ('notes' | 'activity' | 'tags' | 'agents')[] = []) => {
    setBusy(key);
    try {
      const res = await fn();
      if (res.message) toast.success(res.message);
      applyConversation(convId, res.data?.conversation);
      onChanged(extra);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  type ConvRes = { conversation?: InboxSummary };
  const assign = (userId: number | null) => act('assign', () => api.post<ConvRes>(`whatsapp/inbox/conversations/${convId}/assign`, { user_id: userId }), ['agents']);
  const setStatus = (status: ConvStatus) => act('status', () => api.post<ConvRes>(`whatsapp/inbox/conversations/${convId}/status`, { status }), ['agents']);
  const setBot = (active: boolean) => act('bot', () => api.post<ConvRes>(`whatsapp/inbox/conversations/${convId}/bot`, { active }));

  const send = async (payload: SendPayload, preview: Partial<InboxMessage>) => {
    nearBottom.current = true;
    const res = await thread.send(payload, preview);
    onChanged(['agents']);
    return res;
  };

  const retry = async (m: InboxMessage) => {
    setBusy(`retry-${m.id}`);
    try {
      const res = await api.post<{ message: InboxMessage }>(`whatsapp/inbox/messages/${m.id}/retry`);
      thread.replaceMessage(res.data.message);
      toast.success(res.message || 'Message queued again.');
      void thread.latest.refetch();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const resend = (m: InboxMessage) => {
    if (!m.payload) return;
    void thread.send(m.payload, m).catch((e: ApiError) => toast.error(e.message));
  };

  if (thread.latest.error && !thread.latest.data) {
    const err = thread.latest.error as ApiError;
    return (
      <div className="flex h-full flex-col">
        <div className="flex items-center gap-2 border-b border-slate-100 p-3 lg:hidden dark:border-slate-800">
          <IconButton icon={ArrowLeft} label="Back to conversations" onClick={onBack} />
        </div>
        <div className="p-6">
          <Alert variant="error" title={err.status === 403 ? 'You cannot open this conversation' : err.status === 404 ? 'Conversation not found' : 'Unable to load messages'} action={err.status >= 500 || err.status === 0 ? <Button size="xs" variant="secondary" onClick={() => thread.latest.refetch()}>Retry</Button> : undefined}>
            {err.message}
          </Alert>
        </div>
      </div>
    );
  }

  const name = summary?.name ?? detail?.profile.name ?? '…';
  const statusMeta = summary ? STATUS_META[summary.status] : STATUS_META.open;
  const assignedToMe = summary?.assigned_to === meId;
  const windowOpen = summary ? summary.window_open : false;

  return (
    <div className="flex h-full min-h-0 flex-col">
      {/* header */}
      <div className="flex items-center gap-2 border-b border-slate-200 bg-white px-2 py-2 sm:px-3 dark:border-slate-800 dark:bg-slate-900">
        <IconButton icon={ArrowLeft} label="Back to conversations" onClick={onBack} className="lg:hidden" />
        {summary ? (
          <button type="button" onClick={onToggleInfo} className="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40" aria-label={`Contact details for ${name}`}>
            <Avatar name={summary.has_name ? summary.name : '#'} size="md" />
            <span className="min-w-0">
              <span className="flex items-center gap-2">
                <span className="truncate font-semibold text-slate-900 dark:text-white">{name}</span>
                {summary.bot_active && summary.status !== 'resolved' && (
                  <span className="hidden items-center gap-0.5 rounded bg-violet-100 px-1.5 py-px text-[10px] font-semibold text-violet-700 sm:inline-flex dark:bg-violet-500/15 dark:text-violet-300"><Bot className="h-3 w-3" aria-hidden />Bot</span>
                )}
              </span>
              <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                <span className="tabular-nums">{summary.phone_display}</span>
                <WindowBadge lastInboundAt={summary.window_last_inbound_at} compact className="!py-0 text-[11px]" />
              </span>
            </span>
          </button>
        ) : (
          <div className="flex flex-1 items-center gap-3"><Skeleton className="h-9 w-9 rounded-full" /><div className="space-y-1.5"><Skeleton className="h-3.5 w-32" /><Skeleton className="h-3 w-24" /></div></div>
        )}

        {summary && perms && (
          <div className="flex shrink-0 items-center gap-1">
            {perms.edit ? (
              <Dropdown
                label="Assign conversation"
                width="w-64"
                triggerClassName="btn btn-secondary btn-sm hidden !px-2 md:inline-flex"
                trigger={
                  <>
                    {busy === 'assign' ? <Spinner className="h-4 w-4" /> : summary.assigned_name ? <Avatar name={summary.assigned_name} src={summary.assigned_avatar} size="xs" /> : <UserPlus className="h-4 w-4" aria-hidden />}
                    <span className="hidden max-w-[6rem] truncate 2xl:inline">{summary.assigned_name ? (assignedToMe ? 'You' : summary.assigned_name.split(' ')[0]) : 'Assign'}</span>
                    <span className="sr-only 2xl:hidden">{summary.assigned_name ? `Assigned to ${summary.assigned_name}` : 'Assign'}</span>
                    <ChevronDown className="h-3.5 w-3.5 opacity-60" aria-hidden />
                  </>
                }
                header={<span className="text-xs font-semibold text-slate-500">Assign to a team member</span>}
                items={[
                  !assignedToMe && { label: 'Assign to me', icon: UserCheck, onClick: () => assign(meId) },
                  ...agents.filter((a) => a.id !== meId).map((a) => ({ label: a.name, icon: summary.assigned_to === a.id ? UserCheck : undefined, hint: `${a.open_assigned} open`, onClick: () => assign(a.id), disabled: summary.assigned_to === a.id })),
                  summary.assigned_to !== null && { label: 'Unassign', icon: UserMinus, onClick: () => assign(null), danger: true, divider: true },
                ]}
              />
            ) : perms.claim ? (
              <Button size="sm" variant="secondary" icon={UserCheck} loading={busy === 'assign'} onClick={() => assign(meId)} className="hidden md:inline-flex">Claim</Button>
            ) : null}

            {perms.edit && (
              <Dropdown
                label="Conversation status"
                width="w-52"
                triggerClassName="btn btn-secondary btn-sm !px-2"
                trigger={
                  <>
                    {busy === 'status' ? <Spinner className="h-4 w-4" /> : <statusMeta.icon className={clsx('h-4 w-4', statusMeta.cls)} aria-hidden />}
                    <span className="hidden 2xl:inline">{statusMeta.label}</span>
                    <span className="sr-only 2xl:hidden">Status: {statusMeta.label}</span>
                    <ChevronDown className="h-3.5 w-3.5 opacity-60" aria-hidden />
                  </>
                }
                items={[
                  ...(Object.keys(STATUS_META) as ConvStatus[]).map((s) => ({ label: STATUS_META[s].label, icon: STATUS_META[s].icon, disabled: summary.status === s, onClick: () => setStatus(s) })),
                  summary.status !== 'resolved' && { label: summary.bot_active ? 'Take over from bot' : 'Hand back to bot', icon: Bot, onClick: () => setBot(!summary.bot_active), divider: true },
                ]}
              />
            )}
            {perms.edit && (summary.status === 'resolved' ? (
              <Button size="sm" variant="secondary" icon={RotateCcw} loading={busy === 'status'} onClick={() => setStatus('open')} className="hidden !px-2 sm:inline-flex 2xl:!px-3" title="Reopen conversation">
                <span className="hidden 2xl:inline">Reopen</span><span className="sr-only 2xl:hidden">Reopen</span>
              </Button>
            ) : (
              <Button size="sm" variant="success" icon={CheckCircle2} loading={busy === 'status'} onClick={() => setStatus('resolved')} className="hidden !px-2 sm:inline-flex 2xl:!px-3" title="Resolve conversation">
                <span className="hidden 2xl:inline">Resolve</span><span className="sr-only 2xl:hidden">Resolve</span>
              </Button>
            ))}
            <IconButton icon={showInfo ? PanelRightClose : PanelRightOpen} label={showInfo ? 'Hide contact details' : 'Show contact details'} onClick={onToggleInfo} />
          </div>
        )}
      </div>

      {detailError ? <div className="px-3 pt-2"><Alert variant="warning">{(detailError as ApiError).message}</Alert></div> : null}

      {summary?.bot_active && summary.status !== 'resolved' && perms?.edit && (
        <div className="flex flex-wrap items-center justify-between gap-2 border-b border-violet-100 bg-violet-50 px-3 py-1.5 text-xs text-violet-800 dark:border-violet-500/20 dark:bg-violet-500/10 dark:text-violet-200">
          <span className="flex items-center gap-1.5"><Bot className="h-3.5 w-3.5" aria-hidden /> The AI bot is answering this chat. Replying here takes over automatically.</span>
          <Button size="xs" variant="ghost" loading={busy === 'bot'} onClick={() => setBot(false)} className="!text-violet-800 dark:!text-violet-200">Take over</Button>
        </div>
      )}

      {/* messages */}
      <div ref={scroller} onScroll={onScroll} className="min-h-0 flex-1 overflow-y-auto bg-[#EFEAE2] px-3 py-3 sm:px-6 dark:bg-[#0B141A]" aria-live="polite" aria-label="Messages">
        <div ref={content} className="flex min-h-full flex-col">
        {thread.loadingOlder && (
          <div className="flex justify-center py-2"><span className="rounded-full bg-white/90 px-3 py-1 text-xs text-slate-500 shadow-sm dark:bg-slate-800 dark:text-slate-300"><Spinner className="mr-1.5 inline h-3 w-3" />Loading earlier messages…</span></div>
        )}
        {!thread.hasMore && thread.messages.length > 0 && (
          <p className="mx-auto mb-3 max-w-sm rounded-lg bg-[#FFF5C4] px-3 py-1.5 text-center text-[11px] text-slate-600 shadow-sm dark:bg-[#182229] dark:text-amber-200/80">
            Start of the conversation. Messages go through the official WhatsApp Business API (Interakt); ticks show the delivery and read status WhatsApp reports. Reading a chat here does not send blue ticks to the contact — Interakt has no read-receipt API.
          </p>
        )}
        {thread.latest.isLoading ? (
          <div className="space-y-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <div key={i} className={clsx('flex', i % 2 ? 'justify-end' : 'justify-start')}><Skeleton className={clsx('h-12 rounded-lg', i % 3 ? 'w-2/5' : 'w-3/5')} /></div>
            ))}
          </div>
        ) : thread.messages.length === 0 ? (
          <div className="flex flex-1 items-center justify-center">
            <p className="rounded-lg bg-white/90 px-4 py-2 text-center text-sm text-slate-600 shadow-sm dark:bg-slate-800 dark:text-slate-300">No messages yet. {windowOpen ? 'Say hello!' : 'Start with an approved template.'}</p>
          </div>
        ) : (
          <div className="space-y-1.5">
            {thread.messages.map((m, i) => {
              const prev = thread.messages[i - 1];
              const newDay = !prev || messageDayKey(prev.created_at) !== messageDayKey(m.created_at);
              const tail = newDay || !prev || prev.direction !== m.direction || prev.sender_type !== m.sender_type;
              return (
                <Fragment key={m.client_ref ?? m.id}>
                  {newDay && (
                    <div className="flex justify-center py-1.5" role="separator" aria-label={dayLabel(m.created_at)}>
                      <span className="rounded-lg bg-white/95 px-3 py-1 text-[11px] font-medium uppercase tracking-wide text-slate-500 shadow-sm dark:bg-[#182229] dark:text-slate-400">{dayLabel(m.created_at)}</span>
                    </div>
                  )}
                  <div className={clsx(tail && i > 0 && 'pt-1.5', m.pending === 'sending' && 'opacity-80')}>
                    <WaBubble message={m.pending === 'error' ? { ...m, status: 'failed' } : m} tail={tail} showSender={m.direction === 'out' && (tail || m.sender_type !== 'agent')} />
                    {m.pending === 'error' && (
                      <div className="mt-0.5 flex items-center justify-end gap-2 text-[11px]">
                        <span className="text-red-600 dark:text-red-400">Not sent: {m.pending_error}</span>
                        <button type="button" className="inline-flex items-center gap-1 font-semibold text-brand-700 hover:underline dark:text-brand-300" onClick={() => resend(m)}><RefreshCw className="h-3 w-3" aria-hidden />Retry</button>
                        <button type="button" className="inline-flex items-center gap-1 text-slate-500 hover:underline" onClick={() => m.client_ref && thread.discard(m.client_ref)}><Trash2 className="h-3 w-3" aria-hidden />Discard</button>
                      </div>
                    )}
                    {!m.pending && m.can_retry && perms?.send && (
                      <div className="mt-0.5 flex justify-end">
                        <button type="button" disabled={busy === `retry-${m.id}`} onClick={() => retry(m)} className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] font-semibold text-brand-700 hover:bg-white/60 disabled:opacity-50 dark:text-brand-300 dark:hover:bg-white/5">
                          {busy === `retry-${m.id}` ? <Spinner className="h-3 w-3" /> : <RefreshCw className="h-3 w-3" aria-hidden />} Retry sending
                        </button>
                      </div>
                    )}
                  </div>
                </Fragment>
              );
            })}
          </div>
        )}
        </div>
      </div>

      {summary && perms ? (
        <Composer
          convId={convId}
          contactName={summary.has_name ? summary.name.split(' ')[0] : summary.phone_display}
          windowOpen={windowOpen}
          canSend={perms.send}
          blocked={summary.blocked}
          onSend={send}
          onManageQuickReplies={onManageQuickReplies}
        />
      ) : (
        <div className="border-t border-slate-200 p-3 dark:border-slate-800"><Skeleton className="h-10 w-full rounded-lg" /></div>
      )}
    </div>
  );
}
