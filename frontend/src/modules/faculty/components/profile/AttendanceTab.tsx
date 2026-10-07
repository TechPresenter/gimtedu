import { useState } from 'react';
import clsx from 'clsx';
import { CalendarCheck, ChevronLeft, ChevronRight, ExternalLink, Percent, UserCheck, UserMinus, UserX, Clock3 } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, IconButton, Input, Reveal, Skeleton, StatTile, Stagger } from '@/components/ui';
import { BarChart } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { formatTime, isoDate } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { currentMonth, dow, monthGrid, monthLabel, shiftMonth, WEEKDAYS, type EmployeeType } from '../../hr';

interface DayRec {
  status: string;
  in_time: string | null;
  out_time: string | null;
  remarks: string | null;
}
interface Payload {
  month: string;
  days: Record<string, DayRec>;
  leave_days: Record<string, string>;
  holidays: Record<string, string>;
  weekly_offs: number[];
  summary: { present: number; absent: number; late: number; leave: number; half_day: number; marked: number; percent: number | null };
  trend: { month: string; label: string; percent: number | null; marked: number }[];
  has_any: boolean;
}

const statusCell: Record<string, string> = {
  present: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-200 dark:ring-emerald-500/20',
  late: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-200 dark:ring-amber-500/20',
  half_day: 'bg-orange-50 text-orange-800 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-200 dark:ring-orange-500/20',
  absent: 'bg-red-50 text-red-800 ring-red-200 dark:bg-red-500/10 dark:text-red-200 dark:ring-red-500/20',
  leave: 'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-200 dark:ring-violet-500/20',
};
const statusLabel: Record<string, string> = { present: 'Present', late: 'Late', half_day: 'Half day', absent: 'Absent', leave: 'On leave' };

/** Monthly attendance calendar of an employee (records come from Attendance › Faculty & Staff Attendance). */
export default function AttendanceTab({ type, id }: { type: EmployeeType; id: number }) {
  const [month, setMonth] = useState(currentMonth());
  const { data, isLoading, error, isFetching } = useApi<Payload>(['hr-attendance', type, id, month], `${type}/${id}/attendance`, { month }, { placeholderData: (prev) => prev });
  const today = isoDate();
  if (error) return <Alert variant="error">{(error as ApiError).message}</Alert>;
  const s = data?.summary;
  return (
    <div className="space-y-4">
      <Stagger className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        <StatTile label="Attendance" value={s?.percent !== null && s?.percent !== undefined ? `${s.percent}%` : '—'} icon={Percent} tone="navy" loading={isLoading} sub={monthLabel(month)} />
        <StatTile label="Present" value={s?.present ?? 0} icon={UserCheck} tone="green" loading={isLoading} sub={`${s?.late ?? 0} late arrivals`} />
        <StatTile label="Absent" value={s?.absent ?? 0} icon={UserX} tone="red" loading={isLoading} sub="Without leave" />
        <StatTile label="Half days" value={s?.half_day ?? 0} icon={Clock3} tone="orange" loading={isLoading} sub="Counted as ½ present" />
        <StatTile label="On leave" value={s?.leave ?? 0} icon={UserMinus} tone="purple" loading={isLoading} sub="Marked as leave" />
      </Stagger>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card>
            <CardHeader
              title="Monthly calendar"
              subtitle={data && !data.has_any ? 'No attendance recorded yet' : `${s?.marked ?? 0} days marked`}
              icon={CalendarCheck}
              actions={
                <div className="flex items-center gap-1">
                  <IconButton size="sm" icon={ChevronLeft} label="Previous month" onClick={() => setMonth((m) => shiftMonth(m, -1))} />
                  <Input inputSize="sm" type="month" value={month} max={currentMonth()} onChange={(e) => e.target.value && setMonth(e.target.value)} aria-label="Month" className="!w-36" />
                  <IconButton size="sm" icon={ChevronRight} label="Next month" onClick={() => setMonth((m) => shiftMonth(m, 1))} disabled={month >= currentMonth()} />
                </div>
              }
            />
            <div className={clsx('p-4 transition-opacity', isFetching && 'opacity-60')}>
              {isLoading ? (
                <Skeleton className="h-72 !rounded-xl" />
              ) : data && !data.has_any ? (
                <EmptyState
                  icon={CalendarCheck}
                  title="No attendance records"
                  description="Daily attendance for faculty and staff is marked from Attendance › Faculty & Staff Attendance."
                  action={<Button to="/attendance/employees" icon={ExternalLink}>Mark attendance</Button>}
                />
              ) : (
                <>
                  <div className="grid grid-cols-7 gap-1 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                    {WEEKDAYS.map((d) => <div key={d} className="py-1">{d}</div>)}
                  </div>
                  <div className="grid grid-cols-7 gap-1">
                    {monthGrid(month).map((d, i) => {
                      if (!d) return <div key={`p${i}`} />;
                      const rec = data?.days[d];
                      const holiday = data?.holidays[d];
                      const off = data?.weekly_offs.includes(dow(d));
                      const leave = !rec && data?.leave_days[d];
                      const st = rec?.status ?? (leave ? 'leave' : null);
                      return (
                        <div
                          key={d}
                          title={[holiday, rec ? `${statusLabel[rec.status] ?? rec.status}${rec.in_time ? ` · ${formatTime(rec.in_time)}–${formatTime(rec.out_time)}` : ''}` : leave ? 'Approved leave' : off ? 'Weekly off' : ''].filter(Boolean).join(' · ') || undefined}
                          className={clsx(
                            'flex min-h-[3.25rem] flex-col rounded-lg p-1.5 text-left ring-1 ring-inset sm:min-h-[4rem]',
                            st ? statusCell[st] ?? 'bg-slate-50 ring-slate-200' : holiday || off ? 'bg-slate-50 text-slate-400 ring-slate-100 dark:bg-slate-800/40 dark:ring-slate-800' : 'text-slate-600 ring-slate-100 dark:text-slate-300 dark:ring-slate-800',
                            d === today && 'outline outline-2 outline-offset-1 outline-brand-500',
                          )}
                        >
                          <span className="text-xs font-semibold">{Number(d.slice(8))}</span>
                          <span className="mt-auto hidden truncate text-[10px] font-medium sm:block">
                            {st ? statusLabel[st] ?? st : holiday ? holiday : off ? 'Off' : ''}
                          </span>
                          {rec?.in_time && <span className="hidden truncate text-[10px] opacity-75 lg:block">{formatTime(rec.in_time)}</span>}
                        </div>
                      );
                    })}
                  </div>
                  <div className="mt-4 flex flex-wrap gap-3 text-xs text-slate-500">
                    {Object.entries(statusLabel).map(([k, v]) => (
                      <span key={k} className="inline-flex items-center gap-1.5"><span className={clsx('h-3 w-3 rounded ring-1 ring-inset', statusCell[k])} /> {v}</span>
                    ))}
                    <span className="inline-flex items-center gap-1.5"><span className="h-3 w-3 rounded bg-slate-100 dark:bg-slate-800" /> Holiday / weekly off</span>
                  </div>
                </>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Six-month trend" subtitle="Attendance % by month" />
            <div className="p-4">
              {isLoading ? (
                <Skeleton className="h-56" />
              ) : (
                <BarChart labels={(data?.trend ?? []).map((t) => t.label)} series={[{ label: 'Attendance %', data: (data?.trend ?? []).map((t) => t.percent ?? 0), color: '#22943F' }]} percent height={230} legend={false} />
              )}
            </div>
          </Card>
        </Reveal>
      </div>
    </div>
  );
}
