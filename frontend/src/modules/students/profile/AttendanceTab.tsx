import { useState } from 'react';
import clsx from 'clsx';
import { CalendarCheck, CalendarX2, Clock, ListChecks, PieChart, Plane, TrendingUp } from 'lucide-react';
import { Card, CardBody, CardHeader, ProgressBar, Reveal, Select, StatusBadge, Stagger } from '@/components/ui';
import { BarChart, DoughnutChart } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { formatDate, formatNumber } from '@/lib/format';
import type { AttendanceStats } from '../types';
import { useAcademicTree } from '../hooks';
import { MiniStat, TabEmpty, TabError, TabLoading, type TabProps } from './shared';

interface AttendanceData {
  overall: AttendanceStats;
  subjects: { subject_id: number | null; code: string | null; name: string; total: number; attended: number; absent: number; leave: number; percent: number | null }[];
  monthly: { month: string; label: string; total: number; percent: number | null }[];
  recent: { attendance_date: string; status: string; remarks: string | null; subject_code: string | null; subject_name: string | null; slot_name: string | null }[];
}

export default function AttendanceTab({ studentId }: TabProps) {
  const [sessionId, setSessionId] = useState('');
  const { data: tree } = useAcademicTree();
  const q = useApi<AttendanceData>(['students', studentId, 'attendance', sessionId], `students/${studentId}/attendance`, { session_id: sessionId || undefined });
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const d = q.data!;
  const o = d.overall;
  const filter = (
    <Select inputSize="sm" value={sessionId} onChange={(e) => setSessionId(e.target.value)} aria-label="Academic session" className="!w-44"
      options={(tree?.sessions ?? []).map((s) => ({ value: s.id, label: s.name }))} placeholder="All sessions" />
  );
  if (!o.total) {
    return (
      <div className="space-y-4">
        <div className="flex justify-end">{filter}</div>
        <TabEmpty icon={CalendarCheck} title="No attendance recorded yet" description="Attendance appears here as soon as faculty mark the student's classes." />
      </div>
    );
  }
  const low = o.percent !== null && o.percent < o.threshold;
  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-500 dark:text-slate-400">
          Minimum required: <strong className="text-slate-800 dark:text-slate-100">{o.threshold}%</strong> · late arrivals count as present, half days as 0.5
        </p>
        {filter}
      </div>
      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-5" itemClassName="h-full" step={50}>
        {[
          <MiniStat key="p" label="Overall attendance" value={`${o.percent ?? 0}%`} icon={TrendingUp} tone={low ? 'red' : 'green'} sub={low ? `Below ${o.threshold}% — at risk` : `${formatNumber(o.attended)} of ${formatNumber(o.total)} classes`} />,
          <MiniStat key="pr" label="Present" value={formatNumber(o.present)} icon={CalendarCheck} tone="green" />,
          <MiniStat key="a" label="Absent" value={formatNumber(o.absent)} icon={CalendarX2} tone="red" />,
          <MiniStat key="l" label="Late" value={formatNumber(o.late)} icon={Clock} tone="amber" />,
          <MiniStat key="lv" label="Leave / half day" value={`${formatNumber(o.leave)} / ${formatNumber(o.half_day)}`} icon={Plane} tone="purple" />,
        ]}
      </Stagger>

      <div className="grid gap-5 lg:grid-cols-3 [&>*]:min-w-0">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Monthly attendance" subtitle="Percentage of classes attended" icon={TrendingUp} />
            <CardBody>
              <BarChart height={250} percent labels={d.monthly.map((m) => m.label)} series={[{ label: 'Attendance %', data: d.monthly.map((m) => m.percent ?? 0), color: '#22943F' }]} />
            </CardBody>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Breakdown" icon={PieChart} />
            <CardBody>
              <DoughnutChart height={170} centerValue={`${o.percent ?? 0}%`} centerLabel="attended" labels={['Present', 'Late', 'Absent', 'Leave', 'Half day']}
                data={[o.present, o.late, o.absent, o.leave, o.half_day]} colors={['#22943F', '#F59E0B', '#EF4444', '#8B5CF6', '#06B6D4']} valueFormat="number" />
            </CardBody>
          </Card>
        </Reveal>
      </div>

      <div className="grid gap-5 lg:grid-cols-5 [&>*]:min-w-0">
        <Reveal className="lg:col-span-3">
          <Card className="h-full">
            <CardHeader title="Subject-wise attendance" icon={ListChecks} subtitle={`${d.subjects.length} subject${d.subjects.length === 1 ? '' : 's'}`} />
            <CardBody className="space-y-4">
              {d.subjects.map((sub) => {
                const pct = sub.percent ?? 0;
                const bad = pct < o.threshold;
                return (
                  <div key={sub.subject_id ?? 'daily'}>
                    <div className="flex items-baseline justify-between gap-3 text-sm">
                      <p className="min-w-0 truncate">
                        {sub.code && <span className="mr-1.5 font-mono text-xs font-semibold text-slate-500">{sub.code}</span>}
                        <span className="font-medium text-slate-800 dark:text-slate-100">{sub.name}</span>
                      </p>
                      <p className={clsx('shrink-0 font-semibold tabular-nums', bad ? 'text-red-600 dark:text-red-400' : 'text-slate-900 dark:text-white')}>{pct.toFixed(1)}%</p>
                    </div>
                    <ProgressBar value={pct} tone={bad ? 'red' : pct < o.threshold + 10 ? 'amber' : 'green'} className="mt-1.5" label={`${sub.name} attendance`} />
                    <p className="mt-1 text-xs text-slate-500">{formatNumber(sub.attended)} / {formatNumber(sub.total)} classes · {sub.absent} absent{sub.leave ? ` · ${sub.leave} leave` : ''}</p>
                  </div>
                );
              })}
            </CardBody>
          </Card>
        </Reveal>
        <Reveal className="lg:col-span-2" delay={60}>
          <Card className="h-full">
            <CardHeader title="Recent records" icon={CalendarCheck} subtitle="Last 25 classes" />
            <ul className="max-h-[420px] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
              {d.recent.map((r, i) => (
                <li key={i} className="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                  <div className="min-w-0">
                    <p className="truncate font-medium text-slate-800 dark:text-slate-100">{r.subject_name ?? 'Daily attendance'}</p>
                    <p className="text-xs text-slate-500">{formatDate(r.attendance_date)}{r.slot_name ? ` · ${r.slot_name}` : ''}{r.remarks ? ` · ${r.remarks}` : ''}</p>
                  </div>
                  <StatusBadge status={r.status} />
                </li>
              ))}
            </ul>
          </Card>
        </Reveal>
      </div>
    </div>
  );
}
