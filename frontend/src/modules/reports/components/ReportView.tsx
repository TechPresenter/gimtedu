import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { keepPreviousData } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowLeft, ArrowRight, Bookmark, ChartColumn, Download, FileDown, FileSpreadsheet, FileText, Info, Link2, Printer, RefreshCw, SearchX, Table2,
} from 'lucide-react';
import {
  Alert, Button, Card, CardHeader, CountUp, DataTable, Dropdown, EmptyState, PageHeader, Pagination, Reveal, Skeleton, Stagger, StatCard, useToast,
} from '@/components/ui';
import { downloadFile, type ApiError } from '@/lib/api';
import { printUrl } from '@/lib/config';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatMoney, formatMoneyShort, formatNumber, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { activeFilterCount, apiFilters, areaTone, filterQueryString, pushRecent, reportIcon, type Kpi, type ReportMeta, type ReportPayload } from '../lib';
import { useCatalog } from './ReportCatalog';
import { ReportFilters } from './ReportFilters';
import { ReportChart } from './ReportChart';
import { reportColumns } from './ReportCells';
import { PresetsModal } from './PresetsModal';

const PER_PAGE = [10, 25, 50, 100];

export function ReportView({ reportKey }: { reportKey: string }) {
  const [params, setParams] = useSearchParams();
  const { session, can } = useAuth();
  const userId = session?.user?.id;
  const toast = useToast();
  const catalog = useCatalog();
  // The key comes from the URL: only well-formed keys listed in this user's (permission-filtered) catalogue are fetched,
  // so a mistyped or foreign link shows the "unavailable" state without a failing request.
  const validKey = /^[a-z_]{1,64}$/.test(reportKey);
  const listed = catalog.data ? catalog.data.reports.some((r) => r.key === reportKey) : undefined;
  const unavailable = !validKey || listed === false;
  const metaQ = useApi<ReportMeta>(['report-meta', reportKey], `reports/${reportKey}/meta`, undefined, {
    staleTime: 5 * 60_000,
    retry: false,
    enabled: validKey && (listed === true || !!catalog.error),
  });
  const meta = metaQ.data;
  const [presetsOpen, setPresetsOpen] = useState(false);

  useEffect(() => {
    if (meta) pushRecent(userId, reportKey);
  }, [meta, userId, reportKey]);

  /* ---------- URL state ---------- */
  const update = (changes: Record<string, string | null>, resetPage = true) => {
    const next = new URLSearchParams(params);
    Object.entries(changes).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, v)));
    if (resetPage) next.delete('page');
    setParams(next, { replace: true });
  };

  // Search box: local state, debounced into the URL; URL changes made elsewhere (presets, reset) flow back in.
  const urlQ = params.get('q') ?? '';
  const [search, setSearch] = useState(urlQ);
  const pushed = useRef(urlQ);
  const debounced = useDebounce(search, 350);
  useEffect(() => {
    if (debounced !== pushed.current) {
      pushed.current = debounced;
      update({ q: debounced || null });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [debounced]);
  useEffect(() => {
    if (urlQ !== pushed.current) {
      pushed.current = urlQ;
      setSearch(urlQ);
    }
  }, [urlQ]);

  const filters = meta ? apiFilters(meta, params) : {};
  const page = Math.max(1, Number(params.get('page')) || 1);
  const perPage = PER_PAGE.includes(Number(params.get('per_page'))) ? Number(params.get('per_page')) : 25;
  const sort = params.get('sort') ?? undefined;
  const dir = params.get('dir') ?? undefined;
  const query = { ...filters, page, per_page: perPage, sort, dir };
  const dataQ = useApi<ReportPayload>(['report', reportKey, query], `reports/${reportKey}`, query, { enabled: !!meta, placeholderData: keepPreviousData });
  const data = dataQ.data;

  const columns = useMemo(() => reportColumns(meta?.columns ?? []), [meta]);
  const activeCount = meta ? activeFilterCount(meta, params) : 0;
  const canExport = !!meta?.can_export && can('reports', 'export');
  const related = (catalog.data?.reports ?? []).filter((r) => meta && r.area === meta.area && r.key !== reportKey);
  const presetCount = useMemo(() => {
    try {
      const all = JSON.parse(window.localStorage.getItem(`gimt:report-presets:${userId ?? 0}`) ?? '{}') as Record<string, unknown[]>;
      return Array.isArray(all[reportKey]) ? all[reportKey].length : 0;
    } catch {
      return 0;
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [userId, reportKey, presetsOpen]);

  const reset = () => {
    pushed.current = '';
    setSearch('');
    setParams(new URLSearchParams({ r: reportKey }), { replace: true });
  };

  const copyLink = async () => {
    try {
      await navigator.clipboard.writeText(window.location.href);
      toast.success('Link copied — anyone with access to this report sees the same filters.');
    } catch {
      toast.error('Could not copy automatically. Copy the address from the browser bar instead.');
    }
  };

  const exportAs = (format: 'csv' | 'xlsx' | 'pdf' | 'print') => {
    const q: Record<string, string> = { ...filters };
    if (data?.sort) {
      q.sort = data.sort.key;
      q.dir = data.sort.dir;
    }
    if (format === 'csv' || format === 'xlsx') {
      downloadFile(`reports/${reportKey}/export`, { ...q, format });
      toast.success(`Preparing the ${format === 'csv' ? 'CSV' : 'Excel'} file — the download starts in a moment.`);
      return;
    }
    window.open(printUrl('report.php', { report: reportKey, ...q, autoprint: 1, ...(format === 'pdf' ? { output: 'pdf' } : {}) }), '_blank', 'noopener');
    if (format === 'pdf') toast.info('Choose “Save as PDF” as the destination in the print dialog.');
  };

  /* ---------- Unknown report / no access ---------- */
  if (unavailable || metaQ.error) {
    const err = metaQ.error as ApiError | null;
    return (
      <>
        <PageHeader title="Report unavailable" breadcrumbs={[{ label: 'Reports & Analytics', to: '/reports' }, { label: 'Unavailable' }]} />
        <Card>
          <EmptyState
            icon={SearchX}
            title={!err ? 'This report is not available' : err.status === 403 ? 'You do not have access to this report' : err.status === 404 ? 'This report does not exist' : 'Unable to open this report'}
            description={!err ? 'The link may be mistyped, or the report covers a module your role cannot view. Choose a report from the catalogue instead.' : err.message}
            action={<Button size="sm" variant="secondary" icon={ArrowLeft} to="/reports">Back to all reports</Button>}
          />
        </Card>
      </>
    );
  }

  const Icon = reportIcon(meta?.icon);
  const sortState = data?.sort ?? meta?.default_sort ?? null;

  return (
    <>
      <PageHeader
        title={meta?.title ?? 'Loading report…'}
        description={meta?.description}
        breadcrumbs={[
          { label: 'Reports & Analytics', to: '/reports' },
          ...(meta ? [{ label: meta.area_label, to: `/reports?area=${meta.area}` }] : []),
          { label: meta?.title ?? 'Report' },
        ]}
        actions={
          meta && (
            <>
              <Button size="sm" variant="secondary" icon={ArrowLeft} to="/reports" className="hidden sm:inline-flex">
                All reports
              </Button>
              <Button size="sm" variant="secondary" icon={Bookmark} onClick={() => setPresetsOpen(true)}>
                Presets{presetCount ? ` (${presetCount})` : ''}
              </Button>
              <Button size="sm" variant="secondary" icon={Link2} onClick={copyLink}>
                Copy link
              </Button>
              {canExport && (
                <Dropdown
                  label="Export report"
                  triggerClassName="btn btn-primary btn-sm"
                  trigger={<><Download className="h-4 w-4" aria-hidden />Export</>}
                  items={[
                    { label: 'CSV (.csv)', icon: FileText, onClick: () => exportAs('csv'), hint: 'all rows' },
                    { label: 'Excel (.xlsx)', icon: FileSpreadsheet, onClick: () => exportAs('xlsx'), hint: 'all rows' },
                    { label: 'PDF (A4, letterhead)', icon: FileDown, onClick: () => exportAs('pdf') },
                    { label: 'Print', icon: Printer, onClick: () => exportAs('print') },
                  ]}
                />
              )}
            </>
          )
        }
      >
        {meta && (
          <div className="flex flex-wrap items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
            <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-semibold', areaTone(meta.area))}>
              <Icon className="h-3.5 w-3.5" aria-hidden />
              {meta.area_label}
            </span>
            {data && <span aria-live="polite">Updated {timeAgo(data.generated_at)}</span>}
            {!canExport && <span>· Your role can view this report but not export it.</span>}
          </div>
        )}
      </PageHeader>

      {!meta ? (
        <ViewSkeleton />
      ) : (
        <>
          <ReportFilters
            meta={meta}
            params={params}
            search={search}
            onSearch={setSearch}
            onChange={(c) => update(c)}
            onReset={reset}
            activeCount={activeCount}
            applied={data?.applied ?? []}
            total={data ? data.pagination.total : null}
          />

          {meta.note && (
            <p className="-mt-2 mb-5 flex items-start gap-1.5 text-xs text-slate-500 dark:text-slate-400">
              <Info className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden />
              {meta.note}
            </p>
          )}

          {dataQ.error ? (
            <Alert variant="error" title="Unable to run the report" action={<Button size="sm" variant="secondary" onClick={() => dataQ.refetch()}>Retry</Button>}>
              {(dataQ.error as ApiError).message}
            </Alert>
          ) : (
            <>
              {!data ? (
                <div className="mb-5 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                  {Array.from({ length: 5 }).map((_, i) => (
                    <StatCard key={i} label="Loading" value="" icon={ChartColumn} loading />
                  ))}
                </div>
              ) : (
                data.kpis.length > 0 && (
                  <Stagger className="mb-5 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5" itemClassName="min-w-0">
                    {data.kpis.map((k) => (
                      <StatCard key={k.label} label={k.label} value={<KpiValue kpi={k} />} icon={Icon} tone={k.tone} hint={k.hint ?? undefined} className="h-full" />
                    ))}
                  </Stagger>
                )
              )}

              {(data?.chart || !data) && (
                <Reveal className="mb-5">
                  <Card>
                    <CardHeader title={data?.chart?.title ?? 'Chart'} icon={ChartColumn} subtitle={data ? data.applied.join(' · ') || 'All records' : undefined} />
                    <div className="card-body">{!data ? <Skeleton className="h-64 w-full" /> : data.chart && <ReportChart chart={data.chart} />}</div>
                  </Card>
                </Reveal>
              )}

              <Card className="overflow-hidden">
                <CardHeader
                  title="Records"
                  icon={Table2}
                  subtitle={data ? `${formatNumber(data.pagination.total)} ${data.pagination.total === 1 ? 'row' : 'rows'}${sortState ? ` · sorted by ${meta.columns.find((c) => c.key === sortState.key)?.label ?? sortState.key} (${sortState.dir === 'asc' ? 'ascending' : 'descending'})` : ''}` : 'Loading…'}
                  actions={
                    <Button size="xs" variant="ghost" icon={RefreshCw} loading={dataQ.isFetching && !!data} onClick={() => dataQ.refetch()} aria-label="Refresh report data">
                      <span className="hidden sm:inline">Refresh</span>
                    </Button>
                  }
                />
                <DataTable<Row>
                  columns={columns}
                  rows={data?.rows ?? []}
                  rowKey={(r, i) => String(r.id ?? i)}
                  loading={!data || dataQ.isFetching}
                  dense
                  caption={`${meta.title} report`}
                  sort={sortState}
                  onSort={(s) => update({ sort: s.key, dir: s.dir })}
                  empty={
                    <EmptyState
                      icon={SearchX}
                      title="No records match these filters"
                      description={activeCount ? 'Widen the date range, choose “All” in a filter or clear the search.' : 'There is no data for this report yet. It fills up as the module is used.'}
                      action={activeCount ? <Button size="sm" variant="secondary" onClick={reset}>Reset filters</Button> : undefined}
                    />
                  }
                />
                {data && data.pagination.total > 0 && (
                  <Pagination
                    className="border-t border-slate-100 dark:border-slate-800"
                    page={data.pagination.page}
                    pages={data.pagination.pages}
                    total={data.pagination.total}
                    perPage={data.pagination.per_page}
                    onPage={(p) => update({ page: p > 1 ? String(p) : null }, false)}
                    onPerPage={(n) => update({ per_page: n === 25 ? null : String(n) })}
                    perPageOptions={PER_PAGE}
                  />
                )}
              </Card>
            </>
          )}

          {related.length > 0 && (
            <Reveal className="mt-6">
              <h2 className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">More {meta.area_label.toLowerCase()} reports</h2>
              <div className="flex flex-wrap gap-2">
                {related.map((r) => {
                  const RIcon = reportIcon(r.icon);
                  return (
                    <Link key={r.key} to={`/reports?r=${r.key}`} className="group inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:-translate-y-0.5 hover:border-brand-300 hover:shadow-soft motion-reduce:transform-none dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:border-brand-500/50">
                      <RIcon className="h-4 w-4 text-slate-400 group-hover:text-brand-600" aria-hidden />
                      {r.title}
                      <ArrowRight className="h-3.5 w-3.5 text-slate-400 transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" aria-hidden />
                    </Link>
                  );
                })}
              </div>
            </Reveal>
          )}

          <PresetsModal
            open={presetsOpen}
            onClose={() => setPresetsOpen(false)}
            meta={meta}
            userId={userId}
            currentQuery={filterQueryString(params)}
            onApply={(q) => {
              const next = new URLSearchParams({ r: reportKey });
              new URLSearchParams(q).forEach((v, k) => k !== 'r' && next.append(k, v));
              setParams(next, { replace: true });
            }}
          />
        </>
      )}
    </>
  );
}

function KpiValue({ kpi }: { kpi: Kpi }): ReactNode {
  if (kpi.value === null || kpi.value === '') return <span className="text-slate-400">—</span>;
  if (kpi.format === 'text') {
    return (
      <span className="block truncate text-lg leading-8" title={String(kpi.value)}>
        {String(kpi.value)}
      </span>
    );
  }
  const n = Number(kpi.value);
  switch (kpi.format) {
    case 'money':
      return (
        <span title={formatMoney(n)}>
          <CountUp value={Math.round(n)} format={formatMoneyShort} />
        </span>
      );
    case 'percent':
      return <CountUp value={n.toFixed(1)} format={(x) => `${x.toFixed(1)}%`} />;
    case 'decimal':
      return <CountUp value={n.toFixed(2)} format={(x) => x.toFixed(2)} />;
    case 'lpa':
      return <CountUp value={n.toFixed(2)} format={(x) => `${x.toFixed(2)} LPA`} />;
    case 'hours':
      return <CountUp value={n.toFixed(1)} format={(x) => `${x.toFixed(1)} h`} />;
    default:
      return <CountUp value={Math.round(n)} />;
  }
}

function ViewSkeleton() {
  return (
    <>
      <div className="card mb-5 grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-4">
        {Array.from({ length: 4 }).map((_, i) => (
          <div key={i}>
            <Skeleton className="mb-2 h-3 w-20" />
            <Skeleton className="h-8 w-full" />
          </div>
        ))}
      </div>
      <div className="mb-5 grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="card p-4">
            <Skeleton className="h-9 w-9 rounded-xl" />
            <Skeleton className="mt-3 h-3 w-24" />
            <Skeleton className="mt-2 h-6 w-16" />
          </div>
        ))}
      </div>
      <div className="card p-5">
        <Skeleton className="h-64 w-full" />
      </div>
    </>
  );
}
