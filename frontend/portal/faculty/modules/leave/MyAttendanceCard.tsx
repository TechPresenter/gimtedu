import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { CalendarCheck2 } from 'lucide-react';
import { ErrorState, Section } from '../../../components/Ui';
import { usePortalQuery } from '../../../lib/hooks';
import { hhmm } from '../../../lib/time';
import type { MyAttendanceData } from '../../shared/types';
import { Stepper, todayIso } from '../../shared/ui';

const STATUS: Record<string, { label: string; cls: string; dot: string }> = {
  present: { label: 'Present', cls: 'bg-emerald-50 text-emerald-800 ring-emerald-200 dark:bg-emerald-500/15 dark:text-emerald-200 dark:ring-emerald-500/30', dot: 'bg-emerald-500' },
  late: { label: 'Late', cls: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/15 dark:text-amber-200 dark:ring-amber-500/30', dot: 'bg-amber-500' },
  half_day: { label: 'Half day', cls: 'bg-violet-50 text-violet-800 ring-violet-200 dark:bg-violet-500/15 dark:text-violet-200 dark:ring-violet-500/30', dot: 'bg-violet-500' },
  absent: { label: 'Absent', cls: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/15 dark:text-red-200 dark:ring-red-500/30', dot: 'bg-red-500' },
  leave: { label: 'On leave', cls: 'bg-sky-50 text-sky-800 ring-sky-200 dark:bg-sky-500/15 dark:text-sky-200 dark:ring-sky-500/30', dot: 'bg-sky-500' },
};

const monthLabel = (ym: string) => new Date(`${ym}-01T00:00:00`).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });

/** The faculty member's own daily attendance (HR registers) as a month calendar. */
export function MyAttendanceCard() {
  const [month, setMonth] = useState(todayIso().slice(0, 7));
  const { data, isLoading, error, refetch, isFetching } = usePortalQuery<MyAttendanceData>('faculty', ['attendance', 'me'], 'attendance/me', { month });

  const cells = useMemo(() => {
    if (!data) return [];
    const first = new Date(`${data.start}T00:00:00`);
    const days = new Date(first.getFullYear(), first.getMonth() + 1, 0).getDate();
    const lead = (first.getDay() + 6) % 7; // Monday first
    const out: ({ date: string; day: number; dow: number } | null)[] = Array.from({ length: lead }, () => null);
    for (let d = 1; d <= days; d++) {
      const date = `${data.month}-${String(d).padStart(2, '0')}`;
      out.push({ date, day: d, dow: new Date(`${date}T00:00:00`).getDay() });
    }
    return out;
  }, [data]);
  const today = todayIso();

  return (
    <Section
      title="My attendance"
      subtitle="Recorded by the HR office"
      icon={CalendarCheck2}
      action={<Stepper label={monthLabel(month)} onPrev={() => data && setMonth(data.prev)} onNext={() => data?.next && setMonth(data.next)} nextDisabled={!data?.next} prevLabel="Previous month" nextLabel="Next month" />}
    >
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="skeleton h-72 rounded-2xl" />
      ) : (
        <div className={clsx('space-y-4 transition-opacity', isFetching && 'opacity-70')}>
          <div className="grid grid-cols-3 gap-2">
            {[
              ['Attendance', data.summary.percent !== null ? `${data.summary.percent}%` : '—', 'text-brand-900 dark:text-white'],
              ['Present', data.summary.present, 'text-emerald-700 dark:text-emerald-300'],
              ['Late', data.summary.late, 'text-amber-700 dark:text-amber-300'],
              ['Half day', data.summary.half_day, 'text-violet-700 dark:text-violet-300'],
              ['Absent', data.summary.absent, 'text-red-600 dark:text-red-300'],
              ['Leave', data.summary.leave, 'text-sky-700 dark:text-sky-300'],
            ].map(([label, value, cls]) => (
              <div key={String(label)} className="rounded-xl bg-slate-50 px-2 py-2 text-center dark:bg-slate-800/50">
                <p className="text-[10.5px] font-semibold uppercase tracking-wide text-slate-400">{label}</p>
                <p className={clsx('font-display text-lg font-extrabold', cls)}>{value}</p>
              </div>
            ))}
          </div>

          <div>
            <div className="grid grid-cols-7 gap-1 text-center text-[10.5px] font-bold uppercase tracking-wide text-slate-400">
              {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => (
                <span key={d}>{d}</span>
              ))}
            </div>
            <div className="mt-1 grid grid-cols-7 gap-1">
              {cells.map((c, i) => {
                if (!c) return <span key={`b${i}`} />;
                const rec = data.days[c.date];
                const leave = data.leave_days[c.date];
                const holiday = data.holidays[c.date];
                const off = data.weekly_offs.includes(c.dow);
                const meta = rec ? STATUS[rec.status] : leave ? STATUS.leave : null;
                const title = [c.date, rec ? STATUS[rec.status]?.label : leave ? 'On leave' : holiday ?? (off ? 'Weekly off' : c.date > today ? '' : 'Not recorded'), rec?.in_time ? `In ${hhmm(rec.in_time)}` : '', rec?.out_time ? `Out ${hhmm(rec.out_time)}` : '', rec?.remarks ?? '']
                  .filter(Boolean)
                  .join(' · ');
                return (
                  <div
                    key={c.date}
                    title={title}
                    className={clsx(
                      'flex aspect-square flex-col items-center justify-center rounded-lg text-xs font-semibold ring-1 ring-inset sm:aspect-auto sm:h-12',
                      meta ? meta.cls : holiday ? 'bg-slate-100 text-slate-400 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700' : off ? 'bg-[repeating-linear-gradient(135deg,transparent,transparent_4px,rgba(156,151,143,.12)_4px,rgba(156,151,143,.12)_8px)] text-slate-400 ring-slate-100 dark:ring-slate-800' : 'text-slate-500 ring-slate-100 dark:ring-slate-800',
                      c.date === today && 'ring-2 ring-flame-500',
                    )}
                  >
                    <span>{c.day}</span>
                    {rec?.in_time && <span className="hidden text-[9.5px] font-medium opacity-75 sm:block">{hhmm(rec.in_time)}</span>}
                    {!rec && holiday && <span className="hidden max-w-full truncate px-0.5 text-[9px] font-medium sm:block">Holiday</span>}
                  </div>
                );
              })}
            </div>
            <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-slate-500 dark:text-slate-400">
              {Object.entries(STATUS).map(([k, v]) => (
                <span key={k} className="inline-flex items-center gap-1.5">
                  <span className={clsx('h-2 w-2 rounded-full', v.dot)} /> {v.label}
                </span>
              ))}
            </div>
          </div>

          {data.trend.some((t) => t.percent !== null) && (
            <div>
              <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Last 6 months</p>
              <div className="flex h-24 items-end gap-2">
                {data.trend.map((t) => (
                  <div key={t.month} className="flex flex-1 flex-col items-center gap-1">
                    <span className="text-[10px] font-semibold tabular-nums text-slate-500">{t.percent !== null ? `${Math.round(t.percent)}%` : ''}</span>
                    <div className="flex h-14 w-full items-end rounded-md bg-slate-100 dark:bg-slate-800">
                      <div className={clsx('w-full rounded-md', t.percent !== null && t.percent < 75 ? 'bg-red-400' : 'bg-brand-700 dark:bg-brand-400')} style={{ height: `${t.percent ?? 0}%` }} />
                    </div>
                    <span className="text-[10px] text-slate-400">{t.label.split(' ')[0]}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>
      )}
    </Section>
  );
}
