import { useEffect, useRef } from 'react';
import clsx from 'clsx';
import { Bot, Inbox, MessageCircleMore, SearchX, UserRound } from 'lucide-react';
import { Alert, Avatar, Button, EmptyState, SearchInput, Skeleton } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import { StatusTicks } from '../shared';
import type { InboxCounts, InboxFilter, InboxRow } from './types';
import { listTime, tagClass } from './utils';

export const FILTERS: { key: InboxFilter; label: string; short: string }[] = [
  { key: 'all', label: 'All', short: 'All' },
  { key: 'unread', label: 'Unread', short: 'Unread' },
  { key: 'read', label: 'Read', short: 'Read' },
  { key: 'mine', label: 'Assigned to me', short: 'Mine' },
  { key: 'unassigned', label: 'Unassigned', short: 'Unassigned' },
  { key: 'open', label: 'Open', short: 'Open' },
  { key: 'pending', label: 'Pending', short: 'Pending' },
  { key: 'resolved', label: 'Resolved', short: 'Resolved' },
  { key: 'bot', label: 'Bot handling', short: 'Bot' },
];

interface Props {
  rows: InboxRow[];
  counts?: InboxCounts;
  filter: InboxFilter;
  onFilter: (f: InboxFilter) => void;
  q: string;
  onQ: (q: string) => void;
  activeId: number | null;
  onOpen: (id: number) => void;
  loading: boolean;
  error: unknown;
  onRetry: () => void;
  hasMore: boolean;
  loadingMore: boolean;
  onLoadMore: () => void;
}

export function ConversationList({ rows, counts, filter, onFilter, q, onQ, activeId, onOpen, loading, error, onRetry, hasMore, loadingMore, onLoadMore }: Props) {
  const sentinel = useRef<HTMLDivElement>(null);
  const scroller = useRef<HTMLDivElement>(null);

  // infinite scroll: load the next page when the sentinel scrolls into view
  useEffect(() => {
    const el = sentinel.current;
    if (!el || !hasMore) return;
    const io = new IntersectionObserver((entries) => entries[0]?.isIntersecting && !loadingMore && onLoadMore(), { root: scroller.current, rootMargin: '200px' });
    io.observe(el);
    return () => io.disconnect();
  }, [hasMore, loadingMore, onLoadMore, rows.length]);

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div className="space-y-2.5 border-b border-slate-100 p-3 dark:border-slate-800">
        <SearchInput value={q} onChange={onQ} placeholder="Search name, phone, application no…" size="sm" />
        <div className="flex flex-wrap gap-1.5" role="tablist" aria-label="Conversation filters">
          {FILTERS.map((f) => {
            const n = counts?.[f.key];
            const active = filter === f.key;
            return (
              <button
                key={f.key}
                type="button"
                role="tab"
                aria-selected={active}
                onClick={() => onFilter(f.key)}
                title={f.label}
                className={clsx(
                  'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/50',
                  active
                    ? 'bg-brand-900 text-white ring-brand-900 dark:bg-brand-600 dark:ring-brand-600'
                    : 'bg-white text-slate-600 ring-slate-200 hover:bg-slate-50 dark:bg-slate-900 dark:text-slate-300 dark:ring-slate-700 dark:hover:bg-slate-800',
                )}
              >
                {f.key === 'bot' && <Bot className="h-3 w-3" aria-hidden />}
                <span className="sr-only">{f.label}</span>
                <span aria-hidden>{f.short}</span>
                {n !== undefined && (
                  <span
                    className={clsx(
                      'min-w-[1.25rem] rounded-full px-1 text-center text-[10px] font-bold tabular-nums',
                      active ? 'bg-white/20 text-white' : f.key === 'unread' && n > 0 ? 'bg-accent-600 text-white' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300',
                    )}
                  >
                    {formatNumber(n)}
                  </span>
                )}
              </button>
            );
          })}
        </div>
      </div>

      <div ref={scroller} className="min-h-0 flex-1 overflow-y-auto" aria-label="Conversations">
        {error ? (
          <div className="p-3">
            <Alert variant="error" title="Unable to load conversations" action={<Button size="xs" variant="secondary" onClick={onRetry}>Retry</Button>}>
              {(error as ApiError).message}
            </Alert>
          </div>
        ) : loading ? (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800" aria-busy>
            {Array.from({ length: 8 }).map((_, i) => (
              <li key={i} className="flex gap-3 px-3 py-3">
                <Skeleton className="h-11 w-11 shrink-0 rounded-full" />
                <div className="flex-1 space-y-2 pt-1">
                  <Skeleton className="h-3.5 w-2/3" />
                  <Skeleton className="h-3 w-11/12" />
                </div>
              </li>
            ))}
          </ul>
        ) : rows.length === 0 ? (
          <EmptyState
            icon={q ? SearchX : filter === 'all' ? MessageCircleMore : Inbox}
            title={q ? `No chats match “${q}”` : filter === 'all' ? 'No WhatsApp conversations yet' : `Nothing in “${FILTERS.find((f) => f.key === filter)?.label}”`}
            description={q ? 'Try a name, a phone number or an application number.' : filter === 'all' ? 'Conversations appear here as soon as a student or parent messages your WhatsApp number, or you message a contact.' : 'Switch the filter to see other conversations.'}
            action={q ? <Button size="sm" variant="secondary" onClick={() => onQ('')}>Clear search</Button> : filter !== 'all' ? <Button size="sm" variant="secondary" onClick={() => onFilter('all')}>Show all chats</Button> : <Button size="sm" to="/whatsapp/contacts" icon={UserRound}>Open contacts</Button>}
            className="!py-10"
          />
        ) : (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800/80">
            {rows.map((r) => (
              <ConversationRow key={r.id} row={r} active={r.id === activeId} onOpen={onOpen} />
            ))}
          </ul>
        )}
        {hasMore && !loading && !error && (
          <div ref={sentinel} className="flex justify-center p-3">
            <Button size="xs" variant="ghost" loading={loadingMore} onClick={onLoadMore}>Load more</Button>
          </div>
        )}
      </div>
    </div>
  );
}

function ConversationRow({ row: r, active, onOpen }: { row: InboxRow; active: boolean; onOpen: (id: number) => void }) {
  const unread = r.unread_count > 0;
  return (
    <li className="relative">
      {active && <span className="absolute inset-y-0 left-0 z-10 w-1 rounded-r bg-accent-600" aria-hidden />}
      <button
        type="button"
        onClick={() => onOpen(r.id)}
        aria-current={active ? 'true' : undefined}
        className={clsx(
          'flex w-full gap-3 px-3 py-3 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-brand-500/40',
          active ? 'bg-brand-50/80 dark:bg-brand-500/10' : 'hover:bg-slate-50 dark:hover:bg-slate-800/50',
        )}
      >
        <span className="relative h-9 w-9 shrink-0 self-start">
          <Avatar name={r.has_name ? r.name : '#'} size="md" />
          <span
            className={clsx('absolute -bottom-0.5 -right-0.5 h-3 w-3 rounded-full ring-2 ring-white dark:ring-slate-900', r.window_open ? 'bg-emerald-500' : 'bg-slate-300 dark:bg-slate-600')}
            title={r.window_open ? '24-hour window open' : '24-hour window closed (templates only)'}
            aria-label={r.window_open ? '24-hour window open' : '24-hour window closed'}
          />
        </span>
        <span className="min-w-0 flex-1">
          <span className="flex items-baseline justify-between gap-2">
            <span className={clsx('truncate text-sm', unread ? 'font-bold text-slate-900 dark:text-white' : 'font-semibold text-slate-800 dark:text-slate-100')}>{r.name}</span>
            <time className={clsx('shrink-0 text-[11px] tabular-nums', unread ? 'font-semibold text-accent-700 dark:text-accent-300' : 'text-slate-400')}>{listTime(r.last_message_at)}</time>
          </span>
          <span className="mt-0.5 flex items-center gap-1">
            {r.last_message_direction === 'out' && r.last_status && <StatusTicks status={r.last_status} size={14} />}
            <span className={clsx('min-w-0 flex-1 truncate text-[13px]', unread ? 'font-medium text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400')}>
              {r.last_message_preview || <span className="italic">No messages yet</span>}
            </span>
            {unread && (
              <span className="ml-1 inline-flex h-5 min-w-[1.25rem] shrink-0 items-center justify-center rounded-full bg-[#25D366] px-1.5 text-[11px] font-bold text-white" aria-label={`${r.unread_count} unread`}>
                {r.unread_count > 99 ? '99+' : r.unread_count}
              </span>
            )}
          </span>
          <span className="mt-1.5 flex items-center gap-1.5">
            {r.status !== 'open' && (
              <span className={clsx('rounded px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide', r.status === 'pending' ? 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400')}>
                {r.status}
              </span>
            )}
            {r.bot_active && r.status !== 'resolved' && (
              <span className="inline-flex items-center gap-0.5 rounded bg-violet-100 px-1.5 py-px text-[10px] font-semibold text-violet-700 dark:bg-violet-500/15 dark:text-violet-300" title="The AI bot is answering this chat">
                <Bot className="h-3 w-3" aria-hidden /> Bot
              </span>
            )}
            {r.tags.slice(0, 2).map((t) => (
              <span key={t.id} className={clsx('truncate rounded px-1.5 py-px text-[10px] font-medium ring-1 ring-inset', tagClass(t.color))}>{t.name}</span>
            ))}
            {r.tags.length > 2 && <span className="text-[10px] text-slate-400">+{r.tags.length - 2}</span>}
            <span className="ml-auto shrink-0" title={r.assigned_name ? `Assigned to ${r.assigned_name}` : 'Unassigned'}>
              {r.assigned_name ? (
                <Avatar name={r.assigned_name} src={r.assigned_avatar} size="xs" />
              ) : (
                <span className="text-[10px] font-medium text-slate-400">Unassigned</span>
              )}
            </span>
          </span>
        </span>
      </button>
    </li>
  );
}
