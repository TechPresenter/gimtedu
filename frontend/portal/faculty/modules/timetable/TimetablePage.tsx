import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { BadgeCheck, BookOpen, CalendarClock, CalendarDays, CalendarOff, CircleDot, Clock3, Coffee, Layers, MapPin, Printer, Users } from 'lucide-react';
import { Stagger } from '@/components/ui';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead } from '../../../components/Ui';
import { usePortalQuery } from '../../../lib/hooks';
import { useFacultySession } from '../../../lib/session';
import { hhmm } from '../../../lib/time';
import type { TimetableData, TimetableDay, TimetablePeriod } from '../../shared/types';
import { MiniStat, Stepper, fpath } from '../../shared/ui';

const PRINT_CSS = `@media print {
  aside, header, nav[aria-label="Primary"], .no-print { display: none !important; }
  .lg\\:pl-\\[272px\\] { padding-left: 0 !important; }
  body { background: #fff !important; }
  #portal-main { padding: 0 !important; max-width: none !important; }
  .tt-print-grid { font-size: 10px; }
}`;

const STATE_META: Record<TimetablePeriod['state'], { label: string; cls: string; dot: string }> = {
  marked: { label: 'Marked', cls: 'border-l-emerald-500', dot: 'bg-emerald-500' },
  pending: { label: 'Attendance pending', cls: 'border-l-red-500', dot: 'bg-red-500' },
  now: { label: 'In progress', cls: 'border-l-flame-500', dot: 'bg-flame-500' },
  upcoming: { label: 'Upcoming', cls: 'border-l-brand-700 dark:border-l-brand-400', dot: 'bg-brand-700 dark:bg-brand-400' },
  holiday: { label: 'Holiday', cls: 'border-l-slate-300 dark:border-l-slate-600', dot: 'bg-slate-300 dark:bg-slate-600' },
  off: { label: 'Off', cls: 'border-l-slate-300', dot: 'bg-slate-300' },
};

/** Where a period card leads: the register for held classes, the class roster otherwise. */
function periodLink(p: TimetablePeriod): string {
  if (p.date && (p.state === 'marked' || p.state === 'pending' || p.state === 'now')) {
    return `${fpath('attendance')}?date=${p.date}&section_id=${p.section_id}&subject_id=${p.subject_id}&slot=${p.time_slot_id}`;
  }
  return fpath(`classes/${p.section_id}/${p.subject_id}`);
}

function StateBadge({ p }: { p: TimetablePeriod }) {
  if (p.state === 'marked' && p.counts)
    return (
      <span className="badge badge-green">
        <BadgeCheck className="h-3 w-3" aria-hidden /> {p.counts.present + p.counts.late}/{p.counts.total}
      </span>
    );
  if (p.state === 'pending') return <span className="badge badge-red">Not marked</span>;
  if (p.state === 'now')
    return (
      <span className="badge badge-flame">
        <CircleDot className="h-3 w-3 animate-pulse" aria-hidden /> Now
      </span>
    );
  if (p.state === 'holiday') return <span className="badge badge-slate">Holiday</span>;
  return null;
}

function GridCell({ p }: { p: TimetablePeriod }) {
  const m = STATE_META[p.state];
  return (
    <Link
      to={periodLink(p)}
      className={clsx(
        'group flex h-full min-h-[92px] flex-col rounded-xl border border-l-4 border-slate-200/80 bg-white p-2.5 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-700/80 dark:bg-slate-900',
        m.cls,
        p.state === 'holiday' && 'opacity-60',
      )}
      title={`${p.subject} · ${p.program} Sem ${p.semester_no}-${p.section} · ${m.label}`}
    >
      <div className="flex items-start justify-between gap-1.5">
        <span className="font-display text-[13px] font-extrabold text-brand-900 dark:text-white">{p.subject_code}</span>
        {p.type !== 'lecture' && <span className="rounded bg-violet-50 px-1 text-[10px] font-bold uppercase text-violet-700 dark:bg-violet-500/15 dark:text-violet-300">{labelize(p.type).slice(0, 4)}</span>}
      </div>
      <p className="mt-0.5 line-clamp-2 text-[11.5px] font-semibold leading-snug text-slate-700 dark:text-slate-200">{p.subject}</p>
      <div className="mt-auto flex flex-wrap items-center gap-x-2 gap-y-0.5 pt-1.5 text-[10.5px] text-slate-500 dark:text-slate-400">
        <span>
          {p.program} {p.semester_no}-{p.section}
        </span>
        {p.room_code && (
          <span className="inline-flex items-center gap-0.5">
            <MapPin className="h-3 w-3" aria-hidden />
            {p.room_code}
          </span>
        )}
      </div>
      <div className="mt-1.5 flex items-center gap-1.5 text-[10.5px] font-semibold print:hidden">
        <span className={clsx('h-1.5 w-1.5 rounded-full', m.dot)} aria-hidden />
        <span className="text-slate-500 dark:text-slate-400">{p.state === 'marked' && p.counts ? `${p.counts.present + p.counts.late}/${p.counts.total} present` : m.label}</span>
      </div>
    </Link>
  );
}

function WeekGrid({ data }: { data: TimetableData }) {
  const byCell = useMemo(() => {
    const map = new Map<string, TimetablePeriod>();
    data.periods.forEach((p) => map.set(`${p.day_of_week}:${p.time_slot_id}`, p));
    return map;
  }, [data.periods]);
  const cols = data.days.length;
  return (
    <div className="card overflow-hidden">
      <div className="overflow-x-auto">
        <div className="tt-print-grid min-w-[880px]" style={{ display: 'grid', gridTemplateColumns: `128px repeat(${cols}, minmax(0, 1fr))` }}>
          <div className="sticky left-0 z-[1] border-b border-slate-200 bg-slate-50 px-3 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">Period</div>
          {data.days.map((d) => (
            <div key={d.dow} className={clsx('border-b border-l border-slate-200 px-3 py-2.5 dark:border-slate-800', d.is_today ? 'bg-flame-50/70 dark:bg-flame-500/10' : 'bg-slate-50 dark:bg-slate-900')}>
              <p className={clsx('text-[13px] font-bold', d.is_today ? 'text-flame-700 dark:text-flame-300' : 'text-slate-800 dark:text-slate-100')}>
                {d.name} {d.is_today && <span className="ml-1 rounded-full bg-flame-600 px-1.5 py-px text-[10px] font-bold uppercase text-white">Today</span>}
              </p>
              <p className="text-[11px] text-slate-500 dark:text-slate-400">{formatDate(d.date)}</p>
              {(d.holiday || d.event) && <p className={clsx('mt-0.5 truncate text-[10.5px] font-semibold', d.holiday ? 'text-red-600 dark:text-red-300' : 'text-amber-700 dark:text-amber-300')}>{d.holiday ?? d.event}</p>}
            </div>
          ))}
          {data.slots.map((s) =>
            s.is_break ? (
              <div key={s.id} className="contents">
                <div className="sticky left-0 z-[1] flex items-center gap-1.5 border-b border-slate-100 bg-white px-3 py-1.5 text-[11px] font-semibold text-slate-400 dark:border-slate-800 dark:bg-slate-900">
                  <Coffee className="h-3.5 w-3.5" aria-hidden /> {s.name}
                </div>
                <div className="border-b border-l border-slate-100 bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,rgba(156,151,143,.08)_6px,rgba(156,151,143,.08)_12px)] px-3 py-1.5 text-[11px] text-slate-400 dark:border-slate-800" style={{ gridColumn: `span ${cols}` }}>
                  {hhmm(s.start_time)} – {hhmm(s.end_time)}
                </div>
              </div>
            ) : (
              <div key={s.id} className="contents">
                <div className="sticky left-0 z-[1] border-b border-slate-100 bg-white px-3 py-2.5 dark:border-slate-800 dark:bg-slate-900">
                  <p className="text-[13px] font-bold text-slate-800 dark:text-slate-100">{s.name}</p>
                  <p className="text-[11px] tabular-nums text-slate-500 dark:text-slate-400">
                    {hhmm(s.start_time)} – {hhmm(s.end_time)}
                  </p>
                </div>
                {data.days.map((d) => {
                  const p = byCell.get(`${d.dow}:${s.id}`);
                  return (
                    <div key={d.dow} className={clsx('border-b border-l border-slate-100 p-1.5 dark:border-slate-800', d.is_today && 'bg-flame-50/30 dark:bg-flame-500/5', d.holiday && 'bg-slate-50 dark:bg-slate-900/60')}>
                      {p ? <GridCell p={p} /> : <div className="h-full min-h-[92px] rounded-xl border border-dashed border-slate-200/70 dark:border-slate-800" aria-hidden />}
                    </div>
                  );
                })}
              </div>
            ),
          )}
        </div>
      </div>
    </div>
  );
}

function DayList({ day, periods }: { day: TimetableDay; periods: TimetablePeriod[] }) {
  if (day.holiday) return <EmptyBlock icon={CalendarOff} title={day.holiday} text="The institute is closed - no classes on this day." />;
  if (!periods.length) return <EmptyBlock icon={CalendarDays} title={`No classes on ${day.name}`} text="Your published timetable has no periods on this day." />;
  return (
    <ul className="space-y-2.5">
      {periods.map((p) => {
        const m = STATE_META[p.state];
        return (
          <li key={p.id}>
            <Link to={periodLink(p)} className={clsx('card lift flex items-stretch gap-3 border-l-4 p-3.5', m.cls)}>
              <div className="flex w-16 shrink-0 flex-col justify-center border-r border-slate-100 pr-3 dark:border-slate-800">
                <span className="font-display text-sm font-extrabold tabular-nums text-slate-900 dark:text-white">{hhmm(p.start_time).replace(/ (AM|PM)$/, '')}</span>
                <span className="text-[10px] font-semibold uppercase text-slate-400">{hhmm(p.start_time).slice(-2)}</span>
                <span className="mt-1 text-[10px] text-slate-400">{p.slot}</span>
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                  <p className="min-w-0 truncate text-sm font-bold text-slate-900 dark:text-white">{p.subject}</p>
                  <StateBadge p={p} />
                </div>
                <p className="text-xs font-semibold text-flame-700 dark:text-flame-300">{p.subject_code}</p>
                <p className="mt-1 flex flex-wrap gap-x-3 gap-y-0.5 text-xs text-slate-500 dark:text-slate-400">
                  <span className="inline-flex items-center gap-1">
                    <Users className="h-3.5 w-3.5" aria-hidden /> {p.program} Sem {p.semester_no}-{p.section}
                  </span>
                  {p.room_code && (
                    <span className="inline-flex items-center gap-1">
                      <MapPin className="h-3.5 w-3.5" aria-hidden /> {p.room_code}
                    </span>
                  )}
                  <span className="inline-flex items-center gap-1">
                    <Clock3 className="h-3.5 w-3.5" aria-hidden /> until {hhmm(p.end_time)}
                  </span>
                  {p.type !== 'lecture' && <span>{labelize(p.type)}</span>}
                </p>
              </div>
            </Link>
          </li>
        );
      })}
    </ul>
  );
}

export default function TimetablePage() {
  const [params, setParams] = useSearchParams();
  const week = params.get('week') ?? undefined;
  const { data, isLoading, isFetching, error, refetch } = usePortalQuery<TimetableData>('faculty', ['timetable'], 'timetable', { week });
  const [dow, setDow] = useState<number | null>(null);
  const me = useFacultySession().faculty;

  // default the mobile day picker to today (or the first day of the week)
  useEffect(() => {
    if (!data) return;
    setDow((cur) => (cur && data.days.some((d) => d.dow === cur) ? cur : data.days.find((d) => d.is_today)?.dow ?? data.days[0]?.dow ?? 1));
  }, [data]);

  const setWeek = (w: string | null) => {
    const next = new URLSearchParams(params);
    if (w) next.set('week', w);
    else next.delete('week');
    setParams(next, { replace: true });
  };

  const selectedDay = data?.days.find((d) => d.dow === dow) ?? null;
  const dayPeriods = data && selectedDay ? data.periods.filter((p) => p.day_of_week === selectedDay.dow) : [];
  const pending = data ? data.periods.filter((p) => p.state === 'pending').length : 0;

  return (
    <div>
      <style>{PRINT_CSS}</style>
      {me && data && (
        <p className="mb-2 hidden text-sm font-semibold text-slate-700 print:block">
          {me.name} ({me.employee_id}) · {me.designation}
          {me.department ? `, ${me.department}` : ''} · Week of {formatDate(data.week.start)} – {formatDate(data.week.end)}
        </p>
      )}
      <PageHead
        area="faculty"
        title="My Timetable"
        subtitle={data?.session ? `Weekly teaching schedule · Session ${data.session}` : 'Your weekly teaching schedule.'}
        crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'My Timetable' }]}
        actions={
          <div className="no-print flex flex-wrap items-center gap-2">
            {data && (
              <Stepper
                label={data.week.is_current ? 'This week' : `Week of ${formatDate(data.week.start)}`}
                sub={`${formatDate(data.week.start)} – ${formatDate(data.week.end)}`}
                onPrev={() => setWeek(data.week.prev)}
                onNext={() => setWeek(data.week.next)}
                prevLabel="Previous week"
                nextLabel="Next week"
              />
            )}
            {data && !data.week.is_current && (
              <button type="button" className="btn btn-secondary btn-sm" onClick={() => setWeek(null)}>
                This week
              </button>
            )}
            <button type="button" className="btn btn-secondary btn-sm hidden lg:inline-flex" onClick={() => window.print()}>
              <Printer className="h-4 w-4" aria-hidden /> Print
            </button>
          </div>
        }
      />

      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-4">
          <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <div key={i} className="skeleton h-[74px] rounded-2xl" />
            ))}
          </div>
          <div className="skeleton h-[480px] rounded-2xl" />
        </div>
      ) : data.periods.length === 0 ? (
        <div className="card">
          <EmptyBlock icon={CalendarClock} title="No timetable published yet" text="Once the academic office publishes the timetable for this session, your periods appear here." />
        </div>
      ) : (
        <div className={clsx('space-y-5 transition-opacity', isFetching && 'opacity-70')}>
          <Stagger className="no-print grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full">
            {[
              <MiniStat key="p" label="Periods / week" value={data.summary.periods} icon={CalendarClock} tone="navy" sub={data.summary.busiest_day ? `Busiest: ${data.summary.busiest_day} (${data.summary.busiest_count})` : undefined} />,
              <MiniStat key="h" label="Teaching hours" value={`${data.summary.hours} h`} icon={Clock3} tone="flame" sub="per week" />,
              <MiniStat key="s" label="Subjects" value={data.summary.subjects} icon={BookOpen} tone="violet" sub={Object.entries(data.summary.by_type).map(([t, n]) => `${n} ${t}`).join(' · ')} />,
              <MiniStat key="c" label="Sections" value={data.summary.sections} icon={Layers} tone={pending ? 'red' : 'green'} sub={pending ? `${pending} register${pending > 1 ? 's' : ''} pending this week` : 'All registers up to date'} />,
            ]}
          </Stagger>

          <div className="no-print flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-500 dark:text-slate-400" aria-label="Legend">
            {(['marked', 'pending', 'now', 'upcoming'] as const).map((s) => (
              <span key={s} className="inline-flex items-center gap-1.5">
                <span className={clsx('h-2 w-2 rounded-full', STATE_META[s].dot)} aria-hidden /> {STATE_META[s].label}
              </span>
            ))}
            <span className="hidden lg:inline">· Click a period to open its attendance register or class roster.</span>
          </div>

          <div className="hidden lg:block print:block">
            <WeekGrid data={data} />
          </div>

          <div className="lg:hidden print:hidden">
            <div className="-mx-4 mb-3 flex gap-2 overflow-x-auto px-4 pb-1 scrollbar-none" role="tablist" aria-label="Day">
              {data.days.map((d) => {
                const n = data.periods.filter((p) => p.day_of_week === d.dow).length;
                return (
                  <button
                    key={d.dow}
                    type="button"
                    role="tab"
                    aria-selected={dow === d.dow}
                    onClick={() => setDow(d.dow)}
                    className={clsx(
                      'flex min-w-[64px] flex-col items-center rounded-2xl border px-3 py-2 transition',
                      dow === d.dow ? 'border-brand-900 bg-brand-900 text-white shadow-soft dark:border-white/20 dark:bg-white/10' : 'border-slate-200 bg-white text-slate-700 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-200',
                    )}
                  >
                    <span className={clsx('text-[11px] font-semibold uppercase', dow === d.dow ? 'text-flame-300' : d.is_today ? 'text-flame-600 dark:text-flame-300' : 'text-slate-400')}>{d.is_today ? 'Today' : d.short}</span>
                    <span className="font-display text-lg font-extrabold leading-tight">{d.date.slice(8)}</span>
                    <span className={clsx('text-[10px]', dow === d.dow ? 'text-white/70' : 'text-slate-400')}>{d.holiday ? 'Holiday' : `${n} class${n === 1 ? '' : 'es'}`}</span>
                  </button>
                );
              })}
            </div>
            {selectedDay && <DayList day={selectedDay} periods={dayPeriods} />}
          </div>
        </div>
      )}
    </div>
  );
}
