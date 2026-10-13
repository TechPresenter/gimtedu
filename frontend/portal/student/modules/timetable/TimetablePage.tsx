import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { BookOpen, CalendarDays, CalendarOff, Clock, Coffee, DoorOpen, GraduationCap, MapPin, Printer, UserRound } from 'lucide-react';
import { Stagger } from '@/components/ui';
import { formatDate, labelize } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead, Section } from '../../../components/Ui';
import { useStudentSession } from '../../../lib/session';
import { hhmm } from '../../../lib/time';
import { Chip, ListSkeleton, useStudent } from '../../shared/ui';
import type { StudentTimetable, TtDay, TtEntry, TtSlot } from '../../shared/types';

const TONES = [
  'border-l-brand-600 bg-brand-50/80 dark:border-l-brand-400 dark:bg-brand-500/10',
  'border-l-flame-500 bg-flame-50/80 dark:bg-flame-500/10',
  'border-l-emerald-500 bg-emerald-50/80 dark:bg-emerald-500/10',
  'border-l-violet-500 bg-violet-50/80 dark:bg-violet-500/10',
  'border-l-amber-500 bg-amber-50/80 dark:bg-amber-500/10',
  'border-l-sky-500 bg-sky-50/80 dark:bg-sky-500/10',
  'border-l-rose-500 bg-rose-50/80 dark:bg-rose-500/10',
  'border-l-teal-500 bg-teal-50/80 dark:bg-teal-500/10',
];
const DOTS = ['bg-brand-600 dark:bg-brand-400', 'bg-flame-500', 'bg-emerald-500', 'bg-violet-500', 'bg-amber-500', 'bg-sky-500', 'bg-rose-500', 'bg-teal-500'];

function useSubjectTone(data: StudentTimetable | undefined) {
  return useMemo(() => {
    const map = new Map<number, number>();
    (data?.subjects ?? []).forEach((s, i) => map.set(s.id, i % TONES.length));
    return (subjectId: number) => map.get(subjectId) ?? 0;
  }, [data]);
}

function isNow(day: TtDay, slot: TtSlot, now: string) {
  return day.is_today && now >= slot.start_time && now < slot.end_time;
}

function PeriodCard({ e, tone, now, compact }: { e: TtEntry; tone: number; now: boolean; compact?: boolean }) {
  return (
    <div className={clsx('h-full rounded-xl border-l-[3px] px-2.5 py-2 text-left transition', TONES[tone], now && 'ring-2 ring-flame-500 ring-offset-1 ring-offset-white dark:ring-offset-slate-900')}>
      <div className="flex items-start justify-between gap-1">
        <p className="text-[11px] font-bold uppercase tracking-wide text-slate-500 dark:text-slate-400">{e.subject_code}</p>
        {now && <span className="badge badge-flame !px-1.5 !py-0 text-[10px]">Now</span>}
        {!now && e.type !== 'lecture' && <span className="text-[10px] font-semibold uppercase text-slate-400">{labelize(e.type)}</span>}
      </div>
      <p className={clsx('font-semibold text-slate-900 dark:text-white', compact ? 'line-clamp-2 text-[12.5px] leading-snug' : 'text-sm')}>{e.subject}</p>
      <p className="mt-1 flex flex-wrap gap-x-2 gap-y-0.5 text-[11px] text-slate-500 dark:text-slate-400">
        {(e.room_code || e.room) && (
          <span className="inline-flex items-center gap-0.5">
            <MapPin className="h-3 w-3" aria-hidden />
            {e.room_code ?? e.room}
          </span>
        )}
        {e.faculty && !compact && (
          <span className="inline-flex items-center gap-0.5">
            <UserRound className="h-3 w-3" aria-hidden />
            {e.faculty}
          </span>
        )}
      </p>
      {e.faculty && compact && <p className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">{e.faculty}</p>}
    </div>
  );
}

function DayList({ data, day, toneOf }: { data: StudentTimetable; day: TtDay; toneOf: (id: number) => number }) {
  const entries = data.entries.filter((e) => e.day === day.no);
  if (day.holiday || day.weekly_off) {
    return <EmptyBlock icon={CalendarOff} title={day.holiday ?? 'Weekly off'} text={day.holiday ? 'The institute is closed on this day.' : 'No classes are scheduled on this day.'} />;
  }
  if (!entries.length) return <EmptyBlock icon={CalendarDays} title={`No classes on ${day.name}`} text="Your section has no periods on the timetable for this day." />;
  return (
    <ol className="space-y-2.5" aria-label={`${day.name} classes`}>
      {data.slots.map((slot) => {
        if (slot.is_break) {
          return (
            <li key={slot.id} className="flex items-center gap-3 px-1 text-[11px] font-semibold uppercase tracking-wider text-slate-400">
              <span className="w-[3.25rem] text-right tabular-nums">{hhmm(slot.start_time).replace(/ (AM|PM)$/, '')}</span>
              <span className="h-px flex-1 bg-slate-200 dark:bg-slate-800" aria-hidden />
              <Coffee className="h-3.5 w-3.5" aria-hidden /> {slot.name}
              <span className="h-px flex-1 bg-slate-200 dark:bg-slate-800" aria-hidden />
            </li>
          );
        }
        const e = entries.find((x) => x.slot_id === slot.id);
        return (
          <li key={slot.id} className="flex gap-3">
            <div className="w-[3.25rem] shrink-0 pt-2 text-right">
              <p className="text-[13px] font-bold tabular-nums text-slate-800 dark:text-slate-100">{hhmm(slot.start_time).replace(/ (AM|PM)$/, '')}</p>
              <p className="text-[10px] font-semibold uppercase text-slate-400">{hhmm(slot.end_time)}</p>
            </div>
            <div className="min-w-0 flex-1">
              {e ? (
                <PeriodCard e={e} tone={toneOf(e.subject_id)} now={isNow(day, slot, data.now)} />
              ) : (
                <div className="flex h-full min-h-[3.25rem] items-center rounded-xl border border-dashed border-slate-200 px-3 text-xs text-slate-400 dark:border-slate-800">Free period</div>
              )}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function WeekGrid({ data, toneOf }: { data: StudentTimetable; toneOf: (id: number) => number }) {
  const cell = new Map(data.entries.map((e) => [`${e.day}|${e.slot_id}`, e]));
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[860px] border-separate border-spacing-1.5 text-sm">
        <thead>
          <tr>
            <th className="w-24 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-400">Time</th>
            {data.days.map((d) => (
              <th key={d.no} scope="col" className={clsx('rounded-xl px-2 py-2 text-left', d.is_today ? 'bg-brand-900 text-white dark:bg-brand-700' : 'bg-slate-50 dark:bg-slate-800/60')}>
                <span className={clsx('block text-[13px] font-bold', d.is_today ? 'text-white' : 'text-slate-800 dark:text-slate-100')}>{d.name}</span>
                <span className={clsx('block text-[11px] font-medium', d.is_today ? 'text-flame-200' : 'text-slate-400')}>{d.is_today ? 'Today' : formatDate(d.date)}</span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.slots.map((slot) =>
            slot.is_break ? (
              <tr key={slot.id}>
                <td className="whitespace-nowrap text-[11px] font-semibold tabular-nums text-slate-400">{hhmm(slot.start_time)}</td>
                <td colSpan={data.days.length} className="rounded-lg bg-slate-50 py-1.5 text-center text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400 dark:bg-slate-800/40">
                  <Coffee className="mr-1.5 inline h-3.5 w-3.5 align-[-2px]" aria-hidden />
                  {slot.name} · {hhmm(slot.start_time)} – {hhmm(slot.end_time)}
                </td>
              </tr>
            ) : (
              <tr key={slot.id}>
                <th scope="row" className="whitespace-nowrap pr-1 text-left align-top">
                  <span className="block text-[13px] font-bold tabular-nums text-slate-800 dark:text-slate-100">{hhmm(slot.start_time)}</span>
                  <span className="block text-[11px] font-medium text-slate-400">{slot.name}</span>
                </th>
                {data.days.map((d) => {
                  if (d.holiday || d.weekly_off) {
                    return (
                      <td key={d.no} className="rounded-xl bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,rgba(148,163,184,.12)_6px,rgba(148,163,184,.12)_12px)] p-0 text-center align-middle text-[11px] font-semibold text-slate-400">
                        {slot.id === data.slots.find((x) => !x.is_break)?.id ? (d.holiday ?? 'Off') : ''}
                      </td>
                    );
                  }
                  const e = cell.get(`${d.no}|${slot.id}`);
                  return (
                    <td key={d.no} className={clsx('h-[86px] p-0 align-top', d.is_today && 'rounded-xl bg-flame-50/40 dark:bg-flame-500/5')}>
                      {e ? <PeriodCard e={e} tone={toneOf(e.subject_id)} now={isNow(d, slot, data.now)} compact /> : <div className="h-full rounded-xl border border-dashed border-slate-200/80 dark:border-slate-800" aria-label="Free period" />}
                    </td>
                  );
                })}
              </tr>
            ),
          )}
        </tbody>
      </table>
    </div>
  );
}

export default function StudentTimetablePage() {
  const s = useStudentSession().student!;
  const { data, isLoading, error, refetch } = useStudent<StudentTimetable>(['timetable'], 'timetable');
  const toneOf = useSubjectTone(data);
  const [picked, setPicked] = useState<number | null>(null);
  const days = data?.days ?? [];
  const activeDay = days.find((d) => d.no === picked) ?? days.find((d) => d.is_today) ?? days[0];
  const subtitle = `${s.program_short} · Semester ${s.semester}${s.section ? ` · Section ${s.section}` : ''}${data?.session ? ` · Session ${data.session}` : ''}`;

  return (
    <div id="tt-print">
      <style>{'@media print{body *{visibility:hidden!important}#tt-print,#tt-print *{visibility:visible!important}#tt-print{position:absolute;inset:0 auto auto 0;width:100%}.tt-noprint{display:none!important}}'}</style>
      <PageHead
        area="student"
        title="Timetable"
        subtitle={subtitle}
        crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Timetable' }]}
        actions={
          data && data.entries.length > 0 ? (
            <button type="button" onClick={() => window.print()} className="btn btn-secondary btn-sm tt-noprint hidden md:inline-flex">
              <Printer className="h-4 w-4" aria-hidden /> Print week
            </button>
          ) : undefined
        }
      />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data ? (
        <div className="space-y-4">
          <div className="skeleton h-12 rounded-2xl" />
          <ListSkeleton rows={6} />
        </div>
      ) : !data.section ? (
        <div className="card">
          <EmptyBlock icon={GraduationCap} title="No section assigned yet" text="Your timetable appears once the academic office places you in a section. Contact them if this looks wrong." />
        </div>
      ) : data.entries.length === 0 ? (
        <div className="card">
          <EmptyBlock
            icon={CalendarDays}
            title={data.unpublished ? 'Your timetable is being prepared' : 'No timetable published yet'}
            text={data.unpublished ? 'The academic office is finalising the timetable for your section. It will show here as soon as it is published.' : `No periods have been scheduled for Section ${data.section.name} in session ${data.session ?? ''}.`}
          />
        </div>
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap gap-2">
            {data.section.class_teacher && <Chip icon={UserRound}>Class teacher: {data.section.class_teacher}</Chip>}
            {data.section.home_room && <Chip icon={DoorOpen}>Home room {data.section.home_room}</Chip>}
            <Chip icon={Clock}>{data.periods_per_week} periods / week</Chip>
            <Chip icon={BookOpen}>{data.subjects.length} subjects</Chip>
          </div>

          {/* Mobile: day picker + agenda */}
          <div className="md:hidden">
            <div className="-mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1 scrollbar-none" role="tablist" aria-label="Day">
              {days.map((d) => {
                const on = activeDay?.no === d.no;
                return (
                  <button
                    key={d.no}
                    type="button"
                    role="tab"
                    aria-selected={on}
                    onClick={() => setPicked(d.no)}
                    className={clsx(
                      'flex min-w-[3.6rem] flex-col items-center rounded-2xl border px-2 py-2 transition',
                      on ? 'border-brand-900 bg-brand-900 text-white shadow-soft dark:border-brand-600 dark:bg-brand-700' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-300',
                    )}
                  >
                    <span className="text-[11px] font-semibold uppercase">{d.short}</span>
                    <span className="font-display text-lg font-extrabold leading-tight">{d.date.slice(8, 10)}</span>
                    <span className={clsx('mt-0.5 h-1.5 w-1.5 rounded-full', d.is_today ? 'bg-flame-500' : d.holiday ? 'bg-amber-400' : 'bg-transparent')} aria-hidden />
                  </button>
                );
              })}
            </div>
            {activeDay && (
              <Section title={activeDay.is_today ? `Today · ${activeDay.name}` : activeDay.name} subtitle={formatDate(activeDay.date)} icon={CalendarDays}>
                <DayList data={data} day={activeDay} toneOf={toneOf} />
              </Section>
            )}
          </div>

          {/* Desktop: week grid */}
          <section className="card hidden p-3 md:block lg:p-4">
            <WeekGrid data={data} toneOf={toneOf} />
          </section>

          <Section title="Subjects this semester" subtitle="Faculty and weekly load" icon={BookOpen}>
            <Stagger className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3" itemClassName="h-full">
              {data.subjects.map((sub) => (
                <div key={sub.id} className="flex h-full items-start gap-3 rounded-2xl border border-slate-200/80 p-3.5 dark:border-slate-800">
                  <span className={clsx('mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full', DOTS[toneOf(sub.id)])} aria-hidden />
                  <div className="min-w-0 flex-1">
                    <p className="text-[11px] font-bold uppercase tracking-wider text-slate-400">
                      {sub.code} · {labelize(sub.type)}
                    </p>
                    <p className="text-sm font-semibold text-slate-900 dark:text-white">{sub.name}</p>
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{sub.faculty.join(', ') || 'Faculty to be announced'}</p>
                  </div>
                  <span className="shrink-0 rounded-lg bg-slate-100 px-2 py-1 text-center text-[11px] font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                    {sub.periods}
                    <span className="block text-[9px] font-semibold uppercase text-slate-400">/ week</span>
                  </span>
                </div>
              ))}
            </Stagger>
          </Section>
        </div>
      )}
    </div>
  );
}
