import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Inbox, Mail, MailOpen, MessagesSquare, PenSquare, Send, Star, Trash2, type LucideIcon } from 'lucide-react';
import { Alert, Avatar, Button, Card, EmptyState, PageHeader, SearchInput, Skeleton, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatNumber, timeAgo } from '@/lib/format';
import type { Option } from '@/lib/types';
import { ComposeModal } from './messages/ComposeModal';
import { ThreadView } from './messages/ThreadView';
import type { ConversationPage, ConversationRow, Folder, MessageCounts, Thread } from './messages/types';

const FOLDERS: { key: Folder; label: string; icon: LucideIcon }[] = [
  { key: 'inbox', label: 'Inbox', icon: Inbox },
  { key: 'starred', label: 'Starred', icon: Star },
  { key: 'sent', label: 'Sent', icon: Send },
];

function shortTime(iso: string): string {
  const d = new Date(iso.replace(' ', 'T'));
  const now = new Date();
  if (d.toDateString() === now.toDateString()) return d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  if (now.getTime() - d.getTime() < 6 * 86400000) return d.toLocaleDateString('en-GB', { weekday: 'short' });
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' });
}

export default function MessagesPage() {
  const [params, setParams] = useSearchParams();
  const folder: Folder = (['inbox', 'starred', 'sent'] as Folder[]).includes(params.get('folder') as Folder) ? (params.get('folder') as Folder) : 'inbox';
  const threadId = Number(params.get('thread')) || null;
  const unreadOnly = folder === 'inbox' && params.get('unread') === '1';
  const [q, setQ] = useState(params.get('q') ?? '');
  const debouncedQ = useDebounce(q.trim(), 300);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [compose, setCompose] = useState<{ open: boolean; to: Option[] }>({ open: false, to: [] });
  const { session } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();

  const update = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
  };
  useEffect(() => {
    if ((params.get('q') ?? '') !== debouncedQ) update({ q: debouncedQ || null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debouncedQ]);
  useEffect(() => setSelected(new Set()), [folder, debouncedQ, unreadOnly]);

  // Deep link: /messages?compose=1&to=5,7 opens the composer with recipients.
  useEffect(() => {
    if (params.get('compose') !== '1') return;
    const ids = (params.get('to') ?? '').split(',').map(Number).filter(Boolean);
    const openWith = (to: Option[]) => setCompose({ open: true, to });
    if (ids.length) api.get<Option[]>('messages/recipients', { ids: ids.join(',') }).then(openWith).catch(() => openWith([]));
    else openWith([]);
    update({ compose: null, to: null });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const counts = useQuery({ queryKey: ['message-counts'], queryFn: () => api.get<MessageCounts>('messages/counts'), staleTime: 15_000 });
  const list = useInfiniteQuery({
    queryKey: ['messages', folder, debouncedQ, unreadOnly],
    queryFn: ({ pageParam, signal }) => api.get<ConversationPage>('messages', { folder, q: debouncedQ || undefined, unread: unreadOnly ? 1 : undefined, page: pageParam, per_page: 20 }, { signal }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.page < last.pages ? last.page + 1 : undefined),
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.rows) ?? [], [list.data]);
  const total = list.data?.pages[0]?.total ?? 0;

  const thread = useQuery({
    queryKey: ['message-thread', threadId],
    queryFn: () => api.get<Thread>(`messages/thread/${threadId}`),
    enabled: !!threadId,
  });

  const refreshAll = () => Promise.all([
    qc.invalidateQueries({ queryKey: ['messages'] }),
    qc.invalidateQueries({ queryKey: ['message-counts'] }),
    qc.invalidateQueries({ queryKey: ['notification-counts'] }),
  ]);

  // Opening a conversation reads it.
  useEffect(() => {
    if (thread.data && thread.data.unread > 0) {
      void api.post(`messages/thread/${thread.data.thread_id}/read`).then(() => {
        qc.setQueryData<Thread>(['message-thread', threadId], (t) => (t ? { ...t, unread: 0, messages: t.messages.map((m) => ({ ...m, is_read: true })) } : t));
        void refreshAll();
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [thread.data?.thread_id, thread.data?.unread]);

  const openThread = (id: number | null) => update({ thread: id ? String(id) : null });
  const toggleSel = (id: number) => setSelected((s) => {
    const n = new Set(s);
    if (n.has(id)) n.delete(id);
    else n.add(id);
    return n;
  });
  const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.thread_id));

  const bulk = async (action: 'read' | 'unread' | 'star' | 'unstar' | 'delete') => {
    const ids = [...selected];
    if (!ids.length) return;
    if (action === 'delete' && !(await confirm({ title: `Delete ${ids.length} conversation${ids.length === 1 ? '' : 's'}?`, message: 'They will be removed from your mailbox only.', confirmText: 'Delete', danger: true }))) return;
    try {
      const res = await api.post('messages/bulk', { action, thread_ids: ids });
      toast.success(res.message);
      setSelected(new Set());
      if (action === 'delete' && threadId && ids.includes(threadId)) openThread(null);
      await refreshAll();
      if (threadId) void qc.invalidateQueries({ queryKey: ['message-thread', threadId] });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const toggleStar = async (r: ConversationRow) => {
    try {
      await api.post(`messages/thread/${r.thread_id}/star`, { starred: !r.starred });
      await refreshAll();
      if (threadId === r.thread_id) void qc.invalidateQueries({ queryKey: ['message-thread', threadId] });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const c = counts.data;
  const folderCount = (f: Folder) => (f === 'inbox' ? c?.unread : f === 'starred' ? c?.starred : undefined);

  return (
    <>
      <PageHeader
        title="Messages"
        description={c ? (c.unread ? `${formatNumber(c.unread)} unread message${c.unread === 1 ? '' : 's'} in your inbox.` : 'Your inbox is up to date.') : 'Internal messages between SmartCampus users.'}
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'Messages' }]}
        actions={<Button icon={PenSquare} onClick={() => setCompose({ open: true, to: [] })}>Compose</Button>}
      />

      <Card className="overflow-hidden">
        <div className="grid min-h-[34rem] grid-cols-1 lg:h-[calc(100vh-15rem)] lg:min-h-[36rem] lg:grid-cols-12">
          {/* Folders + list */}
          <div className={clsx('flex min-h-0 min-w-0 flex-col border-slate-100 lg:col-span-5 lg:border-r xl:col-span-4 dark:border-slate-800', threadId && 'hidden lg:flex')}>
            <div className="space-y-3 border-b border-slate-100 p-3 dark:border-slate-800">
              <nav className="pill-tabs w-full" aria-label="Message folders">
                {FOLDERS.map((f) => {
                  const n = folderCount(f.key);
                  return (
                    <button key={f.key} type="button" onClick={() => update({ folder: f.key === 'inbox' ? null : f.key, thread: null, unread: null })} className={clsx('pill-tab inline-flex flex-1 items-center justify-center gap-1.5', folder === f.key && 'pill-tab-active')} aria-current={folder === f.key ? 'page' : undefined}>
                      <f.icon className="h-3.5 w-3.5" aria-hidden />
                      {f.label}
                      {!!n && <span className={clsx('rounded-full px-1.5 text-[10px] font-bold', f.key === 'inbox' ? 'bg-brand-700 text-white' : 'bg-slate-200 text-slate-700 dark:bg-slate-700 dark:text-slate-200')}>{n}</span>}
                    </button>
                  );
                })}
              </nav>
              <SearchInput value={q} onChange={setQ} placeholder="Search messages, people…" size="sm" />
              <div className="flex min-h-[2rem] items-center justify-between gap-2">
                <label className="flex items-center gap-2 text-xs text-slate-500">
                  <input type="checkbox" className="form-checkbox" checked={allSelected} disabled={!rows.length} onChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.thread_id)))} />
                  {selected.size ? `${selected.size} selected` : `${formatNumber(total)} conversation${total === 1 ? '' : 's'}`}
                </label>
                {selected.size > 0 ? (
                  <div className="flex items-center gap-1">
                    <Button size="xs" variant="ghost" icon={MailOpen} onClick={() => bulk('read')} aria-label="Mark selected as read">Read</Button>
                    <Button size="xs" variant="ghost" icon={Mail} onClick={() => bulk('unread')} aria-label="Mark selected as unread">Unread</Button>
                    <Button size="xs" variant="ghost" icon={Star} onClick={() => bulk(folder === 'starred' ? 'unstar' : 'star')}>{folder === 'starred' ? 'Unstar' : 'Star'}</Button>
                    <Button size="xs" variant="ghost" icon={Trash2} className="!text-red-600" onClick={() => bulk('delete')}>Delete</Button>
                  </div>
                ) : (
                  folder === 'inbox' && <Toggle checked={unreadOnly} onChange={(v) => update({ unread: v ? '1' : null })} label={<span className="text-xs">Unread only</span>} />
                )}
              </div>
            </div>

            <div className="min-h-0 flex-1 overflow-y-auto">
              {list.error ? (
                <div className="p-4"><Alert variant="error" title="Unable to load messages">{(list.error as ApiError).message}</Alert></div>
              ) : list.isLoading ? (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {Array.from({ length: 6 }).map((_, i) => (
                    <li key={i} className="flex gap-3 p-4"><Skeleton className="h-10 w-10 rounded-full" /><div className="flex-1 space-y-2"><Skeleton className="h-4 w-1/2" /><Skeleton className="h-3 w-5/6" /></div></li>
                  ))}
                </ul>
              ) : rows.length === 0 ? (
                <EmptyState
                  icon={debouncedQ ? MessagesSquare : folder === 'starred' ? Star : folder === 'sent' ? Send : Inbox}
                  title={debouncedQ ? `No messages match “${debouncedQ}”` : folder === 'starred' ? 'No starred conversations' : folder === 'sent' ? 'You have not sent any messages' : unreadOnly ? 'No unread messages' : 'Your inbox is empty'}
                  description={folder === 'starred' ? 'Star important conversations to find them quickly here.' : 'Messages from colleagues appear here. Start a conversation with anyone on the team.'}
                  action={<Button size="sm" icon={PenSquare} onClick={() => setCompose({ open: true, to: [] })}>Compose</Button>}
                  className="!py-10"
                />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {rows.map((r) => {
                    const people = folder === 'sent' || (r.mine && folder !== 'inbox') ? r.to : r.from ? [r.from] : [];
                    const who = folder === 'sent' || (r.mine && folder !== 'inbox') ? `To: ${people.map((p) => p.name).join(', ') || '—'}` : r.from?.name ?? 'Deleted user';
                    const active = threadId === r.thread_id;
                    return (
                      <li key={r.thread_id} className={clsx('relative flex gap-3 px-3 py-3 transition', active ? 'bg-brand-50 dark:bg-brand-500/10' : r.unread ? 'bg-white dark:bg-slate-900' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40')}>
                        {active && <span className="absolute inset-y-0 left-0 w-1 rounded-r bg-brand-700" aria-hidden />}
                        <input type="checkbox" className="form-checkbox mt-3" checked={selected.has(r.thread_id)} onChange={() => toggleSel(r.thread_id)} aria-label={`Select conversation: ${r.subject}`} />
                        <button type="button" onClick={() => openThread(r.thread_id)} className="flex min-w-0 flex-1 gap-3 rounded-lg text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40" aria-current={active ? 'true' : undefined}>
                          <Avatar name={people[0]?.name ?? '?'} src={people[0]?.avatar} size="md" />
                          <span className="min-w-0 flex-1">
                            <span className="flex items-baseline justify-between gap-2">
                              <span className={clsx('truncate text-sm', r.unread ? 'font-bold text-slate-900 dark:text-white' : 'font-medium text-slate-700 dark:text-slate-200')}>
                                {who}
                                {r.messages > 1 && <span className="ml-1 text-xs font-normal text-slate-400">({r.messages})</span>}
                              </span>
                              <time dateTime={r.created_at} title={timeAgo(r.created_at)} className={clsx('shrink-0 text-[11px]', r.unread ? 'font-semibold text-brand-700 dark:text-brand-300' : 'text-slate-400')}>{shortTime(r.created_at)}</time>
                            </span>
                            <span className={clsx('block truncate text-[13px]', r.unread ? 'font-semibold text-slate-800 dark:text-slate-100' : 'text-slate-600 dark:text-slate-300')}>{r.subject}</span>
                            <span className="line-clamp-1 text-xs text-slate-500 dark:text-slate-400">{r.snippet}</span>
                          </span>
                        </button>
                        <div className="flex flex-col items-center gap-1.5 pt-0.5">
                          <button type="button" onClick={() => toggleStar(r)} className="rounded p-0.5 text-slate-300 transition hover:text-amber-500 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40" aria-label={r.starred ? 'Remove star' : 'Star conversation'} aria-pressed={r.starred}>
                            <Star className={clsx('h-4 w-4', r.starred && 'fill-amber-400 text-amber-500')} aria-hidden />
                          </button>
                          {r.unread > 0 && <span className="h-2 w-2 rounded-full bg-brand-600" aria-label={`${r.unread} unread`} />}
                        </div>
                      </li>
                    );
                  })}
                  {list.hasNextPage && (
                    <li className="p-3 text-center">
                      <Button size="sm" variant="secondary" onClick={() => list.fetchNextPage()} loading={list.isFetchingNextPage}>Load more</Button>
                    </li>
                  )}
                </ul>
              )}
            </div>
          </div>

          {/* Conversation */}
          <section className={clsx('min-h-0 min-w-0 lg:col-span-7 xl:col-span-8', !threadId && 'hidden lg:block')} aria-label="Conversation">
            {threadId ? (
              <ThreadView
                thread={thread.data}
                loading={thread.isLoading}
                error={thread.error}
                meId={session?.user?.id ?? 0}
                onBack={() => openThread(null)}
                onRetry={() => thread.refetch()}
                onChanged={() => {
                  void refreshAll();
                  void thread.refetch();
                }}
                onDeleted={() => {
                  openThread(null);
                  void refreshAll();
                }}
              />
            ) : (
              <div className="flex h-full items-center justify-center p-6">
                <EmptyState
                  icon={MessagesSquare}
                  title="Select a conversation"
                  description="Choose a message on the left to read it and reply, or start a new conversation."
                  action={<Button variant="secondary" icon={PenSquare} onClick={() => setCompose({ open: true, to: [] })}>New message</Button>}
                />
              </div>
            )}
          </section>
        </div>
      </Card>

      <ComposeModal
        open={compose.open}
        initialTo={compose.to}
        onClose={() => setCompose({ open: false, to: [] })}
        onSent={(id) => {
          setCompose({ open: false, to: [] });
          void refreshAll();
          update({ folder: 'sent', thread: String(id), unread: null });
        }}
      />
    </>
  );
}
