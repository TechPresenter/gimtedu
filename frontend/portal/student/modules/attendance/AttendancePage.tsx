import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { AlertTriangle, CalendarCheck, CalendarDays, CalendarX2, ChevronLeft, ChevronRight, CircleCheck, Clock, ListChecks, TrendingUp } from 'lucide-react';
import { Alert, Stagger } from '@/components/ui';
import { formatDate, formatNumber, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead, Ring, Section } from '../../../components/Ui';
import { hhmm, longDate } from '../../../lib/time';
import { Bar, ListSkeleton, MiniStat, StatusBadge, pctTone, useStudent } from '../../shared/ui';
import type { AttDay, DayStatus, StudentAttendance } from '../../shared/types';

const CELL: Record<DayStatus, { cls: string; mark: string; label: string }> = {
  present: { cls: 'bg-emerald-500 text-white', mark: 'P', label: 'Present' },
  late: { cls: 'bg-amber-400 text-amber-950', mark: 'L', label: 'Late' },
  absent: { cls: 'bg-red-500 text-white', mark: 'A', label: 'Absent' },
  leave: { cls: 'bg-sky-500 text-white', mark: 'LV', label: 'On leave' },
  partial: { cls: 'bg-gradient-to-br from-emerald-500 to-red-500 text-white', mark: '½', label: 'Partly present' },
  holiday: { cls: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-200', mark: 'H', label: 'Holiday' },
  off: { cls: 'bg-slate-100 text-slate-400 dark:bg-slate-800 dark:text-slate-500', mark: '', label: 'Weekly off' },
  future: { cls: 'bg-transparent text-slate-300 dark:text-slate-600', mark: '', label: 'Upcoming' },
  none: { cls: 'border border-dashed border-slate-200 text-slate-400 dark:border-slate-700', mark: '', label: 'No class marked' },
};

function Calendar({ month, selected, onSelect }: { month: StudentAttendance['month']; selected: string | null; onSelect: (d: string) => void }) {
  const lead = (month.first_dow + 6) % 7; // Monday-first grid
  return (
    <div>
      <div className="grid grid-cols-7 gap-1.5 text-center text-[10px] font-bold uppercase tracking-wider text-slate-400 sm:gap-2">
        {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => (
          <span key={d}>{d}</span>
        ))}
      </div>
      <div className="mt-2 grid grid-cols-7 gap-1.5 sm:gap-2" role="grid" aria-label={`Attendance for ${month.label}`}>
        {Array.from({ length: lead }).map((_, i) => (
          <span key={`l${i}`} aria-hidden />
        ))}
        {month.days.map((d) => {
          const c = CELL[d.status];
          const clickable = d.total > 0 || d.status === 'holiday';
          const day = Number(d.date.slice(8, 10));
          return (
            <button
              key={d.date}
              type="button"
              disabled={!clickable}
              onClick={() => onSelect(d.date)}
              title={`${formatDate(d.date)} · ${d.holiday ?? c.label}${d.total ? ` (${d.total} classes)` : ''}`}
              aria-label={`${formatDate(d.date)}: ${d.holiday ?? c.label}`}
              aria-pressed={selected === d.date}
              className={clsx(
                'relative flex h-11 flex-col items-center justify-center rounded-xl text-[13px] font-bold transition sm:h-12',
                c.cls,
                clickable && 'hover:scale-[1.04] focus-visible:scale-[1.04]',
                selected === d.date && 'ring-2 ring-brand-900 ring-offset-2 ring-offset-white dark:ring-white dark:ring-offset-slate-900',
              )}
            >
              <span className="leading-none">{day}</span>
              {c.mark && <span className="mt-1 text-[9px] font-extrabold leading-none tracking-wide opacity-80">{c.mark}</span>}
            </button>
          );
        })}
      </div>
      <ul className="mt-4 flex flex-wrap gap-x-4 gap-y-2 text-[11px] text-slate-500 dark:text-slate-400">
        {(['present', 'late', 'absent', 'leave', 'partial', 'holiday', 'off'] as DayStatus[]).map((k) => (
          <li key={k} className="flex items-center gap-1.5">
            <span className={clsx('inline-block h-3 w-3 rounded', CELL[k].cls)} aria-hidden /> {CELL[k].label}
          </li>
        ))}
      </ul>
    </div>
  );
}

function DayDetail({ day }: { day: AttDay | null }) {
  if (!day) return <p className="py-6 text-center text-sm text-slate-500">Tap a marked day to see each class.</p>;
  if (!day.records.length) {
    return <EmptyBlock compact icon={CalendarX2} title={day.holiday ?? 'No classes marked'} text={longDate(day.date)} />;
  }
  return (
    <div>
      <p className="mb-2 text-xs font-semibold text-slate-500 dark:text-slate-400">{longDate(day.date)}</p>
      <ul className="divide-y divide-slate-100 dark:divide-slate-800">
        {day.records.map((r, i) => (
          <li key={i} className="flex items-center gap-3 py-2.5">
            <span className="w-16 shrink-0 text-xs font-semibold tabular-nums text-slate-500">{r.start_time ? hhmm(r.start_time) : r.slot ?? '—'}</span>
            <div className="min-w-0 flex-1">
              <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.subject ?? 'Class'}</p>
              <p className="text-[11px] text-slate-400">{r.subject_code}</p>
            </div>
            <StatusBadge status={r.status} />
          </li>
        ))}
      </ul>
    </div>
  );
}

export default function StudentAttendancePage() {
  const [month, setMonth] = useState('');
  const { data, isLoading, isFetching, error, refetch } = useStudent<StudentAttendance>(['attendance'], 'attendance', month ? { month } : undefined);
  const [selected, setSelected] = useState<string | null>(null);

  // default selection: today / last marked day of the shown month
  useEffect(() => {
    if (!data) return;
    const marked = data.month.days.filter((d) => d.total > 0);
    setSelected((cur) => (cur && cur.startsWith(data.month.value) ? cur : marked[marked.length - 1]?.date ?? null));
  }, [data]);

  const idx = data ? data.months.findIndex((m) => m.value === data.month.value) : -1;
  const go = (step: number) => {
    if (!data) return;
    const m = data.months[idx + step];
    if (m) setMonth(m.value);
  };
  const o = data?.overall;
  const tone = o ? pctTone(o.percent, data!.threshold) : 'amber';
  const selectedDay = useMemo(() => data?.month.days.find((d) => d.date === selected) ?? null, [data, selected]);
  const maxTrend = Math.max(100, ...(data?.trend.map((t) => t.percent ?? 0) ?? [0]));

  return (
    <div>
      <PageHead
        area="student"
        title="Attendance"
        subtitle={data ? `Session ${data.session ?? ''} · minimum ${data.threshold}% required${data.late_counts_as_present ? ' · late counts as present' : ''}` : 'Your class attendance this session.'}
        crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Attendance' }]}
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data || !o ? (
        <div className="space-y-5">
          <div className="skeleton h-48 rounded-3xl" />
          <ListSkeleton rows={5} />
        </div>
      ) : o.held === 0 ? (
        <div className="card">
          <EmptyBlock icon={CalendarCheck} title="No attendance recorded yet" text="Your attendance appears here as soon as faculty start marking your classes this session." />
        </div>
      ) : (
        <div className="space-y-5">
          <section className="card overflow-hidden">
            <div className="grid grid-cols-1 gap-6 p-5 sm:p-6 lg:grid-cols-[auto_1fr] lg:items-center">
              <div className="flex items-center gap-5">
                <Ring value={o.percent ?? 0} size={132} stroke={12} tone={tone} label={`Overall attendance ${o.percent}%`}>
                  <span>
                    <span className="block font-display text-[30px] font-extrabold leading-none text-slate-900 dark:text-white">{o.percent}%</span>
                    <span className="mt-1 block text-[10px] font-semibold uppercase tracking-wider text-slate-400">Overall</span>
                  </span>
                </Ring>
                <div className="lg:hidden">
                  <p className="font-display text-lg font-bold text-slate-900 dark:text-white">{formatNumber(o.attended, 1).replace(/\.0$/, '')} / {o.held}</p>
                  <p className="text-xs text-slate-500">classes attended</p>
                </div>
              </div>
              <div className="space-y-4">
                <Stagger className="grid grid-cols-2 gap-2.5 sm:grid-cols-4" itemClassName="h-full">
                  {[
                    <MiniStat key="h" label="Classes held" value={o.held} />,
                    <MiniStat key="p" label="Present" value={o.present} tone="green" sub={o.late ? `+${o.late} late` : undefined} />,
                    <MiniStat key="a" label="Absent" value={o.absent} tone={o.absent ? 'red' : 'default'} />,
                    <MiniStat key="l" label="On leave" value={o.leave} tone="navy" />,
                  ]}
                </Stagger>
                {o.percent !== null && o.percent < data.threshold ? (
                  <Alert variant="error" title={`Below the ${data.threshold}% minimum`}>
                    Attend the next <strong>{o.needed}</strong> class{o.needed === 1 ? '' : 'es'} without a break to get back to {data.threshold}%. Students below the minimum may not be allowed to sit the end-semester examination.
                  </Alert>
                ) : (
                  <Alert variant="success" title={`Above the ${data.threshold}% minimum`}>
                    {o.can_miss > 0 ? (
                      <>
                        You could miss up to <strong>{o.can_miss}</strong> more class{o.can_miss === 1 ? '' : 'es'} and still stay at or above {data.threshold}% — but every class counts.
                      </>
                    ) : (
                      <>You are right at the limit — don&apos;t miss the next class.</>
                    )}
                  </Alert>
                )}
              </div>
            </div>
          </section>

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-5">
            <Section title="Subject-wise attendance" subtitle={`Line marks the ${data.threshold}% minimum`} icon={ListChecks} className="xl:col-span-3">
              <ul className="space-y-4">
                {data.subjects.map((sub) => {
                  const t = pctTone(sub.percent, data.threshold);
                  return (
                    <li key={sub.id}>
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{sub.name}</p>
                          <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">
                            {sub.code}
                            {sub.faculty ? ` · ${sub.faculty}` : ''} · {formatNumber(sub.attended, 1).replace(/\.0$/, '')}/{sub.held} attended
                          </p>
                        </div>
                        <div className="shrink-0 text-right">
                          <p className={clsx('font-display text-base font-extrabold tabular-nums', t === 'red' ? 'text-red-600 dark:text-red-400' : t === 'amber' ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400')}>
                            {sub.percent ?? '—'}%
                          </p>
                          <p className="text-[10px] font-semibold text-slate-400">{sub.needed > 0 ? `Need ${sub.needed} more` : `Can miss ${sub.can_miss}`}</p>
                        </div>
                      </div>
                      <Bar value={sub.percent ?? 0} tone={t} marker={data.threshold} className="mt-2" label={`${sub.name} ${sub.percent}%`} />
                    </li>
                  );
                })}
              </ul>
            </Section>

            <div className="space-y-5 xl:col-span-2">
              <Section title="Monthly trend" subtitle="Attendance % per month" icon={TrendingUp}>
                {data.trend.length === 0 ? (
                  <p className="text-sm text-slate-500">No months to show yet.</p>
                ) : (
                  <div className="flex h-40 items-end gap-2" role="img" aria-label="Monthly attendance trend">
                    {data.trend.map((t) => {
                      const tt = pctTone(t.percent, data.threshold);
                      return (
                        <div key={t.month} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
                          <span className="text-[10px] font-bold tabular-nums text-slate-600 dark:text-slate-300">{t.percent ?? '—'}%</span>
                          <div
                            className={clsx('w-full max-w-[38px] rounded-t-lg transition-[height] duration-500', tt === 'red' ? 'bg-red-400' : tt === 'amber' ? 'bg-amber-400' : 'bg-emerald-500')}
                            style={{ height: `${Math.max(4, ((t.percent ?? 0) / maxTrend) * 100)}%` }}
                          />
                          <span className="text-[10px] font-semibold uppercase text-slate-400">{t.label}</span>
                        </div>
                      );
                    })}
                  </div>
                )}
              </Section>
              <Section title="Recent absences" icon={AlertTriangle} bodyClassName="p-2 sm:p-3">
                {data.absences.length === 0 ? (
                  <EmptyBlock compact icon={CircleCheck} title="No absences this session" text="Keep it up!" />
                ) : (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.absences.map((a, i) => (
                      <li key={i} className="flex items-center gap-3 px-2 py-2.5">
                        <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300">
                          <CalendarX2 className="h-4 w-4" aria-hidden />
                        </span>
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{a.subject ?? 'Class'}</p>
                          <p className="text-[11px] text-slate-500">
                            {formatDate(a.date)}
                            {a.start_time ? ` · ${hhmm(a.start_time)}` : ''}
                          </p>
                        </div>
                        <span className="text-[11px] font-semibold text-slate-400">{a.subject_code}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </Section>
            </div>
          </div>

          <Section
            title="Calendar"
            subtitle={data.month.held ? `${data.month.label}: ${data.month.percent}% · ${data.month.held} classes` : data.month.label}
            icon={CalendarDays}
            action={
              <div className="flex items-center gap-1">
                <button type="button" className="btn-icon h-9 w-9" onClick={() => go(-1)} disabled={idx <= 0 || isFetching} aria-label="Previous month">
                  <ChevronLeft className="h-4 w-4" aria-hidden />
                </button>
                <span className="whitespace-nowrap px-1 text-sm font-semibold text-slate-800 dark:text-slate-100 sm:hidden">{data.months[idx]?.label ?? data.month.label}</span>
                <select value={data.month.value} onChange={(e) => setMonth(e.target.value)} className="form-input form-input-sm hidden w-auto py-1.5 pr-8 sm:block" aria-label="Month">
                  {data.months.map((m) => (
                    <option key={m.value} value={m.value}>
                      {m.label}
                    </option>
                  ))}
                </select>
                <button type="button" className="btn-icon h-9 w-9" onClick={() => go(1)} disabled={idx >= data.months.length - 1 || isFetching} aria-label="Next month">
                  <ChevronRight className="h-4 w-4" aria-hidden />
                </button>
              </div>
            }
          >
            <div className={clsx('grid grid-cols-1 gap-6 lg:grid-cols-5', isFetching && 'opacity-60 transition-opacity')}>
              <div className="lg:col-span-3">
                <Calendar month={data.month} selected={selected} onSelect={setSelected} />
              </div>
              <div className="rounded-2xl bg-slate-50 p-4 dark:bg-slate-800/40 lg:col-span-2">
                <p className="mb-1 flex items-center gap-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">
                  <Clock className="h-3.5 w-3.5" aria-hidden /> Day detail
                </p>
                <DayDetail day={selectedDay} />
                {selectedDay && selectedDay.total > 0 && (
                  <p className="mt-3 text-[11px] text-slate-400">
                    {Object.entries(selectedDay.counts)
                      .filter(([, n]) => n > 0)
                      .map(([k, n]) => `${n} ${labelize(k).toLowerCase()}`)
                      .join(' · ')}
                  </p>
                )}
              </div>
            </div>
          </Section>
        </div>
      )}
    </div>
  );
}
