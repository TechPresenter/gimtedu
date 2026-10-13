import { memo, useMemo } from 'react';
import { Link } from 'react-router-dom';
import { ArrowRight, BadgeCheck, Briefcase, Building2, CalendarDays, Factory, GraduationCap, Handshake, MapPin, PieChart, TrendingUp, UserPlus, UsersRound } from 'lucide-react';
import { Alert, Avatar, Button, Card, CardHeader, EmptyState, Reveal, Skeleton, StatCard, Stagger, StatusBadge } from '@/components/ui';
import { BarChart, DoughnutChart, chartColor } from '@/components/charts';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { BarList, EMPLOYMENT_COLORS, EVENT_MODE_LABELS } from './shared';

export interface AlumniDashboard {
  kpis: {
    total: number; working: number; higher_studies: number; entrepreneurs: number; seeking: number; mentors: number; verified: number; companies: number; batches: number;
    cities: number; abroad: number; unverified_recent: number; first_batch: number | null; latest_batch: number; events_upcoming: number; events_this_year: number; events_total: number;
    jobs_open: number; jobs_pending: number; mentorships_active: number; donations_fy: number; fy_label: string; pending_import: number;
  };
  next_event: { id: number; title: string; event_date: string; venue: string | null; mode: string } | null;
  by_batch: { year: number; total: number; working: number }[];
  by_industry: { label: string; total: number }[];
  top_companies: { name: string; total: number; industry: string | null; mentors: number }[];
  top_cities: { label: string; total: number }[];
  by_status: { status: string; label: string; total: number }[];
  by_program: { label: string; total: number }[];
  recent: Row[];
}

const pct = (a: number, b: number) => (b ? Math.round((a / b) * 100) : 0);

/** KPIs, charts and breakdowns above the directory table. Memoised so table paging / searching does not redraw the charts. */
export const DirectoryOverview = memo(function DirectoryOverview({ data, isLoading, error, canImport, canEdit, onImport }: { data?: AlumniDashboard; isLoading: boolean; error: unknown; canImport: boolean; canEdit: boolean; onImport: () => void }) {
  const k = data?.kpis;
  const batchChart = useMemo(
    () =>
      data
        ? {
            labels: data.by_batch.map((b) => String(b.year)),
            series: [
              { label: 'Working', data: data.by_batch.map((b) => b.working), color: chartColor(0) },
              { label: 'Higher studies / other', data: data.by_batch.map((b) => b.total - b.working), color: '#93C5FD' },
            ],
          }
        : null,
    [data],
  );
  return (
    <>
      {error ? <Alert variant="error" title="Unable to load alumni statistics" className="mb-6">{(error as Error).message}</Alert> : null}
      {k && k.pending_import > 0 && canImport && (
        <Reveal>
          <div className="mb-6 flex flex-col gap-3 rounded-2xl border border-accent-200 bg-accent-50/70 p-4 sm:flex-row sm:items-center dark:border-accent-500/20 dark:bg-accent-500/10">
            <span className="kpi-icon shrink-0 bg-accent-100 text-accent-700 dark:bg-accent-500/20 dark:text-accent-300"><GraduationCap className="h-5 w-5" /></span>
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-slate-900 dark:text-white">{formatNumber(k.pending_import)} passed-out student{k.pending_import === 1 ? ' is' : 's are'} not in the alumni directory yet</p>
              <p className="text-sm text-slate-600 dark:text-slate-300">Import them to start tracking their careers and invite them to events, mentoring and the alumni network.</p>
            </div>
            <Button variant="success" icon={UserPlus} onClick={onImport} className="shrink-0">Review &amp; import</Button>
          </div>
        </Reveal>
      )}

      {k && k.unverified_recent > 0 && canEdit && (
        <Reveal>
          <div className="mb-6 flex flex-col gap-3 rounded-2xl border border-brand-200 bg-brand-50/70 p-4 sm:flex-row sm:items-center dark:border-brand-500/20 dark:bg-brand-500/10">
            <span className="kpi-icon shrink-0 bg-brand-100 text-brand-700 dark:bg-brand-500/20 dark:text-brand-200"><BadgeCheck className="h-5 w-5" /></span>
            <div className="min-w-0 flex-1">
              <p className="font-semibold text-slate-900 dark:text-white">{formatNumber(k.unverified_recent)} new profile{k.unverified_recent === 1 ? '' : 's'} awaiting verification</p>
              <p className="text-sm text-slate-600 dark:text-slate-300">Added in the last 30 days (including registrations from the website). Check the details, then mark them verified.</p>
            </div>
            <Button variant="secondary" icon={BadgeCheck} to="/alumni?f.is_verified=0" className="shrink-0">Review profiles</Button>
          </div>
        </Reveal>
      )}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="total" label="Total alumni" icon={UsersRound} tone="navy" loading={isLoading} value={k?.total ?? 0}
            hint={k ? `${k.batches} batches since ${k.first_batch ?? '—'}` : undefined} />,
          <StatCard key="working" label="Working professionals" icon={Briefcase} tone="green" loading={isLoading} value={k?.working ?? 0}
            hint={k ? `${pct(k.working, k.total)}% of all alumni` : undefined} />,
          <StatCard key="companies" label="Companies" icon={Building2} tone="blue" loading={isLoading} value={k?.companies ?? 0}
            hint={k ? `${k.cities} cities · ${k.abroad} abroad` : undefined} />,
          <StatCard key="mentors" label="Mentors" icon={Handshake} tone="purple" loading={isLoading} value={k?.mentors ?? 0}
            hint={k ? `${formatNumber(k.mentorships_active)} mentoring now` : undefined} to="/alumni?tab=mentors" />,
          <StatCard key="events" label="Upcoming events" icon={CalendarDays} tone="orange" loading={isLoading} value={k?.events_upcoming ?? 0}
            hint={k ? `${k.events_this_year} this year` : undefined} to="/alumni/events" />,
          <StatCard key="jobs" label="Job opportunities" icon={TrendingUp} tone="cyan" loading={isLoading} value={k?.jobs_open ?? 0}
            hint={k ? (k.jobs_pending ? `${k.jobs_pending} pending approval` : 'Open now') : undefined} to="/alumni/jobs" />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title="Alumni by batch" subtitle="Passing-out year · working professionals vs. others" icon={GraduationCap} />
          <div className="card-body !pt-1">
            {isLoading || !data ? (
              <Skeleton className="h-[260px] w-full" />
            ) : data.by_batch.length === 0 ? (
              <EmptyState icon={GraduationCap} title="No alumni yet" description="Import passed-out students or add alumni profiles to see the batch distribution." className="py-10" />
            ) : (
              batchChart && <BarChart labels={batchChart.labels} series={batchChart.series} stacked height={260} />
            )}
          </div>
        </Card>
        <Card>
          <CardHeader title="Industries" subtitle="Where working alumni are employed" icon={Factory} />
          <div className="card-body">
            {isLoading || !data ? (
              <Skeleton className="h-52 w-full" />
            ) : data.by_industry.length === 0 ? (
              <EmptyState icon={PieChart} title="No career data yet" className="py-8" />
            ) : (
              <>
                <div className="flex justify-center">
                  <DoughnutChart labels={data.by_industry.map((r) => r.label)} data={data.by_industry.map((r) => r.total)} centerValue={formatNumber(k?.working ?? 0)} centerLabel="working" height={170} legendList={false} />
                </div>
                <ul className="mt-4 grid grid-cols-1 gap-x-4 gap-y-1.5 border-t border-slate-100 pt-3 text-xs sm:grid-cols-2 dark:border-slate-800">
                  {data.by_industry.map((r, i) => (
                    <li key={r.label} className="flex items-center justify-between gap-2">
                      <span className="flex min-w-0 items-center gap-2 text-slate-600 dark:text-slate-300"><span className="h-2 w-2 shrink-0 rounded-full" style={{ background: chartColor(i) }} /><span className="truncate" title={r.label}>{r.label}</span></span>
                      <span className="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{pct(r.total, k?.working ?? 0)}%</span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </Card>
      </div>

      <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-3">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Top companies" subtitle="Employers with the most GIMT alumni" icon={Building2} />
            <div className="card-body !pt-1">
              {isLoading || !data ? (
                <div className="space-y-4">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-8" />)}</div>
              ) : data.top_companies.length === 0 ? (
                <EmptyState icon={Building2} title="No companies recorded" className="py-8" />
              ) : (
                <BarList
                  items={data.top_companies.slice(0, 8).map((c) => ({
                    key: c.name,
                    label: <Link to={`/alumni?f.company=${encodeURIComponent(c.name)}`} className="hover:text-brand-700 dark:hover:text-brand-300">{c.name}</Link>,
                    value: c.total,
                    sub: [c.industry, c.mentors ? `${c.mentors} mentor${c.mentors === 1 ? '' : 's'}` : null].filter(Boolean).join(' · '),
                  }))}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Career status & locations" subtitle="Employment outcome and top cities" icon={MapPin} />
            <div className="card-body !pt-1">
              {isLoading || !data ? (
                <div className="space-y-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-8" />)}</div>
              ) : (
                <>
                  <div className="mb-5 flex flex-wrap gap-2">
                    {data.by_status.map((s) => (
                      <Link key={s.status} to={`/alumni?f.employment_status=${s.status}`} className="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 px-2.5 py-1.5 text-xs transition hover:border-brand-200 hover:bg-brand-50/60 dark:border-slate-700 dark:hover:bg-slate-800">
                        <StatusBadge status={s.status} label={s.label} colors={EMPLOYMENT_COLORS} dot={false} />
                        <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(s.total)}</span>
                        <span className="text-slate-400">{pct(s.total, k?.total ?? 0)}%</span>
                      </Link>
                    ))}
                  </div>
                  <BarList items={data.top_cities.slice(0, 6).map((c) => ({ key: c.label, label: c.label, value: c.total }))} tone="bg-accent-500" />
                </>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={120} className="lg:col-span-2 xl:col-span-1">
          <Card className="h-full">
            <CardHeader title="Recently added" subtitle="Newest profiles and the next alumni event" icon={UserPlus} />
            <div className="card-body !pt-0">
              {data?.next_event && (
                <Link to="/alumni/events" className="mb-3 flex items-center gap-3 rounded-xl bg-brand-900 p-3 text-white transition hover:-translate-y-0.5 hover:shadow-soft">
                  <CalendarDays className="h-5 w-5 shrink-0 text-accent-300" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold">{data.next_event.title}</span>
                    <span className="block truncate text-xs text-white/70">{formatDateTime(data.next_event.event_date)} · {EVENT_MODE_LABELS[data.next_event.mode] ?? data.next_event.mode}</span>
                  </span>
                  <ArrowRight className="h-4 w-4 shrink-0 text-white/70" />
                </Link>
              )}
              {isLoading || !data ? (
                <div className="space-y-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10" />)}</div>
              ) : data.recent.length === 0 ? (
                <EmptyState icon={UsersRound} title="No alumni yet" className="py-6" action={canImport && <Button size="sm" icon={UserPlus} onClick={onImport}>Import students</Button>} />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.recent.slice(0, 5).map((r) => (
                    <li key={String(r.id)}>
                      <Link to={`/alumni/${r.id}`} className="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <Avatar name={String(r.name)} src={r.photo as string | null} size="sm" />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{String(r.name)}</span>
                          <span className="block truncate text-xs text-slate-500 dark:text-slate-400">
                            {[r.program_short, `Batch ${r.batch_year}`, r.company].filter(Boolean).join(' · ')}
                          </span>
                        </span>
                        <span className="shrink-0 text-xs text-slate-400">{timeAgo(r.created_at)}</span>
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
      </div>
    </>
  );
});
