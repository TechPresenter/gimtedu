import { useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useInfiniteQuery, useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  Award, BadgeCheck, BedDouble, Bell, BellOff, BellRing, BookOpen, BriefcaseBusiness, Bus, CalendarCheck, CalendarPlus, CheckCheck, ClipboardList, DatabaseBackup, ExternalLink, IndianRupee,
  Inbox, Library, Mail, MailOpen, MessageCircle, MessageSquareWarning, Newspaper, ShieldAlert, Trash2, UserPlus, Users, type LucideIcon,
} from 'lucide-react';
import { Alert, Button, Card, EmptyState, IconButton, PageHeader, Skeleton, Spinner, Tabs, toneClasses, useConfirm, useToast, type Tone } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { notificationRoute, type NotificationRow } from '@/components/layout/NotificationMenu';

interface NotificationPage {
  rows: (NotificationRow & { time_ago?: string })[];
  total: number;
  page: number;
  pages: number;
  unread: number;
}
interface TypeCount {
  type: string;
  label: string;
  total: number;
  unread: number;
}

const TYPE_VISUAL: Record<string, { icon: LucideIcon; tone: Tone }> = {
  admission: { icon: UserPlus, tone: 'blue' }, application: { icon: UserPlus, tone: 'blue' }, fee: { icon: IndianRupee, tone: 'green' }, payment: { icon: IndianRupee, tone: 'green' },
  attendance: { icon: CalendarCheck, tone: 'amber' }, exam: { icon: ClipboardList, tone: 'purple' }, result: { icon: Award, tone: 'purple' }, enquiry: { icon: MessageCircle, tone: 'cyan' },
  contact: { icon: Mail, tone: 'cyan' }, certificate: { icon: BadgeCheck, tone: 'orange' }, security: { icon: ShieldAlert, tone: 'red' }, system: { icon: Bell, tone: 'slate' },
  newsletter: { icon: Newspaper, tone: 'cyan' }, event: { icon: CalendarPlus, tone: 'pink' }, hostel: { icon: BedDouble, tone: 'amber' }, academic: { icon: BookOpen, tone: 'navy' },
  library: { icon: Library, tone: 'amber' }, transport: { icon: Bus, tone: 'orange' }, placement: { icon: BriefcaseBusiness, tone: 'cyan' }, leave: { icon: Users, tone: 'purple' },
  feedback: { icon: MessageSquareWarning, tone: 'red' }, backup: { icon: DatabaseBackup, tone: 'slate' },
};
const visual = (type: string) => TYPE_VISUAL[type] ?? { icon: BellRing, tone: 'navy' as Tone };

const PER_PAGE = 20;

function dayGroup(iso: string): string {
  const d = new Date(iso.replace(' ', 'T'));
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const diff = Math.floor((today.getTime() - new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()) / 86400000);
  if (diff <= 0) return 'Today';
  if (diff === 1) return 'Yesterday';
  if (diff < 7) return 'Earlier this week';
  return d.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}

export default function NotificationsPage() {
  const [params, setParams] = useSearchParams();
  const filter = params.get('filter') === 'unread' ? 'unread' : 'all';
  const type = params.get('type') ?? '';
  const navigate = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [busy, setBusy] = useState<string | null>(null);

  const setParam = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v);
    else next.delete(k);
    setParams(next, { replace: true });
    setSelected(new Set());
  };

  const types = useQuery({ queryKey: ['notification-types'], queryFn: () => api.get<TypeCount[]>('dashboard/notification-types') });
  const list = useInfiniteQuery({
    queryKey: ['notifications', 'center', filter, type],
    queryFn: ({ pageParam, signal }) => api.get<NotificationPage>('notifications', { filter, type: type || undefined, page: pageParam, per_page: PER_PAGE }, { signal }),
    initialPageParam: 1,
    getNextPageParam: (last) => (last.page < last.pages ? last.page + 1 : undefined),
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.rows) ?? [], [list.data]);
  const first = list.data?.pages[0];
  const unread = first?.unread ?? 0;
  // Per-category counts (the list endpoint's `unread` is across every category).
  const typeRows = types.data ?? [];
  const activeType = type ? typeRows.find((t) => t.type === type) : undefined;
  const tabUnread = type ? activeType?.unread ?? 0 : unread;
  const readTotal = typeRows.reduce((a, t) => a + (t.total - t.unread), 0);
  const groups = useMemo(() => {
    const out: { label: string; items: typeof rows }[] = [];
    rows.forEach((r) => {
      const g = dayGroup(r.created_at);
      if (out[out.length - 1]?.label !== g) out.push({ label: g, items: [] });
      out[out.length - 1].items.push(r);
    });
    return out;
  }, [rows]);

  const refresh = () => Promise.all([
    qc.invalidateQueries({ queryKey: ['notifications'] }),
    qc.invalidateQueries({ queryKey: ['notification-counts'] }),
    qc.invalidateQueries({ queryKey: ['notification-types'] }),
  ]);

  const run = async (key: string, fn: () => Promise<unknown>, success?: string) => {
    setBusy(key);
    try {
      await fn();
      if (success) toast.success(success);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const markAll = () => run('all', () => api.post('notifications/read-all'), 'All notifications marked as read.');
  const clearRead = async () => {
    if (!(await confirm({ title: 'Clear read notifications?', message: 'All notifications you have already read will be permanently removed.', confirmText: 'Clear read', danger: true }))) return;
    await run('clear', () => api.del('notifications'), 'Read notifications cleared.');
  };
  const toggleRead = (n: NotificationRow) => run(`r${n.id}`, () => api.post(`notifications/${n.id}/${n.is_read ? 'unread' : 'read'}`));
  const remove = (n: NotificationRow) => run(`d${n.id}`, () => api.del(`notifications/${n.id}`), 'Notification deleted.');
  const bulk = async (action: 'read' | 'unread' | 'delete') => {
    const ids = [...selected];
    if (!ids.length) return;
    if (action === 'delete' && !(await confirm({ title: `Delete ${ids.length} notification${ids.length === 1 ? '' : 's'}?`, message: 'Selected notifications will be permanently removed.', confirmText: 'Delete', danger: true }))) return;
    await run('bulk', async () => {
      await Promise.all(ids.map((id) => (action === 'delete' ? api.del(`notifications/${id}`) : api.post(`notifications/${id}/${action}`))));
      setSelected(new Set());
    }, `${ids.length} notification${ids.length === 1 ? '' : 's'} ${action === 'delete' ? 'deleted' : `marked as ${action}`}.`);
  };
  const open = (n: NotificationRow) => {
    if (!n.is_read) void api.post(`notifications/${n.id}/read`).then(refresh);
    const route = notificationRoute(n.url);
    if (!route) return;
    if (route.startsWith('http')) window.open(route, '_blank', 'noopener');
    else navigate(route);
  };
  const toggleSel = (id: number) => setSelected((s) => {
    const next = new Set(s);
    if (next.has(id)) next.delete(id);
    else next.add(id);
    return next;
  });
  const allSelected = rows.length > 0 && rows.every((r) => selected.has(r.id));

  return (
    <>
      <PageHeader
        title="Notifications"
        description={unread ? `You have ${formatNumber(unread)} unread notification${unread === 1 ? '' : 's'}.` : 'You are all caught up.'}
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'Notifications' }]}
        actions={
          <>
            <Button variant="secondary" icon={Trash2} onClick={clearRead} loading={busy === 'clear'} disabled={types.isSuccess ? readTotal === 0 : !rows.some((r) => r.is_read)}>
              Clear read
            </Button>
            <Button icon={CheckCheck} onClick={markAll} loading={busy === 'all'} disabled={!unread}>
              Mark all as read
            </Button>
          </>
        }
      />

      <div className="grid items-start gap-6 lg:grid-cols-12">
        <aside className="min-w-0 lg:sticky lg:top-20 lg:col-span-3" aria-label="Notification filters">
          <Card className="p-3">
            <p className="px-2 pb-2 pt-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">Categories</p>
            <ul className="flex gap-1 overflow-x-auto scrollbar-none lg:flex-col">
              <li>
                <button type="button" onClick={() => setParam('type', '')} className={clsx('flex w-full items-center justify-between gap-3 whitespace-nowrap rounded-lg px-2.5 py-2 text-sm transition', !type ? 'bg-brand-50 font-semibold text-brand-800 dark:bg-brand-500/15 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800')} aria-pressed={!type}>
                  <span className="flex items-center gap-2"><Inbox className="h-4 w-4" aria-hidden /> All categories</span>
                  <span className="text-xs tabular-nums text-slate-400">{formatNumber((types.data ?? []).reduce((a, t) => a + t.total, 0))}</span>
                </button>
              </li>
              {types.isLoading && Array.from({ length: 4 }).map((_, i) => <li key={i}><Skeleton className="m-1 h-8 w-40 lg:w-auto" /></li>)}
              {(types.data ?? []).map((t) => {
                const v = visual(t.type);
                const active = type === t.type;
                return (
                  <li key={t.type}>
                    <button type="button" onClick={() => setParam('type', active ? '' : t.type)} className={clsx('flex w-full items-center justify-between gap-3 whitespace-nowrap rounded-lg px-2.5 py-2 text-sm transition', active ? 'bg-brand-50 font-semibold text-brand-800 dark:bg-brand-500/15 dark:text-white' : 'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800')} aria-pressed={active}>
                      <span className="flex min-w-0 items-center gap-2">
                        <span className={clsx('inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-md', toneClasses[v.tone].icon)}><v.icon className="h-3.5 w-3.5" aria-hidden /></span>
                        <span className="lg:truncate" title={t.label}>{t.label}</span>
                      </span>
                      <span className="flex shrink-0 items-center gap-1.5 text-xs tabular-nums">
                        {t.unread > 0 && <span className="rounded-full bg-brand-700 px-1.5 py-px text-[10px] font-bold text-white">{t.unread}</span>}
                        <span className="text-slate-400">{t.total}</span>
                      </span>
                    </button>
                  </li>
                );
              })}
            </ul>
          </Card>
        </aside>

        <div className="min-w-0 lg:col-span-9">
          <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
              <div className="flex items-center gap-3">
                <input
                  type="checkbox"
                  className="form-checkbox"
                  checked={allSelected}
                  onChange={() => setSelected(allSelected ? new Set() : new Set(rows.map((r) => r.id)))}
                  aria-label="Select all loaded notifications"
                  disabled={!rows.length}
                />
                <Tabs variant="pills" value={filter} onChange={(v) => setParam('filter', v === 'all' ? '' : v)} tabs={[{ key: 'all', label: 'All', count: first?.total !== undefined && filter === 'all' ? first.total : activeType?.total }, { key: 'unread', label: 'Unread', count: tabUnread }]} />
              </div>
              {selected.size > 0 && (
                <div className="flex flex-wrap items-center gap-2 text-sm">
                  <span className="text-slate-500">{selected.size} selected</span>
                  <Button size="sm" variant="secondary" icon={MailOpen} onClick={() => bulk('read')} loading={busy === 'bulk'}>Mark read</Button>
                  <Button size="sm" variant="secondary" icon={Mail} onClick={() => bulk('unread')}>Mark unread</Button>
                  <Button size="sm" variant="danger" icon={Trash2} onClick={() => bulk('delete')}>Delete</Button>
                </div>
              )}
            </div>

            {list.error ? (
              <div className="p-5">
                <Alert variant="error" title="Unable to load notifications" action={<Button size="sm" variant="secondary" onClick={() => list.refetch()}>Retry</Button>}>
                  {(list.error as ApiError).message}
                </Alert>
              </div>
            ) : list.isLoading ? (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {Array.from({ length: 6 }).map((_, i) => (
                  <li key={i} className="flex gap-3 px-4 py-4">
                    <Skeleton className="h-9 w-9 rounded-full" />
                    <div className="flex-1 space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-3 w-1/2" /></div>
                  </li>
                ))}
              </ul>
            ) : rows.length === 0 ? (
              <EmptyState
                icon={filter === 'unread' ? CheckCheck : BellOff}
                title={filter === 'unread' ? 'No unread notifications' : 'No notifications here'}
                description={filter === 'unread' ? 'You have read everything. New admissions, payments, alerts and reminders will appear here.' : type ? 'Nothing in this category yet.' : 'Alerts about admissions, fees, attendance, exams, enquiries and security will appear here.'}
                action={filter === 'unread' || type ? <Button variant="secondary" onClick={() => setParams({}, { replace: true })}>Show all notifications</Button> : <Button variant="secondary" to="/">Back to dashboard</Button>}
              />
            ) : (
              <div>
                {groups.map((g) => (
                  <section key={g.label} aria-label={g.label}>
                    <h2 className="sticky top-16 z-[1] border-b border-slate-100 bg-slate-50/95 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 backdrop-blur dark:border-slate-800 dark:bg-slate-900/95 dark:text-slate-400">{g.label}</h2>
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                      {g.items.map((n) => {
                        const v = visual(n.type);
                        const route = notificationRoute(n.url);
                        return (
                          <li key={n.id} className={clsx('group flex items-start gap-3 px-4 py-3.5 transition', !n.is_read ? 'bg-brand-50/50 dark:bg-brand-500/[.06]' : 'hover:bg-slate-50 dark:hover:bg-slate-800/40')}>
                            <input type="checkbox" className="form-checkbox mt-2.5" checked={selected.has(n.id)} onChange={() => toggleSel(n.id)} aria-label={`Select notification: ${n.title}`} />
                            <span className={clsx('mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full', toneClasses[v.tone].icon)}>
                              <v.icon className="h-4 w-4" aria-hidden />
                            </span>
                            <button type="button" onClick={() => open(n)} className="min-w-0 flex-1 rounded-lg text-left focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40" aria-label={`${n.is_read ? '' : 'Unread: '}${n.title}${route ? ' — open' : ''}`}>
                              <span className="flex flex-wrap items-center gap-x-2 gap-y-0.5">
                                <span className={clsx('text-sm text-slate-900 dark:text-white', !n.is_read ? 'font-semibold' : 'font-medium')}>{n.title}</span>
                                {!n.is_read && <span className="h-2 w-2 rounded-full bg-brand-600" aria-hidden />}
                                {route && <ExternalLink className="hidden h-3.5 w-3.5 text-slate-300 opacity-0 transition group-hover:opacity-100 sm:inline" aria-hidden />}
                              </span>
                              {n.message && <span className="mt-0.5 block text-[13px] text-slate-600 dark:text-slate-300">{n.message}</span>}
                              <time dateTime={n.created_at} title={formatDateTime(n.created_at)} className="mt-1 block text-xs text-slate-400">{timeAgo(n.created_at)} · {formatDateTime(n.created_at)}</time>
                            </button>
                            <div className="flex shrink-0 items-center gap-0.5 sm:opacity-0 sm:transition sm:group-focus-within:opacity-100 sm:group-hover:opacity-100">
                              <IconButton size="sm" icon={n.is_read ? Mail : MailOpen} label={n.is_read ? 'Mark as unread' : 'Mark as read'} onClick={() => toggleRead(n)} tone="primary" disabled={busy === `r${n.id}`} />
                              <IconButton size="sm" icon={Trash2} label="Delete notification" tone="danger" onClick={() => remove(n)} disabled={busy === `d${n.id}`} />
                            </div>
                          </li>
                        );
                      })}
                    </ul>
                  </section>
                ))}
                <div className="flex justify-center border-t border-slate-100 px-4 py-3 dark:border-slate-800">
                  {list.hasNextPage ? (
                    <Button variant="secondary" size="sm" onClick={() => list.fetchNextPage()} loading={list.isFetchingNextPage}>
                      Load older notifications
                    </Button>
                  ) : (
                    <p className="flex items-center gap-2 text-xs text-slate-400">
                      {list.isFetching && <Spinner className="h-3.5 w-3.5" />}
                      Showing all {formatNumber(rows.length)} notification{rows.length === 1 ? '' : 's'}
                    </p>
                  )}
                </div>
              </div>
            )}
          </Card>
        </div>
      </div>
    </>
  );
}
