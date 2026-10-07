import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { Activity, AlertTriangle, CalendarDays, Download, FileSpreadsheet, FileText, FilterX, History, List, Printer, RefreshCw, Rows3, Trash2, Users } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import {
  Alert, Avatar, Button, Card, CardHeader, Combobox, DataTable, Dropdown, EmptyState, IconButton, Input, PageHeader, Pagination, Reveal, SearchInput, Select, Skeleton,
  Stagger, StatCard, StatusBadge, Tabs, type Column, type SortState,
} from '@/components/ui';
import { BarChart, chartColor } from '@/components/charts';
import { apiUrl, downloadFile, type ApiError, type Query } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { useApi, useCrudList } from '@/lib/queries';
import { formatDate, formatDateTime, formatNumber, isoDate, timeAgo } from '@/lib/format';
import type { Option } from '@/lib/types';
import { ActionBadge, ActionIcon } from './components';
import { ActivityDetailModal } from './activity/ActivityDetailModal';
import { PurgeLogsModal } from './activity/PurgeLogsModal';
import type { ActivityItem } from './types';

interface Stats {
  today: number;
  yesterday: number;
  week: number;
  failed_week: number;
  users_today: number;
  total: number;
  oldest: string | null;
  series: { labels: string[]; series: Record<string, number[]> };
  modules: { module: string; label: string; count: number }[];
  top_users: { id: number; name: string; avatar: string | null; designation: string | null; count: number }[];
}

const FILTER_KEYS = ['user', 'module', 'action', 'status', 'from', 'to'] as const;
type FilterKey = (typeof FILTER_KEYS)[number];

function dayLabel(d: string) {
  const day = d.slice(0, 10);
  const today = isoDate();
  const y = new Date();
  y.setDate(y.getDate() - 1);
  if (day === today) return 'Today';
  if (day === isoDate(y)) return 'Yesterday';
  return new Date(`${day}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'long', day: '2-digit', month: 'short', year: 'numeric' });
}

export default function ActivityLogsPage() {
  const { can } = useAuth();
  const qc = useQueryClient();
  const [params, setParams] = useSearchParams();
  // Phones default to the card-like timeline; the choice is remembered per browser.
  const [view, setView] = useLocalStorage<'table' | 'timeline'>('gimt.activity.view', typeof window !== 'undefined' && window.matchMedia('(max-width: 767px)').matches ? 'timeline' : 'table');
  const [q, setQ] = useState(params.get('q') ?? '');
  const [filters, setFilters] = useState<Record<FilterKey, string>>(() => Object.fromEntries(FILTER_KEYS.map((k) => [k, params.get(k) ?? ''])) as Record<FilterKey, string>);
  const [page, setPage] = useState(Number(params.get('page') ?? 1));
  const [perPage, setPerPage] = useLocalStorage('gimt.activity.perPage', 25);
  const [sort, setSort] = useState<SortState>({ key: 'created_at', dir: 'desc' });
  const [detailId, setDetailId] = useState<number | null>(null);
  const [purgeOpen, setPurgeOpen] = useState(false);
  const dq = useDebounce(q, 350);

  useEffect(() => setPage(1), [dq, filters, perPage]);
  useEffect(() => {
    const next = new URLSearchParams();
    if (dq) next.set('q', dq);
    FILTER_KEYS.forEach((k) => filters[k] && next.set(k, filters[k]));
    if (page > 1) next.set('page', String(page));
    if (next.toString() !== params.toString()) setParams(next, { replace: true });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [dq, filters, page]);

  const f: Record<string, unknown> = {};
  if (filters.user) f.user_id = filters.user;
  if (filters.module) f.module = filters.module;
  if (filters.action) f.action = filters.action;
  if (filters.status) f.status = filters.status;
  if (filters.from || filters.to) f.created_at = { from: filters.from || undefined, to: filters.to || undefined };
  const query: Query = { page, per_page: perPage, q: dq || undefined, sort: sort.key, dir: sort.dir, f };
  const { data, isLoading, isFetching, error, refetch } = useCrudList<ActivityItem>('activity_logs', query);
  const { data: stats, isLoading: statsLoading } = useApi<Stats>(['activity-stats'], 'activity-logs/stats');
  const { data: facets } = useApi<{ modules: Option[]; actions: Option[] }>(['activity-facets'], 'activity-logs/facets', undefined, { staleTime: 60_000 });

  const active = Object.values(filters).filter(Boolean).length + (dq ? 1 : 0);
  const setFilter = (k: FilterKey, v: string) => setFilters((s) => ({ ...s, [k]: v }));
  const clearAll = () => {
    setQ('');
    setFilters(Object.fromEntries(FILTER_KEYS.map((k) => [k, ''])) as Record<FilterKey, string>);
  };
  const quickRange = (days: number) => {
    const from = new Date();
    from.setDate(from.getDate() - (days - 1));
    setFilters((s) => ({ ...s, from: isoDate(from), to: isoDate() }));
  };
  const exportAs = (format: 'csv' | 'xlsx' | 'print') => {
    const qy: Query = { format, q: dq || undefined, sort: sort.key, dir: sort.dir, f };
    if (format === 'print') window.open(apiUrl('crud/activity_logs/export', qy), '_blank', 'noopener');
    else downloadFile('crud/activity_logs/export', qy);
  };
  const refreshAll = () => {
    void refetch();
    void qc.invalidateQueries({ queryKey: ['activity-stats'] });
  };

  const rows = data?.rows ?? [];
  const grouped = useMemo(() => {
    const out: { day: string; items: ActivityItem[] }[] = [];
    rows.forEach((r) => {
      const day = r.created_at.slice(0, 10);
      const last = out[out.length - 1];
      if (last && last.day === day) last.items.push(r);
      else out.push({ day, items: [r] });
    });
    return out;
  }, [rows]);

  const trend = stats ? (stats.yesterday ? Math.round(((stats.today - stats.yesterday) / stats.yesterday) * 100) : null) : null;
  const columns: Column<ActivityItem>[] = [
    {
      key: 'created_at', header: 'Time', sortable: true, width: '150px',
      render: (r) => (
        <div className="leading-tight" title={formatDateTime(r.created_at)}>
          <div className="whitespace-nowrap text-sm text-slate-800 dark:text-slate-100">{formatDateTime(r.created_at).split(', ')[1]}</div>
          <div className="whitespace-nowrap text-xs text-slate-500">{formatDate(r.created_at)}</div>
        </div>
      ),
    },
    {
      key: 'user_name', header: 'User', sortable: true,
      render: (r) => (r.user_name ? (
        <div className="flex min-w-0 items-center gap-2.5">
          <Avatar name={r.user_name} src={r.user_avatar} size="sm" />
          <span className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{r.user_name}</span>
        </div>
      ) : <span className="text-xs text-slate-400">System</span>),
    },
    { key: 'action', header: 'Action', sortable: true, render: (r) => <ActionBadge action={r.action} status={r.status} /> },
    { key: 'module_label', header: 'Module', sortable: true, render: (r) => <span className="whitespace-nowrap text-sm">{r.module_label}</span> },
    {
      key: 'description', header: 'Description',
      render: (r) => (
        <div className="max-w-md">
          <p className="line-clamp-2 text-sm text-slate-700 dark:text-slate-200">{r.description || '—'}</p>
          {r.record_id && <span className="text-[11px] text-slate-400">Record #{r.record_id}</span>}
        </div>
      ),
    },
    {
      key: 'ip_address', header: 'IP / browser', sortable: true,
      render: (r) => (
        <div className="leading-tight">
          <div className="whitespace-nowrap font-mono text-xs text-slate-700 dark:text-slate-300">{r.ip_address ?? '—'}</div>
          <div className="whitespace-nowrap text-[11px] text-slate-400">{r.browser}</div>
        </div>
      ),
    },
    { key: 'status', header: 'Status', sortable: true, render: (r) => <StatusBadge status={r.status} /> },
  ];

  const emptyState = (
    <EmptyState
      icon={active ? FilterX : History}
      title={active ? 'No matching activity' : 'No activity recorded yet'}
      description={active ? 'Try widening the date range or clearing the filters.' : 'Every create, update, delete, export and sign-in will appear here.'}
      action={active ? <Button variant="secondary" icon={FilterX} onClick={clearAll}>Clear filters</Button> : undefined}
    />
  );

  return (
    <>
      <PageHeader
        title="Activity Logs"
        description="A complete audit trail of who did what, where and when across every module."
        breadcrumbs={[{ label: 'System' }, { label: 'Activity Logs' }]}
        actions={
          <>
            {can('activity_logs', 'export') && (
              <Dropdown
                label="Export"
                triggerClassName="btn btn-secondary"
                trigger={<><Download className="h-4 w-4" />Export</>}
                items={[
                  { label: 'Export as CSV', icon: FileText, onClick: () => exportAs('csv') },
                  { label: 'Export as Excel', icon: FileSpreadsheet, onClick: () => exportAs('xlsx') },
                  { label: 'Print / Save as PDF', icon: Printer, onClick: () => exportAs('print') },
                ]}
              />
            )}
            {can('activity_logs', 'delete') && (
              <Button variant="secondary" icon={Trash2} className="!text-red-600 hover:!border-red-200 hover:!bg-red-50 dark:!text-red-400 dark:hover:!bg-red-500/10" onClick={() => setPurgeOpen(true)}>
                Purge old logs
              </Button>
            )}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        {[
          <StatCard key="t" label="Events today" value={stats?.today ?? 0} icon={Activity} tone="navy" loading={statsLoading}
            trend={trend !== null ? { value: `${Math.abs(trend)}%`, dir: trend > 0 ? 'up' : trend < 0 ? 'down' : 'flat', label: 'vs yesterday', positive: true } : undefined} hint="No events yesterday" />,
          <StatCard key="w" label="Last 7 days" value={stats?.week ?? 0} icon={CalendarDays} tone="blue" loading={statsLoading} hint={stats ? `${formatNumber(stats.total)} entries in total` : undefined} />,
          <StatCard key="f" label="Failed / denied (7 days)" value={stats?.failed_week ?? 0} icon={AlertTriangle} tone={stats?.failed_week ? 'red' : 'green'} loading={statsLoading} hint="Permission denials and errors" />,
          <StatCard key="u" label="Active users today" value={stats?.users_today ?? 0} icon={Users} tone="green" loading={statsLoading} hint={stats?.oldest ? `Logging since ${formatDate(stats.oldest)}` : undefined} />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Activity over the last 14 days" subtitle="Successful vs failed / denied actions per day" />
            <div className="p-5">
              {statsLoading || !stats ? (
                <Skeleton className="h-[300px] w-full" />
              ) : (
                <BarChart labels={stats.series.labels} stacked height={300} series={Object.entries(stats.series.series).map(([label, d], i) => ({ label, data: d, color: i === 0 ? chartColor(0) : '#EF4444' }))} />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Busiest modules" subtitle="Last 30 days" />
            <div className="space-y-3 p-5">
              {statsLoading && Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-7 w-full" />)}
              {stats?.modules.map((m, i) => {
                const max = stats.modules[0]?.count || 1;
                return (
                  <button key={m.module} type="button" onClick={() => setFilter('module', m.module)} className="group block w-full text-left" title={`Filter by ${m.label}`}>
                    <div className="mb-1 flex items-center justify-between text-xs">
                      <span className="font-medium text-slate-700 group-hover:text-brand-700 dark:text-slate-200 dark:group-hover:text-brand-300">{m.label}</span>
                      <span className="tabular-nums text-slate-500">{formatNumber(m.count)}</span>
                    </div>
                    <div className="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                      <div className="h-full origin-left rounded-full transition-transform duration-700 ease-out" style={{ width: `${(m.count / max) * 100}%`, background: chartColor(i) }} />
                    </div>
                  </button>
                );
              })}
              {stats && stats.top_users.length > 0 && (
                <div className="border-t border-slate-100 pt-3 dark:border-slate-800">
                  <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500">Most active users</p>
                  <div className="flex flex-wrap gap-1.5">
                    {stats.top_users.map((u) => (
                      <button key={u.id} type="button" onClick={() => setFilter('user', String(u.id))} className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 py-0.5 pl-0.5 pr-2.5 text-xs transition hover:border-brand-300 hover:bg-brand-50 dark:border-slate-700 dark:hover:bg-brand-500/10" title={`${u.count} actions`}>
                        <Avatar name={u.name} src={u.avatar} size="xs" />
                        {u.name.split(' ').slice(0, 2).join(' ')}
                        <span className="tabular-nums text-slate-400">{u.count}</span>
                      </button>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <Card className="overflow-hidden">
        <div className="space-y-3 border-b border-slate-100 p-4 dark:border-slate-800">
          <div className="flex flex-col gap-2 lg:flex-row lg:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search description, user, IP, record…" className="w-full lg:max-w-sm" />
            <div className="flex items-center gap-2 lg:ml-auto">
              <span className="hidden text-xs text-slate-500 sm:inline">{data ? `${formatNumber(data.total)} entries` : ''}</span>
              <Tabs
                variant="pills"
                value={view}
                onChange={(v) => setView(v as 'table' | 'timeline')}
                tabs={[
                  { key: 'table', label: 'Table', icon: List },
                  { key: 'timeline', label: 'Timeline', icon: Rows3 },
                ]}
              />
              <IconButton icon={RefreshCw} label="Refresh" onClick={refreshAll} className={clsx(isFetching && '[&_svg]:animate-spin')} />
            </div>
          </div>
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:flex lg:flex-wrap lg:items-center">
            <div className="lg:w-56">
              <Combobox size="sm" value={filters.user || null} onChange={(v) => setFilter('user', v ? String(v) : '')} optionsUrl="crud/activity_logs/options/filter:user_id" placeholder="User: All" />
            </div>
            <Select inputSize="sm" value={filters.module} onChange={(e) => setFilter('module', e.target.value)} options={facets?.modules ?? []} placeholder="Module: All" aria-label="Module" className="lg:!w-48" />
            <Select inputSize="sm" value={filters.action} onChange={(e) => setFilter('action', e.target.value)} options={facets?.actions ?? []} placeholder="Action: All" aria-label="Action" className="lg:!w-40" />
            <Select inputSize="sm" value={filters.status} onChange={(e) => setFilter('status', e.target.value)} options={{ success: 'Success', failed: 'Failed / denied' }} placeholder="Status: All" aria-label="Status" className="lg:!w-40" />
            <div className="flex items-center gap-1.5 sm:col-span-2">
              <Input inputSize="sm" type="date" value={filters.from} max={filters.to || undefined} onChange={(e) => setFilter('from', e.target.value)} aria-label="From date" className="lg:!w-36" />
              <span className="text-xs text-slate-400">to</span>
              <Input inputSize="sm" type="date" value={filters.to} min={filters.from || undefined} onChange={(e) => setFilter('to', e.target.value)} aria-label="To date" className="lg:!w-36" />
            </div>
            <div className="flex flex-wrap items-center gap-1 sm:col-span-2">
              {[['Today', 1], ['7 days', 7], ['30 days', 30]].map(([l, n]) => (
                <button key={l} type="button" onClick={() => quickRange(n as number)} className="rounded-lg px-2 py-1 text-xs font-medium text-slate-500 transition hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-white">
                  {l}
                </button>
              ))}
              {active > 0 && (
                <button type="button" onClick={clearAll} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-red-600 transition hover:bg-red-50 dark:hover:bg-red-500/10">
                  <FilterX className="h-3.5 w-3.5" /> Clear ({active})
                </button>
              )}
            </div>
          </div>
        </div>

        {error ? (
          <div className="p-6">
            <Alert variant="error" title="Unable to load activity">{(error as ApiError).message}</Alert>
          </div>
        ) : view === 'table' ? (
          <DataTable<ActivityItem> columns={columns} rows={rows} loading={isLoading || isFetching} sort={sort} onSort={setSort} onRowClick={(r) => setDetailId(r.id)} empty={emptyState} caption="Activity logs" />
        ) : isLoading ? (
          <div className="space-y-4 p-5">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-12 w-full" />)}</div>
        ) : rows.length === 0 ? (
          emptyState
        ) : (
          <div className={clsx('p-5 transition-opacity', isFetching && 'opacity-60')}>
            {grouped.map((g) => (
              <section key={g.day} className="mb-6 last:mb-0">
                <h3 className="sticky top-16 z-[1] mb-3 inline-flex items-center gap-2 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                  <CalendarDays className="h-3.5 w-3.5" /> {dayLabel(g.day)}
                  <span className="text-slate-400">· {g.items.length}</span>
                </h3>
                <ol className="relative space-y-1 before:absolute before:bottom-3 before:left-[15px] before:top-3 before:w-px before:bg-slate-200 dark:before:bg-slate-800">
                  {g.items.map((r) => (
                    <li key={r.id}>
                      <button type="button" onClick={() => setDetailId(r.id)} className="group relative flex w-full items-start gap-3 rounded-xl py-2 pr-2 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <span className="relative z-[1] rounded-full ring-4 ring-white dark:ring-slate-900">
                          <ActionIcon action={r.action} status={r.status} />
                        </span>
                        <span className="min-w-0 flex-1 pt-0.5">
                          <span className="block text-sm text-slate-800 group-hover:text-brand-800 dark:text-slate-100 dark:group-hover:text-white">
                            {r.user_name && <strong className="font-semibold">{r.user_name}</strong>} <span className="text-slate-600 dark:text-slate-300">{r.description}</span>
                          </span>
                          <span className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500">
                            <span>{r.module_label}</span>
                            {r.ip_address && <span>· {r.ip_address}</span>}
                            <span>· {r.browser}</span>
                            {r.status !== 'success' && <StatusBadge status={r.status} />}
                          </span>
                        </span>
                        <span className="shrink-0 pt-1 text-right text-xs text-slate-400" title={formatDateTime(r.created_at)}>
                          {formatDateTime(r.created_at).split(', ')[1]}
                          <span className="hidden sm:block">{timeAgo(r.created_at)}</span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ol>
              </section>
            ))}
          </div>
        )}

        {data && data.total > 0 && (
          <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={setPerPage} />
        )}
      </Card>

      <ActivityDetailModal
        id={detailId}
        onClose={() => setDetailId(null)}
        onRelated={(module, record) => {
          setDetailId(null);
          setQ(record);
          setFilters((s) => ({ ...s, module }));
        }}
      />
      <PurgeLogsModal
        open={purgeOpen}
        onClose={() => setPurgeOpen(false)}
        onDone={() => {
          void qc.invalidateQueries({ queryKey: ['crud', 'activity_logs'] });
          void qc.invalidateQueries({ queryKey: ['activity-stats'] });
          void qc.invalidateQueries({ queryKey: ['activity-facets'] });
        }}
      />
    </>
  );
}
