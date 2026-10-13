import clsx from 'clsx';
import { ArrowRight, CalendarDays, ChevronLeft, ChevronRight, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import type { IslandBaseProps } from '../lib/types';
import { addDays, addMonths, daysInMonth, formatDay, MONTHS, WEEKDAYS, weekdayIndex } from './home/shared';

type Kind = 'academic' | 'exam' | 'holiday' | 'event' | 'admission';

export interface CalendarItem {
  id: string;
  title: string;
  /** YYYY-MM-DD */
  start: string;
  end: string | null;
  type: Kind;
  meta?: string;
  url?: string | null;
}

export interface AcademicCalendarProps extends IslandBaseProps {
  session: { name: string; start: string; end: string };
  /** Server "today" (YYYY-MM-DD, Asia/Kolkata). */
  today: string;
  items: CalendarItem[];
  /** type → label for the filter chips. */
  types: Record<Kind, string>;
  /** Full calendar page (/academics#calendar) when it exists. */
  fullUrl?: string | null;
}

const DOT: Record<Kind, string> = {
  academic: 'bg-brand-600',
  exam: 'bg-violet-500',
  holiday: 'bg-rose-500',
  event: 'bg-emerald-500',
  admission: 'bg-accent-500',
};
const CHIP: Record<Kind, string> = {
  academic: 'bg-brand-50 text-brand-800',
  exam: 'bg-violet-50 text-violet-700',
  holiday: 'bg-rose-50 text-rose-700',
  event: 'bg-emerald-50 text-emerald-700',
  admission: 'bg-accent-50 text-accent-700',
};

const covers = (it: CalendarItem, day: string) => it.start <= day && (it.end || it.start) >= day;

/**
 * Academic Calendar: month grid (keyboard: arrows move by day / week, Home/End to week edges, PageUp/PageDown by
 * month, Enter selects a day) + agenda list, filter chips (All / Academic / Exams / Holidays / Events / Admissions),
 * today marker, month navigation limited to the academic session. Items come from holidays, exams, events, the session
 * dates and the admissions deadline (includes/partials/home.php → hm_calendar()).
 */
export default function AcademicCalendar({ session, today, items, types, fullUrl }: AcademicCalendarProps) {
  const first = (session.start || today).slice(0, 7);
  const last = (session.end || today).slice(0, 7);
  const clampMonth = (m: string) => (m < first ? first : m > last ? last : m);
  const [month, setMonth] = useState(() => clampMonth(today.slice(0, 7)));
  const [filter, setFilter] = useState<Kind | 'all'>('all');
  const [selected, setSelected] = useState<string | null>(null);
  const [focusDay, setFocusDay] = useState<string>(() => (today.slice(0, 7) === clampMonth(today.slice(0, 7)) ? today : `${clampMonth(today.slice(0, 7))}-01`));
  const gridRef = useRef<HTMLDivElement>(null);
  const wantFocus = useRef(false);

  const filtered = useMemo(() => (filter === 'all' ? items : items.filter((i) => i.type === filter)), [items, filter]);
  const counts = useMemo(() => {
    const c: Record<string, number> = { all: items.length };
    items.forEach((i) => (c[i.type] = (c[i.type] || 0) + 1));
    return c;
  }, [items]);

  const monthStart = `${month}-01`;
  const monthEnd = `${month}-${String(daysInMonth(month)).padStart(2, '0')}`;
  const inMonth = filtered.filter((i) => i.start <= monthEnd && (i.end || i.start) >= monthStart);
  const agenda = selected ? filtered.filter((i) => covers(i, selected)) : inMonth;
  const [y, m] = month.split('-').map((v) => parseInt(v, 10));
  const monthLabel = `${MONTHS[m - 1]} ${y}`;

  const lead = weekdayIndex(monthStart);
  const days = Array.from({ length: daysInMonth(month) }, (_, i) => `${month}-${String(i + 1).padStart(2, '0')}`);
  const cells: (string | null)[] = [...Array(lead).fill(null), ...days];
  while (cells.length % 7) cells.push(null);
  const weeks: (string | null)[][] = [];
  for (let i = 0; i < cells.length; i += 7) weeks.push(cells.slice(i, i + 7));

  useEffect(() => {
    if (!wantFocus.current) return;
    wantFocus.current = false;
    gridRef.current?.querySelector<HTMLButtonElement>(`[data-day="${focusDay}"]`)?.focus();
  }, [focusDay, month]);

  const goMonth = (delta: number) => {
    const next = clampMonth(addMonths(month, delta));
    setMonth(next);
    setSelected(null);
    setFocusDay(next === today.slice(0, 7) ? today : `${next}-01`);
  };

  const moveFocus = (day: string) => {
    const target = day.slice(0, 7);
    if (target < first || target > last) return;
    wantFocus.current = true;
    if (target !== month) setMonth(target);
    setFocusDay(day);
  };

  const onKey = (e: KeyboardEvent<HTMLButtonElement>, day: string) => {
    const wd = weekdayIndex(day);
    const actions: Record<string, () => void> = {
      ArrowRight: () => moveFocus(addDays(day, 1)),
      ArrowLeft: () => moveFocus(addDays(day, -1)),
      ArrowDown: () => moveFocus(addDays(day, 7)),
      ArrowUp: () => moveFocus(addDays(day, -7)),
      Home: () => moveFocus(addDays(day, -wd)),
      End: () => moveFocus(addDays(day, 6 - wd)),
      PageDown: () => {
        const nm = addMonths(day.slice(0, 7), 1);
        moveFocus(`${nm}-${String(Math.min(parseInt(day.slice(8), 10), daysInMonth(nm))).padStart(2, '0')}`);
      },
      PageUp: () => {
        const pm = addMonths(day.slice(0, 7), -1);
        moveFocus(`${pm}-${String(Math.min(parseInt(day.slice(8), 10), daysInMonth(pm))).padStart(2, '0')}`);
      },
    };
    if (actions[e.key]) {
      e.preventDefault();
      actions[e.key]();
    }
  };

  const range = (it: CalendarItem) => (it.end && it.end !== it.start ? `${formatDay(it.start)} – ${formatDay(it.end, { year: it.end.slice(0, 4) !== it.start.slice(0, 4) })}` : formatDay(it.start, { weekday: true }));
  const kinds = (Object.keys(types) as Kind[]).filter((k) => counts[k]);

  return (
    <div className="academic-calendar overflow-hidden rounded-[30px] border border-warm-2 bg-white shadow-[0_24px_60px_-46px_rgba(7,26,59,.5)]">
      <div className="flex flex-col gap-4 border-b border-warm-2 p-5 sm:p-6 lg:flex-row lg:items-center lg:justify-between">
        <div className="flex items-center gap-3">
          <span className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-brand-900 text-white">
            <CalendarDays className="h-5 w-5" aria-hidden="true" />
          </span>
          <div>
            <h3 className="font-display text-xl font-extrabold text-brand-900">Academic calendar</h3>
            <p className="text-[13px] text-slate-500">Session {session.name}</p>
          </div>
        </div>
        <div className="pill-row scrollbar-none" role="group" aria-label="Filter calendar">
          {(['all', ...kinds] as (Kind | 'all')[]).map((k) => (
            <button
              key={k}
              type="button"
              onClick={() => setFilter(k)}
              aria-pressed={filter === k}
              className={clsx('chip !px-3.5 !py-1.5 !text-[13px]', filter === k && 'chip-active')}
            >
              {k !== 'all' && <span className={clsx('h-2 w-2 rounded-full', DOT[k])} aria-hidden="true" />}
              {k === 'all' ? 'All' : types[k]}
              <span className={clsx('chip-count', filter === k && 'is-active')}>{counts[k] || 0}</span>
            </button>
          ))}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-12">
        <div className="border-b border-warm-2 p-5 sm:p-6 lg:col-span-7 lg:border-b-0 lg:border-r">
          <div className="flex items-center justify-between gap-2">
            <p className="font-display text-lg font-extrabold text-brand-900" id="ac-month" aria-live="polite">
              {monthLabel}
            </p>
            <div className="flex items-center gap-1.5">
              {today.slice(0, 7) !== month && today.slice(0, 7) >= first && today.slice(0, 7) <= last && (
                <button
                  type="button"
                  onClick={() => {
                    setMonth(today.slice(0, 7));
                    setSelected(null);
                    setFocusDay(today);
                  }}
                  className="mr-1 rounded-full px-3 py-1.5 text-[13px] font-semibold text-accent-700 hover:bg-accent-50"
                >
                  Today
                </button>
              )}
              <button type="button" onClick={() => goMonth(-1)} disabled={month <= first} aria-label="Previous month" className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-warm-2 text-brand-900 transition hover:bg-brand-900 hover:text-white disabled:pointer-events-none disabled:opacity-35">
                <ChevronLeft className="h-4 w-4" aria-hidden="true" />
              </button>
              <button type="button" onClick={() => goMonth(1)} disabled={month >= last} aria-label="Next month" className="inline-flex h-9 w-9 items-center justify-center rounded-full border border-warm-2 text-brand-900 transition hover:bg-brand-900 hover:text-white disabled:pointer-events-none disabled:opacity-35">
                <ChevronRight className="h-4 w-4" aria-hidden="true" />
              </button>
            </div>
          </div>
          <div ref={gridRef} role="grid" aria-labelledby="ac-month" className="mt-4">
            <div role="row" className="grid grid-cols-7 text-center text-[11.5px] font-bold uppercase tracking-wider text-slate-400">
              {WEEKDAYS.map((d) => (
                <span key={d} role="columnheader" aria-label={d} className="py-1">
                  {d.slice(0, 3)}
                </span>
              ))}
            </div>
            {weeks.map((week, wi) => (
              <div role="row" key={wi} className="mt-1 grid grid-cols-7 gap-1">
                {week.map((day, di) => {
                  if (!day) return <span key={di} role="gridcell" className="h-11 sm:h-14" />;
                  const on = filtered.filter((it) => covers(it, day));
                  const isToday = day === today;
                  const isSel = day === selected;
                  const kindsOn = Array.from(new Set(on.map((o) => o.type))).slice(0, 3);
                  return (
                    <span role="gridcell" key={day} aria-selected={isSel}>
                      <button
                        type="button"
                        data-day={day}
                        tabIndex={day === focusDay ? 0 : -1}
                        onClick={() => {
                          setSelected(isSel ? null : day);
                          setFocusDay(day);
                        }}
                        onKeyDown={(e) => onKey(e, day)}
                        aria-current={isToday ? 'date' : undefined}
                        aria-label={`${formatDay(day, { weekday: true, year: true })}${isToday ? ', today' : ''}${on.length ? `, ${on.length} ${on.length === 1 ? 'entry' : 'entries'}: ${on.map((o) => o.title).join('; ')}` : ''}`}
                        className={clsx(
                          'relative flex h-11 w-full flex-col items-center justify-center rounded-xl text-[14px] font-semibold transition sm:h-14 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-accent-500',
                          isSel ? 'bg-brand-900 text-white' : isToday ? 'bg-accent-600 text-white' : on.length ? 'bg-[#FAF8F5] text-brand-900 hover:bg-warm' : 'text-slate-600 hover:bg-[#FAF8F5]',
                        )}
                      >
                        {parseInt(day.slice(8), 10)}
                        {kindsOn.length > 0 && (
                          <span className="absolute bottom-1.5 flex gap-0.5" aria-hidden="true">
                            {kindsOn.map((k) => (
                              <span key={k} className={clsx('h-1.5 w-1.5 rounded-full', isSel || isToday ? 'bg-white' : DOT[k])} />
                            ))}
                          </span>
                        )}
                      </button>
                    </span>
                  );
                })}
              </div>
            ))}
          </div>
          <p className="mt-4 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12px] text-slate-500">
            <span className="inline-flex items-center gap-1.5">
              <span className="h-3 w-3 rounded bg-accent-600" aria-hidden="true" />
              Today
            </span>
            {kinds.map((k) => (
              <span key={k} className="inline-flex items-center gap-1.5">
                <span className={clsx('h-2 w-2 rounded-full', DOT[k])} aria-hidden="true" />
                {types[k]}
              </span>
            ))}
          </p>
        </div>

        <div className="flex flex-col p-5 sm:p-6 lg:col-span-5">
          <div className="flex items-center justify-between gap-3">
            <p className="font-display text-[15px] font-extrabold text-brand-900" aria-live="polite">
              {selected ? formatDay(selected, { weekday: true, year: true }) : `${monthLabel} · ${inMonth.length} ${inMonth.length === 1 ? 'entry' : 'entries'}`}
            </p>
            {selected && (
              <button type="button" onClick={() => setSelected(null)} className="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[12.5px] font-semibold text-accent-700 hover:bg-accent-50">
                <X className="h-3.5 w-3.5" aria-hidden="true" />
                Whole month
              </button>
            )}
          </div>
          <ul className="mt-3 max-h-[360px] space-y-2.5 overflow-y-auto pr-1">
            {agenda.map((it) => (
              <li key={it.id} className="flex items-start gap-3 rounded-2xl border border-warm-2 p-3">
                <span className="w-11 shrink-0 text-center">
                  <span className="block font-display text-lg font-extrabold leading-none text-brand-900">{it.start.slice(8)}</span>
                  <span className="text-[10.5px] font-bold uppercase text-slate-400">{MONTHS[parseInt(it.start.slice(5, 7), 10) - 1].slice(0, 3)}</span>
                </span>
                <span className="min-w-0 flex-1">
                  <span className="block text-[14px] font-semibold leading-snug text-brand-900">
                    {it.url ? (
                      <a href={it.url} className="hover:text-accent-700">
                        {it.title}
                      </a>
                    ) : (
                      it.title
                    )}
                  </span>
                  <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px] text-slate-500">
                    <span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-bold', CHIP[it.type])}>{types[it.type]}</span>
                    {range(it)}
                    {it.meta ? <span className="text-slate-400">· {it.meta}</span> : null}
                  </span>
                </span>
              </li>
            ))}
            {!agenda.length && <li className="rounded-2xl border border-dashed border-[#E2DAD0] p-5 text-center text-[14px] text-slate-500">{selected ? 'Nothing scheduled on this day.' : 'Nothing scheduled this month.'}</li>}
          </ul>
          {fullUrl && (
            <a href={fullUrl} className="link-arrow mt-auto pt-5 text-[14px]">
              Full academic calendar
              <ArrowRight className="h-4 w-4" aria-hidden="true" />
            </a>
          )}
        </div>
      </div>
    </div>
  );
}
