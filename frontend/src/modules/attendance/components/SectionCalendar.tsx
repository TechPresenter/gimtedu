import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { CalendarDays, CalendarRange, Lock, PartyPopper } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, Field, Skeleton } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatDate, formatTime } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import type { CalendarDay, CalendarPayload } from '../types';
import { currentMonth, heatClass, HeatLegend, MiniStat, monthLabel, MonthNav, PercentBar, SectionPicker } from './shared';
import type { MarkSelection } from './MarkAttendance';

const DOW = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export function SectionCalendar({ section, month, onChange, onOpen }: { section: number | null; month: string; onChange: (p: { section?: number | null; month?: string }) => void; onOpen: (s: Partial<MarkSelection>) => void }) {
  const q = useApi<CalendarPayload>(['attendance', 'calendar', section, month], 'attendance/calendar', { section_id: section ?? undefined, month }, { enabled: !!section });
  const d = q.data;
  const [selected, setSelected] = useState<string | null>(null);
  useEffect(() => setSelected(null), [section, month]);
  const lead = d ? d.days[0].dow - 1 : 0;
  const day = useMemo<CalendarDay | null>(() => {
    if (!d) return null;
    if (selected) return d.days.find((x) => x.date === selected) ?? null;
    const marked = [...d.days].reverse().find((x) => (x.sessions?.length ?? 0) > 0);
    return marked ?? null;
  }, [d, selected]);

  return (
    <div className="space-y-5">
      <Card className="p-4 sm:p-5">
        <div className="flex flex-col gap-4 md:flex-row md:items-end">
          <Field label="Class / section" htmlFor="cal-section" className="md:w-96">
            <SectionPicker id="cal-section" value={section} onChange={(v) => onChange({ section: v })} />
          </Field>
          <div className="md:ml-auto">
            <span className="form-label">Month</span>
            <MonthNav value={month} onChange={(m) => onChange({ month: m })} max={currentMonth()} />
          </div>
        </div>
      </Card>

      {!section ? (
        <Card>
          <EmptyState icon={CalendarRange} title="Choose a class" description="The monthly heatmap shows each day's attendance for the selected section, with holidays and weekly offs." />
        </Card>
      ) : q.error ? (
        <Alert variant="error" title="Unable to load the calendar">{(q.error as ApiError).message}</Alert>
      ) : (
        <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_22rem]">
          <Card>
            <CardHeader
              title={d ? `${d.section.label} · ${monthLabel(month)}` : monthLabel(month)}
              subtitle={d ? `${d.section.students_count} students${d.section.class_teacher_name ? ` · Class teacher ${d.section.class_teacher_name}` : ''}` : 'Loading…'}
              icon={CalendarDays}
            />
            <div className="p-4 sm:p-5">
              {d && (
                <div className="mb-5 grid grid-cols-2 gap-4 rounded-xl bg-slate-50 p-4 sm:grid-cols-4 dark:bg-slate-800/40">
                  <MiniStat label="Average attendance" value={d.summary.percent !== null ? `${d.summary.percent.toFixed(1)}%` : '—'} tone={d.summary.percent !== null && d.summary.percent < d.min_percent ? 'red' : 'green'} />
                  <MiniStat label="Days marked" value={`${d.summary.days_marked}/${d.summary.working_days}`} hint="of working days so far" />
                  <MiniStat label="Classes held" value={d.summary.sessions} />
                  <MiniStat label="Lowest day" value={d.summary.worst ? `${d.summary.worst.percent.toFixed(1)}%` : '—'} hint={d.summary.worst ? formatDate(d.summary.worst.date) : undefined} tone="amber" />
                </div>
              )}
              <div className="grid grid-cols-7 gap-1.5 sm:gap-2" role="grid" aria-label="Attendance calendar">
                {DOW.map((x) => (
                  <div key={x} className="pb-1 text-center text-[11px] font-semibold uppercase tracking-wide text-slate-400" role="columnheader">
                    {x}
                  </div>
                ))}
                {q.isLoading || !d
                  ? Array.from({ length: 35 }).map((_, i) => <Skeleton key={i} className="aspect-square w-full rounded-lg sm:aspect-[4/3]" />)
                  : [
                      ...Array.from({ length: lead }).map((_, i) => <div key={`l${i}`} aria-hidden />),
                      ...d.days.map((x) => {
                        const closed = x.holiday && ['holiday', 'vacation'].includes(x.holiday.type);
                        const has = (x.sessions?.length ?? 0) > 0;
                        const isSel = day?.date === x.date;
                        return (
                          <button
                            key={x.date}
                            type="button"
                            role="gridcell"
                            onClick={() => setSelected(x.date)}
                            aria-selected={isSel}
                            aria-label={`${formatDate(x.date)}: ${has ? `${x.percent}% attendance, ${x.sessions?.length} classes` : closed ? x.holiday?.title : x.weekly_off ? 'weekly off' : 'no classes marked'}`}
                            className={clsx(
                              'group relative flex aspect-square w-full flex-col justify-between rounded-lg p-1 text-left transition duration-200 sm:aspect-[4/3] sm:p-2',
                              has ? heatClass(x.percent, d.min_percent) : closed ? 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' : x.weekly_off ? 'bg-slate-50 text-slate-300 dark:bg-slate-800/30 dark:text-slate-600' : 'bg-slate-50 text-slate-500 ring-1 ring-inset ring-slate-100 dark:bg-slate-800/50 dark:text-slate-400 dark:ring-slate-800',
                              x.future && 'opacity-50',
                              isSel ? 'ring-2 ring-brand-600 ring-offset-2 dark:ring-brand-400 dark:ring-offset-slate-900' : 'hover:-translate-y-0.5 hover:shadow-soft',
                            )}
                          >
                            <span className="text-xs font-bold sm:text-sm">{x.day}</span>
                            {has ? (
                              <span className="hidden text-[11px] font-semibold tabular-nums sm:block">{x.percent?.toFixed(0)}% · {x.sessions?.length}</span>
                            ) : closed ? (
                              <span className="hidden truncate text-[10px] font-medium sm:block" title={x.holiday?.title}>{x.holiday?.title}</span>
                            ) : x.holiday ? (
                              <span className="hidden truncate text-[10px] font-medium text-amber-600 sm:block">{x.holiday.title}</span>
                            ) : null}
                            {x.holiday && <PartyPopper className="absolute right-1 top-1 hidden h-3 w-3 opacity-70 sm:block" aria-hidden />}
                          </button>
                        );
                      }),
                    ]}
              </div>
              <div className="mt-4">
                <HeatLegend min={d?.min_percent ?? 75} />
              </div>
            </div>
          </Card>

          <Card className="xl:sticky xl:top-20 xl:self-start">
            <CardHeader title={day ? formatDate(day.date) : 'Day details'} subtitle={day ? (day.holiday ? day.holiday.title : `${day.sessions?.length ?? 0} classes marked`) : 'Select a day on the calendar'} icon={CalendarRange} />
            {!day ? (
              <EmptyState icon={CalendarRange} title="No day selected" description="Click a day to see the classes marked." />
            ) : !day.sessions?.length ? (
              <EmptyState
                icon={day.holiday ? PartyPopper : CalendarDays}
                title={day.holiday ? day.holiday.title : day.weekly_off ? 'Weekly off' : day.future ? 'Upcoming day' : 'No classes marked'}
                description={day.holiday || day.weekly_off || day.future ? undefined : 'Attendance has not been marked for this class on this day.'}
                action={!day.holiday && !day.weekly_off && !day.future ? <Button size="sm" onClick={() => onOpen({ date: day.date, section, subject: null, slot: null })}>Mark attendance</Button> : undefined}
              />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {day.sessions.map((s) => (
                  <li key={s.id}>
                    <button type="button" onClick={() => onOpen({ date: day.date, section, subject: s.subject_id, slot: s.time_slot_id })} className="w-full px-4 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                      <div className="flex items-center justify-between gap-2">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{s.subject}</p>
                        {s.is_locked && <Lock className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-label="Locked" />}
                      </div>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                        {s.slot_name ? `${s.slot_name} · ${formatTime(s.start_time)}` : 'No period'} · {s.faculty_name ?? '—'}
                      </p>
                      <div className="mt-2 flex items-center gap-3">
                        <PercentBar value={s.counts.percent} min={d?.min_percent} className="flex-1" />
                        <span className="whitespace-nowrap text-[11px] text-slate-500">
                          {s.counts.absent} A · {s.counts.late} L · {s.counts.leave} LV
                        </span>
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
      )}
    </div>
  );
}
