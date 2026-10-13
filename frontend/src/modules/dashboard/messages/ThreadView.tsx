import { useEffect, useRef, useState, type FormEvent } from 'react';
import clsx from 'clsx';
import { ArrowLeft, CheckCheck, Mail, Reply, ReplyAll, Send, Star, Trash2 } from 'lucide-react';
import { Alert, Avatar, Button, IconButton, Skeleton, Textarea, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, timeAgo } from '@/lib/format';
import type { Thread, ThreadMessage } from './types';

interface ThreadViewProps {
  thread?: Thread;
  loading: boolean;
  error?: unknown;
  onBack: () => void;
  onChanged: () => void;
  onDeleted: () => void;
  onRetry: () => void;
  /** Current user id (shown as "you"). */
  meId: number;
}

function names(list: { name: string }[], max = 3) {
  const n = list.map((u) => u.name);
  return n.length > max ? `${n.slice(0, max).join(', ')} and ${n.length - max} more` : n.join(', ');
}

/** One conversation: header actions, messages (oldest first) and an inline reply box. */
export function ThreadView({ thread, loading, error, onBack, onChanged, onDeleted, onRetry, meId }: ThreadViewProps) {
  const toast = useToast();
  const confirm = useConfirm();
  const [body, setBody] = useState('');
  const [mode, setMode] = useState<'reply' | 'all'>('reply');
  const [err, setErr] = useState('');
  const [sending, setSending] = useState(false);
  const [busy, setBusy] = useState<string | null>(null);
  const endRef = useRef<HTMLDivElement>(null);
  const replyRef = useRef<HTMLTextAreaElement>(null);

  useEffect(() => {
    setBody('');
    setErr('');
    setMode('reply');
  }, [thread?.thread_id]);
  useEffect(() => {
    if (thread) endRef.current?.scrollIntoView({ block: 'nearest' });
  }, [thread]);

  if (error) {
    return (
      <div className="p-5">
        <Alert variant="error" title="Unable to open this conversation" action={<Button size="sm" variant="secondary" onClick={onRetry}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      </div>
    );
  }
  if (loading || !thread) {
    return (
      <div className="space-y-4 p-5">
        <Skeleton className="h-6 w-2/3" />
        {Array.from({ length: 2 }).map((_, i) => (
          <div key={i} className="flex gap-3">
            <Skeleton className="h-10 w-10 rounded-full" />
            <div className="flex-1 space-y-2"><Skeleton className="h-4 w-1/3" /><Skeleton className="h-20 w-full" /></div>
          </div>
        ))}
      </div>
    );
  }

  const last: ThreadMessage = thread.messages[thread.messages.length - 1];
  const lastIncoming = [...thread.messages].reverse().find((m) => !m.mine) ?? null;
  // Reply goes to the last person who wrote to me (or, in my own sent thread, to my last recipients).
  const replyTargets = lastIncoming ? [lastIncoming.from] : last.to;
  const allTargets = thread.participants;
  const targets = (mode === 'all' ? allTargets : replyTargets).filter((u) => u.id > 0);
  const canReplyAll = allTargets.length > replyTargets.length;

  const act = async (key: string, fn: () => Promise<{ message: string }>, after?: () => void) => {
    setBusy(key);
    try {
      const res = await fn();
      if (res.message) toast.success(res.message);
      (after ?? onChanged)();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const del = async () => {
    if (!(await confirm({ title: 'Delete this conversation?', message: 'It will be removed from your mailbox only. Other participants keep their copy.', confirmText: 'Delete', danger: true }))) return;
    await act('delete', () => api.del(`messages/thread/${thread.thread_id}`), onDeleted);
  };
  const delOne = async (m: ThreadMessage) => {
    if (!(await confirm({ title: 'Delete this message?', message: 'It will be removed from your view of the conversation.', confirmText: 'Delete', danger: true }))) return;
    await act(`m${m.id}`, () => api.del(`messages/${m.id}`), thread.messages.length > 1 ? onChanged : onDeleted);
  };

  const send = async (e: FormEvent) => {
    e.preventDefault();
    if (!body.trim()) {
      setErr('Write a reply before sending.');
      replyRef.current?.focus();
      return;
    }
    if (!targets.length) {
      setErr('There is nobody to reply to in this conversation.');
      return;
    }
    setSending(true);
    try {
      const res = await api.post('messages', { recipient_ids: targets.map((t) => t.id), body, reply_to: last.id });
      toast.success(res.message || 'Reply sent.');
      setBody('');
      setErr('');
      onChanged();
    } catch (e2) {
      const ae = e2 as ApiError;
      setErr(ae.errors?.body ?? ae.errors?.recipient_ids ?? ae.message);
    } finally {
      setSending(false);
    }
  };

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="flex items-start gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
        <IconButton icon={ArrowLeft} label="Back to conversations" onClick={onBack} className="lg:hidden" />
        <div className="min-w-0 flex-1 pt-1">
          <h2 className="font-display text-base font-semibold leading-snug text-slate-900 dark:text-white">{thread.subject}</h2>
          <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
            {thread.messages.length} message{thread.messages.length === 1 ? '' : 's'} · with {names(thread.participants) || 'former users'}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-0.5">
          <IconButton
            icon={Star}
            label={thread.starred ? 'Remove star' : 'Star conversation'}
            className={clsx(thread.starred && '!text-amber-500 [&>svg]:fill-amber-400')}
            disabled={busy === 'star'}
            onClick={() => act('star', () => api.post(`messages/thread/${thread.thread_id}/star`, { starred: !thread.starred }))}
          />
          <IconButton icon={Mail} label="Mark as unread" disabled={busy === 'unread' || !thread.messages.some((m) => !m.mine)} onClick={() => act('unread', () => api.post(`messages/thread/${thread.thread_id}/unread`), onBack)} />
          <IconButton icon={Trash2} label="Delete conversation" tone="danger" disabled={busy === 'delete'} onClick={del} />
        </div>
      </div>

      <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4">
        {thread.messages.map((m) => (
          <article key={m.id} className={clsx('group rounded-2xl border p-4', m.mine ? 'border-brand-100 bg-brand-50/40 dark:border-brand-500/20 dark:bg-brand-500/[.06]' : 'border-slate-100 bg-white dark:border-slate-800 dark:bg-slate-900')} aria-label={`Message from ${m.from.name}`}>
            <header className="flex items-start gap-3">
              <Avatar name={m.from.name} src={m.from.avatar} size="md" />
              <div className="min-w-0 flex-1">
                <p className="text-sm font-semibold text-slate-900 dark:text-white">
                  {m.mine ? 'You' : m.from.name}
                  {m.from.designation && !m.mine && <span className="block text-xs font-normal text-slate-500 sm:ml-1.5 sm:inline dark:text-slate-400">{m.from.designation}</span>}
                </p>
                <p className="truncate text-xs text-slate-500 dark:text-slate-400" title={m.to.map((t) => t.name).join(', ')}>
                  to {names(m.to.map((t) => ({ name: t.id === meId ? 'you' : t.name })), 4)}
                </p>
              </div>
              <div className="flex shrink-0 items-center gap-1">
                <time dateTime={m.created_at} title={formatDateTime(m.created_at)} className="text-xs text-slate-400">{timeAgo(m.created_at)}</time>
                <IconButton size="sm" icon={Trash2} label="Delete this message" tone="danger" className="sm:opacity-0 sm:group-hover:opacity-100 sm:group-focus-within:opacity-100" onClick={() => delOne(m)} disabled={busy === `m${m.id}`} />
              </div>
            </header>
            <div className="mt-3 whitespace-pre-wrap break-words text-[14px] sm:pl-12 leading-relaxed text-slate-700 dark:text-slate-200">{m.body}</div>
            {m.mine && (
              <p className="mt-2 flex items-center gap-1 text-[11px] sm:pl-12 text-slate-400">
                <CheckCheck className={clsx('h-3.5 w-3.5', m.read_by > 0 && 'text-brand-600 dark:text-brand-300')} aria-hidden />
                {m.read_by > 0 ? `Read by ${m.read_by} of ${m.to.length}` : 'Delivered'}
              </p>
            )}
          </article>
        ))}
        <div ref={endRef} />
      </div>

      <form onSubmit={send} noValidate className="border-t border-slate-100 p-4 dark:border-slate-800">
        <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
          <div className="pill-tabs" role="radiogroup" aria-label="Reply mode">
            <button type="button" role="radio" aria-checked={mode === 'reply'} onClick={() => setMode('reply')} className={clsx('pill-tab inline-flex items-center gap-1.5', mode === 'reply' && 'pill-tab-active')}>
              <Reply className="h-3.5 w-3.5" aria-hidden /> Reply
            </button>
            {canReplyAll && (
              <button type="button" role="radio" aria-checked={mode === 'all'} onClick={() => setMode('all')} className={clsx('pill-tab inline-flex items-center gap-1.5', mode === 'all' && 'pill-tab-active')}>
                <ReplyAll className="h-3.5 w-3.5" aria-hidden /> Reply all
              </button>
            )}
          </div>
          <p className="min-w-0 truncate text-xs text-slate-500 dark:text-slate-400">To: {names(targets, 4) || '—'}</p>
        </div>
        <label htmlFor="reply-body" className="sr-only">Reply</label>
        <Textarea
          ref={replyRef}
          id="reply-body"
          rows={3}
          value={body}
          maxLength={10000}
          invalid={!!err}
          onChange={(e) => { setBody(e.target.value); setErr(''); }}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) void send(e as unknown as FormEvent);
          }}
          placeholder={`Write a reply to ${names(targets, 2) || 'this conversation'}…`}
        />
        {err && <p className="form-error" role="alert">{err}</p>}
        <div className="mt-2 flex items-center justify-between gap-2">
          <span className="hidden text-[11px] text-slate-400 sm:inline"><kbd className="kbd">Ctrl</kbd> + <kbd className="kbd">Enter</kbd> to send</span>
          <Button type="submit" icon={Send} loading={sending} className="ml-auto">
            Send reply
          </Button>
        </div>
      </form>
    </div>
  );
}
