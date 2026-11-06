import { useMemo, useState, type ReactNode } from 'react';
import clsx from 'clsx';
import { Award, BriefcaseBusiness, Building2, CalendarCheck, ChartPie, IndianRupee, TrendingUp, UserPlus, VenusAndMars } from 'lucide-react';
import { Button } from '@/components/ui';
import { formatMoneyShort, formatNumber, formatPercent } from '@/lib/format';
import { AreaLineChart, GradientBarChart, RingDonut } from './DashCharts';
import { HUES, hueAt, hueHex, type Hue } from './hues';
import { Panel, PanelEmpty, PeriodSelect, ViewAll } from './Panel';
import type { DashboardCharts } from './types';

type Can = (module: string, action?: 'view' | 'create') => boolean;

const C = {
  indigo: HUES.indigo.hex, emerald: HUES.emerald.hex, amber: HUES.amber.hex, rose: HUES.rose.hex,
  sky: HUES.sky.hex, violet: HUES.violet.hex, teal: HUES.teal.hex, orange: HUES.orange.hex,
};

/* ------------------------------------------------------------ Admissions trend */
export function AdmissionsTrendCard({ data, can }: { data: NonNullable<DashboardCharts['admissions_trend']>; can: Can }) {
  const [months, setMonths] = useState<'6' | '12'>('6');
  const rows = useMemo(() => data.slice(-Number(months)), [data, months]);
  const apps = rows.reduce((a, r) => a + r.applications, 0);
  const adm = rows.reduce((a, r) => a + r.admissions, 0);
  const series = useMemo(() => [
    { label: 'Applications', data: rows.map((r) => r.applications), color: C.indigo },
    { label: 'Admissions', data: rows.map((r) => r.admissions), color: C.emerald },
  ], [rows]);
  return (
    <Panel
      title="Admissions Trend"
      subtitle={`${formatNumber(apps)} applications · ${formatNumber(adm)} confirmed`}
      icon={UserPlus}
      hue="indigo"
      action={<PeriodSelect label="Admissions trend period" value={months} onChange={setMonths} options={[{ value: '6', label: 'Last 6 Months' }, { value: '12', label: 'Last 12 Months' }]} />}
    >
      {apps + adm === 0 ? (
        <PanelEmpty icon={UserPlus} title="No applications in this period" description="New online and counter applications will be charted here." action={can('admissions', 'create') ? <Button size="sm" to="/admissions/new">New application</Button> : undefined} />
      ) : (
        <GradientBarChart height={210} labels={rows.map((r) => r.label)} series={series} />
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Fee collection */
export function FeeCollectionCard({ data, can }: { data: NonNullable<DashboardCharts['fee_collection']>; can: Can }) {
  const [mode, setMode] = useState<'cumulative' | 'monthly'>('cumulative');
  const rows = useMemo(() => {
    // Months that have not started yet only carry a target; their collection is left unplotted (NaN = gap).
    const now = new Date();
    const thisMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
    let c = 0;
    let t = 0;
    return data.months.map((m) => {
      c += m.collected;
      t += m.target;
      const future = m.month > thisMonth;
      const row = mode === 'cumulative' ? { ...m, collected: c, target: t } : { ...m };
      return future ? { ...row, collected: Number.NaN } : row;
    });
  }, [data.months, mode]);
  const hasTarget = data.months.some((m) => m.target > 0);
  const rate = data.billed > 0 ? (data.collected / data.billed) * 100 : null;
  const series = useMemo(() => [
    { label: 'Collection', data: rows.map((m) => m.collected), color: C.teal, fill: true },
    ...(hasTarget ? [{ label: 'Target', data: rows.map((m) => m.target), color: C.amber, dashed: true }] : []),
  ], [rows, hasTarget]);
  return (
    <Panel
      title="Fee Collection"
      subtitle={`${formatMoneyShort(data.collected)} collected${rate !== null ? ` · ${formatPercent(rate)} of ${formatMoneyShort(data.billed)} billed` : ''}`}
      icon={IndianRupee}
      hue="teal"
      action={<PeriodSelect label="Fee collection view" value={mode} onChange={setMode} options={[{ value: 'cumulative', label: 'This Session' }, { value: 'monthly', label: 'Month by month' }]} />}
    >
      {data.collected === 0 && !hasTarget ? (
        <PanelEmpty icon={IndianRupee} title="No fee activity yet" description="Collections and installment targets for this session will appear here." action={can('fees', 'create') ? <Button size="sm" to="/fees/collect">Collect fees</Button> : undefined} />
      ) : (
        <AreaLineChart height={210} format="money" labels={rows.map((m) => m.label)} series={series} />
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Student attendance */
export function AttendanceCard({ data, can }: { data: NonNullable<DashboardCharts['attendance']>; can: Can }) {
  const [period, setPeriod] = useState<'day' | 'week' | 'month'>('day');
  const a = data[period];
  const present = a.present + a.late;
  return (
    <Panel
      title="Student Attendance"
      subtitle={`${a.label} · ${formatNumber(a.sheets)} class sessions`}
      icon={CalendarCheck}
      hue="sky"
      action={<PeriodSelect label="Attendance period" value={period} onChange={setPeriod} options={[{ value: 'day', label: data.day.label === 'Today' ? 'Today' : 'Last marked day' }, { value: 'week', label: 'This Week' }, { value: 'month', label: 'Current Month' }]} />}
    >
      {a.total === 0 ? (
        <PanelEmpty icon={CalendarCheck} title="No attendance marked" action={can('attendance', 'create') ? <Button size="sm" to="/attendance?tab=mark">Mark attendance</Button> : undefined} />
      ) : (
        <div className="mx-auto w-full max-w-md">
          <RingDonut
            size={164}
            labels={['Present', 'Absent', 'Leave']}
            data={[present, a.absent, a.leave]}
            colors={[C.emerald, C.rose, C.amber]}
            centerValue={a.percent !== null ? `${Math.round(a.percent)}%` : '—'}
            centerLabel="Overall"
          />
        </div>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Course distribution */
export function DistributionCard({ data }: { data: NonNullable<DashboardCharts['distribution']> }) {
  const [by, setBy] = useState<'category' | 'level'>('category');
  const rows = data[by];
  const total = rows.reduce((a, r) => a + r.value, 0);
  return (
    <Panel
      title="Course Distribution"
      subtitle="Active students by program"
      icon={ChartPie}
      hue="violet"
      action={<PeriodSelect label="Group programs by" value={by} onChange={setBy} options={[{ value: 'category', label: 'All Programs' }, { value: 'level', label: 'By Level' }]} />}
    >
      {total === 0 ? (
        <PanelEmpty icon={ChartPie} title="No active students yet" />
      ) : (
        <div className="mx-auto w-full max-w-md">
          <RingDonut size={164} labels={rows.map((r) => r.label)} data={rows.map((r) => r.value)} colors={rows.map((_, i) => hueHex(i))} centerValue={formatNumber(total)} centerLabel="Students" />
        </div>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Student growth */
export function StudentGrowthCard({ data }: { data: NonNullable<DashboardCharts['student_growth']> }) {
  const prev = data.length > 1 ? data[data.length - 2] : null;
  const last = data[data.length - 1]?.total ?? 0;
  const growth = prev && prev.total > 0 ? ((last - prev.total) / prev.total) * 100 : null;
  const series = useMemo(() => [
    { label: 'Total enrolled', data: data.map((d) => d.total), color: C.indigo, fill: true },
    { label: 'New admissions', data: data.map((d) => d.new), color: C.emerald, fill: true },
  ], [data]);
  return (
    <Panel
      title="Student Growth"
      subtitle={growth !== null ? `${formatNumber(last)} enrolled · ${growth >= 0 ? '+' : ''}${formatPercent(growth)} vs ${prev?.label}` : 'Enrolment across academic sessions'}
      icon={TrendingUp}
      hue="indigo"
      action={<ViewAll to="/reports" label="Reports" />}
    >
      {data.length === 0 || last === 0 ? (
        <PanelEmpty icon={TrendingUp} title="No enrolment history yet" />
      ) : (
        <AreaLineChart height={230} labels={data.map((d) => d.label)} series={series} />
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Gender */
export function GenderCard({ data }: { data: NonNullable<DashboardCharts['gender']> }) {
  const total = data.reduce((a, r) => a + r.value, 0);
  const palette: Record<string, string> = { Male: C.sky, Female: C.rose, Other: C.violet, Unspecified: '#94A3B8' };
  return (
    <Panel title="Gender Distribution" subtitle="Active students" icon={VenusAndMars} hue="rose">
      {total === 0 ? (
        <PanelEmpty icon={VenusAndMars} title="No active students yet" />
      ) : (
        <div className="mx-auto w-full max-w-md">
          <RingDonut size={164} labels={data.map((d) => d.label)} data={data.map((d) => d.value)} colors={data.map((d, i) => palette[d.label] ?? hueHex(i + 2))} centerValue={formatNumber(total)} centerLabel="Students" />
        </div>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Departments */
export function DepartmentsCard({ data }: { data: NonNullable<DashboardCharts['departments']> }) {
  const max = Math.max(1, ...data.map((d) => d.value));
  const total = data.reduce((a, d) => a + d.value, 0);
  return (
    <Panel title="Department-wise Students" subtitle={`${formatNumber(total)} active students`} icon={Building2} hue="emerald" action={<ViewAll to="/academics?tab=departments" label="Departments" />}>
      {data.length === 0 ? (
        <PanelEmpty icon={Building2} title="No department data yet" />
      ) : (
        <ul className="space-y-3.5">
          {data.map((d, i) => {
            const hue: Hue = hueAt(i);
            return (
              <li key={d.code || d.label}>
                <div className="flex items-baseline justify-between gap-3 text-[13px]">
                  <span className="flex min-w-0 items-center gap-2 font-medium text-slate-700 dark:text-slate-200" title={d.label}>
                    <span className={clsx('h-2 w-2 shrink-0 rounded-full', HUES[hue].dot)} aria-hidden />
                    <span className="truncate">{d.label}</span>
                  </span>
                  <span className="shrink-0 tabular-nums text-slate-500 dark:text-slate-400">
                    <span className="font-semibold text-slate-900 dark:text-white">{formatNumber(d.value)}</span> · {formatPercent((d.value / Math.max(1, total)) * 100)}
                  </span>
                </div>
                <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" aria-label={`${d.label}: ${d.value} students`}>
                  <div className={clsx('h-full rounded-full bg-gradient-to-r transition-[width] duration-700 ease-out motion-reduce:transition-none', HUES[hue].edge)} style={{ width: `${(d.value / max) * 100}%` }} />
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Exam performance */
export function ExamPerformanceCard({ data, can }: { data: NonNullable<DashboardCharts['exam_performance']>; can: Can }) {
  const series = useMemo(() => [
    { label: 'Pass %', data: data.programs.map((p) => p.pass_percent), color: C.emerald },
    { label: 'Average %', data: data.programs.map((p) => p.avg_percent), color: C.violet },
  ], [data.programs]);
  return (
    <Panel
      title="Examination Performance"
      subtitle={data.students ? `${formatPercent(data.pass_percent ?? 0, 1)} pass rate · ${formatNumber(data.students)} results${data.scope === 'computed' ? ' (unpublished)' : ''}` : 'Program-wise results this session'}
      icon={Award}
      hue="violet"
      action={can('results') ? <ViewAll to="/results" label="Results" /> : undefined}
    >
      {data.programs.length === 0 ? (
        <PanelEmpty icon={Award} title="No results this session" description="Pass percentage and average score per program appear once results are computed." action={can('examination') ? <Button size="sm" variant="secondary" to="/examination">View exams</Button> : undefined} />
      ) : (
        <GradientBarChart format="percent" height={Math.max(210, data.programs.length * 28)} labels={data.programs.map((p) => p.label)} series={series} />
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Placement */
export function PlacementCard({ data, can }: { data: NonNullable<DashboardCharts['placement']>; can: Can }) {
  const lpa = (v: number | null) => (v === null ? '—' : <>{v.toFixed(v % 1 ? 1 : 0)}<span className="ml-0.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400">LPA</span></>);
  const stats: { label: string; value: ReactNode; hue: Hue }[] = [
    { label: 'Placed', value: formatNumber(data.placed), hue: 'teal' },
    { label: 'Offers', value: formatNumber(data.offers), hue: 'sky' },
    { label: 'Highest', value: lpa(data.highest), hue: 'amber' },
    { label: 'Average', value: lpa(data.average), hue: 'violet' },
  ];
  // Top six programs keep every bar label readable (Chart.js skips labels when bars get too thin).
  const programs = useMemo(() => data.programs.slice(0, 6), [data.programs]);
  const series = useMemo(() => [{ label: 'Students placed', data: programs.map((p) => p.placed), color: C.teal }], [programs]);
  return (
    <Panel
      title="Placement Statistics"
      subtitle={`${data.scope === 'session' ? 'Offers this session' : 'All placement seasons'}${data.companies ? ` · ${formatNumber(data.companies)} compan${data.companies === 1 ? 'y' : 'ies'}` : ''}`}
      icon={BriefcaseBusiness}
      hue="teal"
      action={<ViewAll to="/placement" label="Placement" />}
    >
      {data.offers === 0 ? (
        <PanelEmpty
          icon={BriefcaseBusiness}
          title="No placement offers recorded"
          description={data.drives ? `${formatNumber(data.drives)} drive${data.drives === 1 ? '' : 's'} planned — offers appear here as results come in.` : 'Plan a campus drive to start tracking offers and packages.'}
          action={can('placement', 'create') ? <Button size="sm" to="/placement/drives?add=1">Plan a drive</Button> : undefined}
        />
      ) : (
        <>
          <dl className="mb-4 grid grid-cols-4 gap-2">
            {stats.map((s) => (
              <div key={s.label} className={clsx('min-w-0 rounded-xl px-1.5 py-2 text-center', HUES[s.hue].tint, HUES[s.hue].ring)}>
                <dt className={clsx('truncate text-[11px] font-semibold', HUES[s.hue].text)}>{s.label}</dt>
                <dd className="whitespace-nowrap font-display text-sm font-bold text-slate-900 dark:text-white">{s.value}</dd>
              </div>
            ))}
          </dl>
          {programs.length > 0 && (
            <GradientBarChart height={Math.max(160, programs.length * 32 + 30)} horizontal legend={false} labels={programs.map((p) => p.label)} series={series} />
          )}
        </>
      )}
    </Panel>
  );
}
