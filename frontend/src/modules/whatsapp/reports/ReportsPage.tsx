import { useMemo } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertOctagon,
  BarChart3,
  BookOpen,
  Bot,
  Clock,
  Filter,
  GraduationCap,
  Heart,
  LayoutTemplate,
  Megaphone,
  MessageSquareText,
  RotateCcw,
  Users,
  type LucideIcon,
} from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Select, Skeleton, Stagger, StatCard, type Tone } from '@/components/ui';
import { BarChart, DoughnutChart, LineChart } from '@/components/charts';
import { ApiError, type Query } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatMoney, formatNumber, formatPercent } from '@/lib/format';
import { ModeBanner } from '../shared';
import { useReport, useReportMeta, type Period, type ReportChart, type ReportSummary } from '../admin/lib';
import { PeriodPicker } from '../admin/ui';
import { FunnelBars } from '../dashboard/FunnelBars';
import { ReportTableCard } from './ReportTable';

const REPORT_ICONS: Record<string, LucideIcon> = {
  messages: MessageSquareText,
  campaigns: Megaphone,
  templates: LayoutTemplate,
  conversion: GraduationCap,
  counsellors: Users,
  engagement: Heart,
  automation: Bot,
  failures: AlertOctagon,
  courses: BookOpen,
};
const FALLBACK = [
  { key: 'messages', title: 'Messages' },
  { key: 'campaigns', title: 'Campaigns' },
  { key: 'templates', title: 'Templates' },
  { key: 'conversion', title: 'Admission conversion' },
  { key: 'counsellors', title: 'Counsellor performance' },
  { key: 'engagement', title: 'Student engagement' },
  { key: 'automation', title: 'Automation execution' },
  { key: 'failures', title: 'Failed automation' },
  { key: 'courses', title: 'Course-wise enquiries' },
];
const TONES: Tone[] = ['blue', 'navy', 'green', 'orange', 'amber', 'purple', 'pink', 'red', 'cyan', 'slate'];
/**
 * KPI grids sized to the report column (page width − 272 px sidebar − 15 rem report list): six tiles share one row only
 * from 1680 px, so a money value (Meta cost ₹1,091.03) never touches the tile edge — 3 × 2 on laptops, 2 per row at lg
 * where the column is narrowest. Five-tile reports hold counts / percentages only.
 */
const KPI_GRID_6 = 'grid-cols-2 md:grid-cols-3 lg:grid-cols-2 xl:grid-cols-3 min-[1680px]:grid-cols-6';
const KPI_GRID_5 = 'grid-cols-2 md:grid-cols-3 xl:grid-cols-5';
const PALETTE = ['#0B2A5B', '#F05A28', '#22943F', '#8B5CF6', '#06B6D4', '#F59E0B', '#EF4444'];

/** Filters each report understands (besides the period). */
const REPORT_FILTERS: Record<string, ('program_id' | 'source' | 'sender_type' | 'category' | 'contact_type')[]> = {
  messages: ['sender_type'],
  templates: ['category'],
  conversion: ['program_id', 'source'],
  engagement: ['contact_type'],
};

function summaryValue(s: ReportSummary) {
  if (s.format === 'percent') return formatPercent(s.value, 1);
  if (s.format === 'money') return formatMoney(s.value, 2);
  if (s.format === 'minutes') return s.value >= 120 ? `${formatNumber(s.value / 60, 1)} h` : `${formatNumber(s.value, s.value % 1 ? 1 : 0)} min`;
  return s.value;
}

/**
 * Reports & Analytics (Module 12): nine WhatsApp reports with period + filters, KPI summary, charts and tables, each
 * table exportable as CSV / Excel (server-side, permission whatsapp_reports.export + the security setting).
 */
export default function ReportsPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const meta = useReportMeta();
  const reports = meta.data?.reports ?? FALLBACK;
  const key = reports.some((r) => r.key === params.get('report')) ? (params.get('report') as string) : 'messages';
  const period = (['daily', 'weekly', 'monthly', 'custom'].includes(params.get('period') ?? '') ? params.get('period') : 'monthly') as Period;
  const from = params.get('from') ?? undefined;
  const to = params.get('to') ?? undefined;
  const filterKeys = REPORT_FILTERS[key] ?? [];

  const query = useMemo(() => {
    const q: Query = period === 'custom' && from && to ? { period, from, to } : { period: period === 'custom' ? 'monthly' : period };
    filterKeys.forEach((f) => {
      const v = params.get(f);
      if (v) q[f] = v;
    });
    return q;
  }, [period, from, to, params, filterKeys]);

  const { data, isLoading, isFetching, error, refetch } = useReport(key, query);
  const current = reports.find((r) => r.key === key);

  const setParam = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === '' ? next.delete(k) : next.set(k, v)));
    setParams(next, { replace: true });
  };
  const setReport = (k: string) => setParam({ report: k === 'messages' ? null : k, program_id: null, source: null, sender_type: null, category: null, contact_type: null });
  const hasFilters = filterKeys.some((f) => params.get(f));

  const filterOptions: Record<string, { label: string; options: { value: string | number; label: string }[] }> = {
    program_id: { label: 'Program', options: meta.data?.programs ?? [] },
    source: { label: 'Source', options: meta.data?.sources ?? [] },
    sender_type: { label: 'Sender', options: meta.data?.sender_types ?? [] },
    category: { label: 'Template category', options: meta.data?.categories ?? [] },
    contact_type: { label: 'Contact type', options: meta.data?.contact_types ?? [] },
  };

  return (
    <>
      <PageHeader
        title="Reports & Analytics"
        description="Delivery, campaigns, templates, admissions conversion, counsellor performance and automation health on WhatsApp."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp', to: can('whatsapp', 'view') ? '/whatsapp' : undefined }, { label: 'Reports & Analytics' }]}
        actions={
          can('whatsapp', 'view') ? (
            <Button variant="secondary" icon={BarChart3} to="/whatsapp">
              Dashboard
            </Button>
          ) : undefined
        }
      />
      <ModeBanner />

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-[15rem_minmax(0,1fr)]">
        {/* report picker */}
        <div className="lg:hidden">
          <label htmlFor="wa-report" className="form-label">
            Report
          </label>
          <Select id="wa-report" value={key} onChange={(e) => setReport(e.target.value)} options={reports.map((r) => ({ value: r.key, label: r.title }))} />
        </div>
        <nav aria-label="Reports" className="hidden lg:block">
          <Card className="sticky top-20 p-2">
            <ul className="space-y-0.5">
              {reports.map((r) => {
                const Icon = REPORT_ICONS[r.key] ?? BarChart3;
                const active = r.key === key;
                return (
                  <li key={r.key}>
                    <button
                      type="button"
                      onClick={() => setReport(r.key)}
                      aria-current={active ? 'page' : undefined}
                      className={clsx(
                        'flex w-full items-center gap-2.5 rounded-xl px-3 py-2 text-left text-sm transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                        active
                          ? 'bg-brand-900 font-semibold text-white shadow-soft dark:bg-brand-700'
                          : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
                      )}
                    >
                      <Icon className={clsx('h-4 w-4 shrink-0', active ? 'text-accent-300' : 'text-slate-400')} aria-hidden />
                      <span className="truncate">{r.title}</span>
                    </button>
                  </li>
                );
              })}
            </ul>
          </Card>
        </nav>

        <div className="min-w-0">
          <Card className="mb-4">
            <div className="flex flex-col gap-3 p-4">
              <div className="flex flex-col gap-1 sm:flex-row sm:items-baseline sm:justify-between">
                <h2 className="font-display text-lg font-semibold text-slate-900 dark:text-white">{current?.title ?? 'Report'}</h2>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {data ? (
                    <>
                      {data.range.label} · by {data.range.bucket}
                    </>
                  ) : (
                    ' '
                  )}
                  {isFetching && !isLoading && <span className="ml-2 text-brand-600 dark:text-brand-300">Updating…</span>}
                </p>
              </div>
              {data?.description && <p className="-mt-1 text-sm text-slate-500 dark:text-slate-400">{data.description}</p>}
              <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                <PeriodPicker key={key} period={period} from={from} to={to} onChange={(v) => setParam({ period: v.period === 'monthly' ? null : v.period, from: v.from ?? null, to: v.to ?? null })} />
                {filterKeys.length > 0 && (
                  <div className="flex flex-wrap items-center gap-2">
                    <Filter className="hidden h-4 w-4 text-slate-400 sm:block" aria-hidden />
                    {filterKeys.map((f) => (
                      <Select
                        key={f}
                        inputSize="sm"
                        aria-label={filterOptions[f].label}
                        className="w-full sm:w-48"
                        value={params.get(f) ?? ''}
                        placeholder={`All — ${filterOptions[f].label.toLowerCase()}`}
                        options={filterOptions[f].options.map((o) => ({ value: String(o.value), label: o.label }))}
                        onChange={(e) => setParam({ [f]: e.target.value || null })}
                      />
                    ))}
                    {hasFilters && (
                      <Button size="sm" variant="ghost" icon={RotateCcw} onClick={() => setParam(Object.fromEntries(filterKeys.map((f) => [f, null])))}>
                        Clear
                      </Button>
                    )}
                  </div>
                )}
              </div>
            </div>
          </Card>

          {error ? (
            <Alert variant="error" title="Could not load this report" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
              {(error as ApiError).message}
            </Alert>
          ) : isLoading || !data ? (
            <div className="space-y-4">
              <div className={clsx('grid gap-3', KPI_GRID_6)}>
                {[0, 1, 2, 3, 4, 5].map((i) => (
                  <Skeleton key={i} className="h-28 w-full rounded-2xl" />
                ))}
              </div>
              <Skeleton className="h-72 w-full rounded-2xl" />
              <Skeleton className="h-64 w-full rounded-2xl" />
            </div>
          ) : (
            <div className={clsx('space-y-5 transition-opacity', isFetching && 'opacity-70')}>
              <Stagger key={data.key} className={clsx('grid gap-3', data.summary.length === 5 ? KPI_GRID_5 : KPI_GRID_6)} step={40}>
                {data.summary.map((s, i) => (
                  <StatCard
                    key={s.label}
                    className="h-full min-w-0"
                    label={s.label}
                    value={summaryValue(s)}
                    icon={[MessageSquareText, Users, BarChart3, Clock, Heart, AlertOctagon][i % 6]}
                    tone={(TONES.includes(s.tone as Tone) ? s.tone : TONES[i % TONES.length]) as Tone}
                    hint={s.hint}
                  />
                ))}
              </Stagger>

              {data.charts.length > 0 && (
                <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
                  {data.charts.map((c, i) => (
                    <Reveal key={`${data.key}-${i}`} delay={i * 60} className={chartSpan(c, i, data.charts.length, data.charts)}>
                      <ChartCard chart={c} />
                    </Reveal>
                  ))}
                </div>
              )}

              {data.tables.map((t) => (
                <Reveal key={`${data.key}-${t.key}`}>
                  <ReportTableCard reportKey={data.key} table={t} query={query} canExport={!!meta.data?.can_export} />
                </Reveal>
              ))}
            </div>
          )}
        </div>
      </div>
    </>
  );
}

/** 12-column spans: the funnel and a lone chart take the full width; the other charts share the row equally. */
function chartSpan(c: ReportChart, _i: number, n: number, charts: ReportChart[] = []): string {
  if (n === 1 || c.type === 'funnel') return 'xl:col-span-12';
  return charts.filter((x) => x.type !== 'funnel').length === 1 ? 'xl:col-span-12' : 'xl:col-span-6';
}

function ChartCard({ chart }: { chart: ReportChart }) {
  const empty =
    chart.type === 'doughnut' ? !chart.data.some((v) => v > 0) : chart.type === 'funnel' ? !chart.stages.some((s) => s.reached > 0) : !chart.series.some((s) => s.data.some((v) => v > 0));
  return (
    <Card className="h-full">
      <CardHeader title={chart.title} icon={BarChart3} />
      <div className="card-body">
        {empty ? (
          <EmptyState icon={BarChart3} title="Nothing to chart for this period" />
        ) : chart.type === 'funnel' ? (
          <FunnelBars stages={chart.stages} />
        ) : chart.type === 'doughnut' ? (
          <DoughnutChart labels={chart.labels} data={chart.data} colors={chart.colors ?? PALETTE} valueFormat="number" centerValue={formatNumber(chart.data.reduce((a, b) => a + b, 0))} centerLabel="total" height={220} ariaLabel={chart.title} />
        ) : chart.type === 'line' ? (
          <LineChart labels={chart.labels} legend={chart.series.length > 1} percent={chart.percent} series={chart.series.map((s, i) => ({ ...s, color: s.color ?? PALETTE[i % PALETTE.length] }))} ariaLabel={chart.title} />
        ) : (
          <BarChart
            labels={chart.labels}
            legend={chart.series.length > 1}
            percent={chart.percent}
            horizontal={chart.horizontal}
            stacked={chart.stacked}
            max={chart.percent ? 100 : undefined}
            height={chart.horizontal ? Math.max(220, chart.labels.length * 40) : 260}
            series={chart.series.map((s, i) => ({ ...s, color: s.color ?? PALETTE[i % PALETTE.length] }))}
            ariaLabel={chart.title}
          />
        )}
      </div>
    </Card>
  );
}
