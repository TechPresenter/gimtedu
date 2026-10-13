import { useMemo, useState } from 'react';
import { Award, BriefcaseBusiness, Building2, CalendarCheck, ChartPie, IndianRupee, TrendingUp, UserPlus, VenusAndMars } from 'lucide-react';
import { BarChart, DoughnutChart, LineChart, chartColor } from '@/components/charts';
import { Button } from '@/components/ui';
import { formatMoneyShort, formatNumber, formatPercent } from '@/lib/format';
import { Panel, PanelEmpty, PeriodSelect, ViewAll } from './Panel';
import type { DashboardCharts } from './types';

type Can = (module: string, action?: 'view' | 'create') => boolean;

/* ------------------------------------------------------------ Admissions trend */
export function AdmissionsTrendCard({ data, can }: { data: NonNullable<DashboardCharts['admissions_trend']>; can: Can }) {
  const [months, setMonths] = useState<'6' | '12'>('6');
  const rows = data.slice(-Number(months));
  const apps = rows.reduce((a, r) => a + r.applications, 0);
  const adm = rows.reduce((a, r) => a + r.admissions, 0);
  return (
    <Panel
      title="Admissions Trend"
      subtitle={`${formatNumber(apps)} applications · ${formatNumber(adm)} confirmed`}
      icon={UserPlus}
      action={<PeriodSelect label="Admissions trend period" value={months} onChange={setMonths} options={[{ value: '6', label: 'Last 6 Months' }, { value: '12', label: 'Last 12 Months' }]} />}
    >
      {apps + adm === 0 ? (
        <PanelEmpty icon={UserPlus} title="No applications in this period" description="New online and counter applications will be charted here." action={can('admissions', 'create') ? <Button size="sm" to="/admissions/new">New application</Button> : undefined} />
      ) : (
        <BarChart
          height={230}
          labels={rows.map((r) => r.label)}
          series={[
            { label: 'Applications', data: rows.map((r) => r.applications), color: '#1D4ED8' },
            { label: 'Admissions', data: rows.map((r) => r.admissions), color: '#22943F' },
          ]}
        />
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Fee collection */
export function FeeCollectionCard({ data, can }: { data: NonNullable<DashboardCharts['fee_collection']>; can: Can }) {
  const [mode, setMode] = useState<'cumulative' | 'monthly'>('cumulative');
  const series = useMemo(() => {
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
  return (
    <Panel
      title="Fee Collection"
      subtitle={`${formatMoneyShort(data.collected)} collected${rate !== null ? ` · ${formatPercent(rate)} of ${formatMoneyShort(data.billed)} billed` : ''}`}
      icon={IndianRupee}
      action={<PeriodSelect label="Fee collection view" value={mode} onChange={setMode} options={[{ value: 'cumulative', label: 'This Session' }, { value: 'monthly', label: 'Month by month' }]} />}
    >
      {data.collected === 0 && !hasTarget ? (
        <PanelEmpty icon={IndianRupee} title="No fee activity yet" description="Collections and installment targets for this session will appear here." action={can('fees', 'create') ? <Button size="sm" to="/fees/collect">Collect fees</Button> : undefined} />
      ) : (
        <LineChart
          height={230}
          money
          labels={series.map((m) => m.label)}
          series={[
            { label: 'Collection Amount', data: series.map((m) => m.collected), color: '#1D4ED8', fill: true },
            ...(hasTarget ? [{ label: 'Target', data: series.map((m) => m.target), color: '#22943F', dashed: true }] : []),
          ]}
        />
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
      action={<PeriodSelect label="Attendance period" value={period} onChange={setPeriod} options={[{ value: 'day', label: data.day.label === 'Today' ? 'Today' : 'Last marked day' }, { value: 'week', label: 'This Week' }, { value: 'month', label: 'Current Month' }]} />}
    >
      {a.total === 0 ? (
        <PanelEmpty icon={CalendarCheck} title="No attendance marked" action={can('attendance', 'create') ? <Button size="sm" to="/attendance?tab=mark">Mark attendance</Button> : undefined} />
      ) : (
        <div className="mx-auto w-full max-w-md">
          <DoughnutChart
            height={170}
            labels={['Present', 'Absent', 'Leave']}
            data={[present, a.absent, a.leave]}
            colors={['#22943F', '#F43F5E', '#F59E0B']}
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
      action={<PeriodSelect label="Group programs by" value={by} onChange={setBy} options={[{ value: 'category', label: 'All Programs' }, { value: 'level', label: 'By Level' }]} />}
    >
      {total === 0 ? (
        <PanelEmpty icon={ChartPie} title="No active students yet" />
      ) : (
        <div className="mx-auto w-full max-w-md">
          <DoughnutChart height={170} labels={rows.map((r) => r.label)} data={rows.map((r) => r.value)} centerValue={formatNumber(total)} centerLabel="Students" />
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
  return (
    <Panel
      title="Student Growth"
      subtitle={growth !== null ? `${formatNumber(last)} enrolled · ${growth >= 0 ? '+' : ''}${formatPercent(growth)} vs ${prev?.label}` : 'Enrolment across academic sessions'}
      icon={TrendingUp}
      action={<ViewAll to="/reports" label="Reports" />}
    >
      {data.length === 0 || last === 0 ? (
        <PanelEmpty icon={TrendingUp} title="No enrolment history yet" />
      ) : (
        <LineChart
          height={240}
          labels={data.map((d) => d.label)}
          series={[
            { label: 'Total enrolled', data: data.map((d) => d.total), color: '#1D4ED8', fill: true },
            { label: 'New admissions', data: data.map((d) => d.new), color: '#22943F' },
          ]}
        />
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Gender */
export function GenderCard({ data }: { data: NonNullable<DashboardCharts['gender']> }) {
  const total = data.reduce((a, r) => a + r.value, 0);
  const palette: Record<string, string> = { Male: '#1D4ED8', Female: '#F43F5E', Other: '#8B5CF6', Unspecified: '#94A3B8' };
  return (
    <Panel title="Gender Distribution" subtitle="Active students" icon={VenusAndMars}>
      {total === 0 ? (
        <PanelEmpty icon={VenusAndMars} title="No active students yet" />
      ) : (
        <div className="mx-auto w-full max-w-md">
          <DoughnutChart height={170} labels={data.map((d) => d.label)} data={data.map((d) => d.value)} colors={data.map((d, i) => palette[d.label] ?? chartColor(i + 2))} centerValue={formatNumber(total)} centerLabel="Students" />
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
    <Panel title="Department-wise Students" subtitle={`${formatNumber(total)} active students`} icon={Building2} action={<ViewAll to="/academics?tab=departments" label="Departments" />}>
      {data.length === 0 ? (
        <PanelEmpty icon={Building2} title="No department data yet" />
      ) : (
        <ul className="space-y-3">
          {data.map((d, i) => (
            <li key={d.code || d.label}>
              <div className="flex items-baseline justify-between gap-3 text-[13px]">
                <span className="truncate font-medium text-slate-700 dark:text-slate-200" title={d.label}>
                  {d.label}
                </span>
                <span className="shrink-0 tabular-nums text-slate-500 dark:text-slate-400">
                  <span className="font-semibold text-slate-900 dark:text-white">{formatNumber(d.value)}</span> · {formatPercent((d.value / Math.max(1, total)) * 100)}
                </span>
              </div>
              <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" aria-label={`${d.label}: ${d.value} students`}>
                <div className="h-full rounded-full transition-[width] duration-700 ease-out motion-reduce:transition-none" style={{ width: `${(d.value / max) * 100}%`, background: chartColor(i) }} />
              </div>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Exam performance */
export function ExamPerformanceCard({ data, can }: { data: NonNullable<DashboardCharts['exam_performance']>; can: Can }) {
  return (
    <Panel
      title="Examination Performance"
      subtitle={data.students ? `${formatPercent(data.pass_percent ?? 0, 1)} pass rate · ${formatNumber(data.students)} results${data.scope === 'computed' ? ' (unpublished)' : ''}` : 'Program-wise results this session'}
      icon={Award}
      action={can('results') ? <ViewAll to="/results" label="Results" /> : undefined}
    >
      {data.programs.length === 0 ? (
        <PanelEmpty icon={Award} title="No results this session" description="Pass percentage and average score per program appear once results are computed." action={can('examination') ? <Button size="sm" variant="secondary" to="/examination">View exams</Button> : undefined} />
      ) : (
        <BarChart
          percent
          height={Math.max(220, data.programs.length * 30)}
          labels={data.programs.map((p) => p.label)}
          series={[
            { label: 'Pass %', data: data.programs.map((p) => p.pass_percent), color: '#22943F' },
            { label: 'Average %', data: data.programs.map((p) => p.avg_percent), color: '#1D4ED8' },
          ]}
        />
      )}
    </Panel>
  );
}

/* ------------------------------------------------------------ Placement */
export function PlacementCard({ data, can }: { data: NonNullable<DashboardCharts['placement']>; can: Can }) {
  const lpa = (v: number | null) => (v === null ? '—' : <>{v.toFixed(v % 1 ? 1 : 0)}<span className="ml-0.5 text-[10px] font-semibold text-slate-500 dark:text-slate-400">LPA</span></>);
  const stats = [
    { label: 'Placed', value: formatNumber(data.placed) },
    { label: 'Offers', value: formatNumber(data.offers) },
    { label: 'Highest', value: lpa(data.highest) },
    { label: 'Average', value: lpa(data.average) },
  ];
  // Top six programs keep every bar label readable (Chart.js skips labels when bars get too thin).
  const programs = data.programs.slice(0, 6);
  return (
    <Panel
      title="Placement Statistics"
      subtitle={`${data.scope === 'session' ? 'Offers this session' : 'All placement seasons'}${data.companies ? ` · ${formatNumber(data.companies)} compan${data.companies === 1 ? 'y' : 'ies'}` : ''}`}
      icon={BriefcaseBusiness}
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
              <div key={s.label} className="min-w-0 rounded-xl bg-slate-50 px-1.5 py-2 text-center dark:bg-slate-800/60">
                <dt className="truncate text-[11px] font-medium text-slate-500 dark:text-slate-400">{s.label}</dt>
                <dd className="whitespace-nowrap font-display text-sm font-bold text-slate-900 dark:text-white">{s.value}</dd>
              </div>
            ))}
          </dl>
          {programs.length > 0 && (
            <BarChart height={Math.max(160, programs.length * 32 + 40)} horizontal labels={programs.map((p) => p.label)} series={[{ label: 'Students placed', data: programs.map((p) => p.placed), color: '#0891B2' }]} />
          )}
        </>
      )}
    </Panel>
  );
}

