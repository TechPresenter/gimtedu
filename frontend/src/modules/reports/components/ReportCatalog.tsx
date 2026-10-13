import { useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRight, ChartPie, Download, History, LayoutGrid, SearchX } from 'lucide-react';
import { Alert, Button, Card, EmptyState, PageHeader, Reveal, SearchInput, Skeleton, Stagger, StatCard } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { timeAgo } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { areaTone, loadRecent, reportIcon, type Catalog, type CatalogReport } from '../lib';

/** The catalogue is small and permission-filtered on the server; cached for the session. */
export function useCatalog() {
  return useApi<Catalog>(['reports-catalog'], 'reports', undefined, { staleTime: 5 * 60_000 });
}

export function ReportCatalog() {
  const { session } = useAuth();
  const userId = session?.user?.id;
  const [params, setParams] = useSearchParams();
  const [q, setQ] = useState('');
  const { data, isLoading, error, refetch } = useCatalog();
  // An unknown area, or one this role cannot open (shared link), falls back to every area.
  const rawArea = params.get('area') ?? '';
  const area = data && !data.areas.some((a) => a.key === rawArea) ? '' : rawArea;

  const setArea = (key: string) => {
    const next = new URLSearchParams(params);
    if (key) next.set('area', key);
    else next.delete('area');
    setParams(next, { replace: true });
  };

  const areaLabel = useMemo(() => Object.fromEntries((data?.areas ?? []).map((a) => [a.key, a.label])), [data]);
  const term = q.trim().toLowerCase();
  const filtered = useMemo(
    () => (data?.reports ?? []).filter((r) => (!area || r.area === area) && (!term || `${r.title} ${r.description} ${areaLabel[r.area] ?? ''}`.toLowerCase().includes(term))),
    [data, area, term, areaLabel],
  );
  const groups = (data?.areas ?? []).map((a) => ({ area: a, reports: filtered.filter((r) => r.area === a.key) })).filter((g) => g.reports.length > 0);
  const recent = useMemo(() => {
    const byKey = new Map((data?.reports ?? []).map((r) => [r.key, r]));
    return loadRecent(userId).map((k) => byKey.get(k)).filter((r): r is CatalogReport => !!r).slice(0, 4);
  }, [data, userId]);

  return (
    <>
      <PageHeader
        title="Reports & Analytics"
        breadcrumbs={[{ label: 'Reports & Analytics' }]}
        description="Every institutional report in one place — filter by session, department, program, semester and status, see the KPIs and chart, then export to CSV, Excel or PDF, or print."
      />

      {error ? (
        <Alert variant="error" title="Unable to load the report catalogue" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <>
          <Stagger className="mb-6 grid grid-cols-2 gap-4 lg:grid-cols-4" itemClassName="min-w-0">
            {[
              <StatCard key="n" label="Reports available to you" value={data?.reports.length ?? 0} icon={ChartPie} tone="navy" loading={isLoading} hint={data ? `of ${data.total_reports} in the catalogue` : undefined} />,
              <StatCard key="a" label="Areas covered" value={data?.areas.length ?? 0} icon={LayoutGrid} tone="green" loading={isLoading} hint={data?.session ? `Current session ${data.session}` : undefined} />,
              <StatCard key="e" label="Exports in the last 30 days" value={data?.activity.exports_30d ?? 0} icon={Download} tone="blue" loading={isLoading} hint="CSV, Excel, PDF and prints" />,
              <StatCard
                key="l"
                label="Your last export"
                value={data?.activity.my_last_export ? timeAgo(data.activity.my_last_export.at) : 'None yet'}
                icon={History}
                tone="purple"
                loading={isLoading}
                hint={data?.activity.my_last_export?.description ?? (data?.can_export ? 'Exports are logged in the activity log' : 'Your role can view but not export')}
              />,
            ]}
          </Stagger>

          <Card className="mb-6 p-4">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
              <SearchInput size="sm" value={q} onChange={setQ} placeholder="Find a report…" className="w-full lg:w-72" />
              <div role="group" aria-label="Filter by area" className="-mx-1 flex gap-1.5 overflow-x-auto px-1 pb-1 lg:flex-wrap lg:overflow-visible lg:pb-0">
                <AreaChip active={!area} onClick={() => setArea('')} label="All areas" count={data?.reports.length} />
                {isLoading
                  ? Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-8 w-24 shrink-0 rounded-full" />)
                  : data?.areas.map((a) => <AreaChip key={a.key} active={area === a.key} onClick={() => setArea(area === a.key ? '' : a.key)} label={a.label} count={a.count} />)}
              </div>
            </div>
            {recent.length > 0 && !area && !term && (
              <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
                <span className="inline-flex items-center gap-1 font-medium text-slate-500 dark:text-slate-400">
                  <History className="h-3.5 w-3.5" aria-hidden /> Recently opened
                </span>
                {recent.map((r) => {
                  const Icon = reportIcon(r.icon);
                  return (
                    <Link key={r.key} to={`/reports?r=${r.key}`} className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 px-2.5 py-1 font-medium text-slate-700 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-800 dark:border-slate-700 dark:text-slate-200 dark:hover:border-brand-500/50 dark:hover:bg-brand-500/10 dark:hover:text-white">
                      <Icon className="h-3.5 w-3.5" aria-hidden />
                      {r.title}
                    </Link>
                  );
                })}
              </div>
            )}
          </Card>

          {isLoading ? (
            <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
              {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="card p-4">
                  <div className="flex gap-3">
                    <Skeleton className="h-10 w-10 rounded-xl" />
                    <div className="flex-1">
                      <Skeleton className="h-4 w-1/2" />
                      <Skeleton className="mt-2 h-3 w-full" />
                      <Skeleton className="mt-1.5 h-3 w-2/3" />
                    </div>
                  </div>
                </div>
              ))}
            </div>
          ) : !data?.reports.length ? (
            <Card>
              <EmptyState
                icon={ChartPie}
                title="No reports available for your role"
                description="Reports open only for the modules your role can view. Ask an administrator to grant view access to the modules you report on."
                action={<Button size="sm" variant="secondary" to="/">Back to dashboard</Button>}
              />
            </Card>
          ) : groups.length === 0 ? (
            <Card>
              <EmptyState
                icon={SearchX}
                title="No report matches your search"
                description={`Nothing found for “${q.trim()}”${area ? ` in ${areaLabel[area] ?? area}` : ''}. Try a broader word such as “fee”, “attendance” or “placement”.`}
                action={<Button size="sm" variant="secondary" onClick={() => { setQ(''); setArea(''); }}>Show all reports</Button>}
              />
            </Card>
          ) : (
            groups.map((g, gi) => {
              const AreaIcon = reportIcon(g.area.icon);
              return (
                <section key={g.area.key} aria-labelledby={`area-${g.area.key}`} className="mb-8">
                  <Reveal delay={Math.min(gi, 3) * 40} className="mb-3 flex items-center gap-3">
                    <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', areaTone(g.area.key))}>
                      <AreaIcon className="h-[18px] w-[18px]" aria-hidden />
                    </span>
                    <div className="min-w-0">
                      <h2 id={`area-${g.area.key}`} className="font-display text-base font-bold text-slate-900 dark:text-white">
                        {g.area.label}
                      </h2>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">{g.area.description}</p>
                    </div>
                    <span className="badge badge-slate ml-auto shrink-0">{g.reports.length} {g.reports.length === 1 ? 'report' : 'reports'}</span>
                  </Reveal>
                  <Stagger className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" itemClassName="min-w-0" step={50}>
                    {g.reports.map((r) => (
                      <ReportCard key={r.key} report={r} />
                    ))}
                  </Stagger>
                </section>
              );
            })
          )}
        </>
      )}
    </>
  );
}

function AreaChip({ active, onClick, label, count }: { active: boolean; onClick: () => void; label: string; count?: number }) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={clsx(
        'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400',
        active
          ? 'border-brand-800 bg-brand-800 text-white dark:border-brand-500 dark:bg-brand-600'
          : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:text-white',
      )}
    >
      {label}
      {count !== undefined && <span className={clsx('rounded-full px-1.5 text-[10px]', active ? 'bg-white/20' : 'bg-slate-100 dark:bg-slate-800')}>{count}</span>}
    </button>
  );
}

function ReportCard({ report }: { report: CatalogReport }) {
  const Icon = reportIcon(report.icon);
  return (
    <Link
      to={`/reports?r=${report.key}`}
      className="card group flex h-full flex-col p-4 transition duration-200 hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 motion-reduce:transform-none dark:hover:border-brand-500/40"
    >
      <div className="flex items-start gap-3">
        <span className={clsx('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', areaTone(report.area))}>
          <Icon className="h-5 w-5" aria-hidden />
        </span>
        <div className="min-w-0">
          <h3 className="font-display text-[15px] font-semibold leading-snug text-slate-900 dark:text-white">{report.title}</h3>
          <p className="mt-1 line-clamp-2 text-[13px] leading-relaxed text-slate-500 dark:text-slate-400">{report.description}</p>
        </div>
      </div>
      <span className="mt-auto inline-flex items-center gap-1 pt-3 text-xs font-semibold text-brand-700 dark:text-brand-300">
        Open report
        <ArrowRight className="h-3.5 w-3.5 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden />
      </span>
    </Link>
  );
}
