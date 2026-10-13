import { useMemo } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { CalendarDays, Coffee, DoorOpen, UserCheck, Users } from 'lucide-react';
import { Alert, Card, EmptyState, Skeleton, StatTile } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import { useApi, useLookup } from '@/lib/queries';
import { initials } from '@/lib/format';
import type { TtDaily, TtDay } from '../types';
import { hm, todayDow, toneFor } from './helpers';

interface DailyViewProps {
  sessionId: number;
  days: TtDay[];
  day: number | null;
  department: string;
  program: string;
  onChange: (patch: Record<string, string | null>) => void;
}

/** All classes of the institute on one day: sections x periods. */
export function DailyView({ sessionId, days, day, department, program, onChange }: DailyViewProps) {
  const today = todayDow();
  const activeDay = day ?? (days.some((d) => d.no === today) ? today : days[0]?.no ?? 1);
  const { data: departments = [] } = useLookup('departments');
  const { data: programs = [] } = useLookup('programs', department ? { department_id: department } : {});
  const { data, isLoading, error } = useApi<TtDaily>(['tt', 'daily', sessionId, activeDay, department, program], 'timetable/daily', {
    session_id: sessionId, day: activeDay, department_id: department || undefined, program_id: program || undefined,
  });
  const cells = useMemo(() => {
    const m = new Map<string, TtDaily['entries'][number]>();
    data?.entries.forEach((e) => m.set(`${e.section_id}-${e.time_slot_id}`, e));
    return m;
  }, [data]);

  return (
    <div className="space-y-5">
      <Card className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center">
        <div className="flex gap-1.5 overflow-x-auto scrollbar-none" role="tablist" aria-label="Day of the week">
          {days.map((d) => (
            <button
              key={d.no}
              type="button"
              role="tab"
              aria-selected={d.no === activeDay}
              onClick={() => onChange({ day: String(d.no) })}
              className={clsx(
                'rounded-xl px-3.5 py-2 text-sm font-semibold transition',
                d.no === activeDay ? 'bg-brand-800 text-white shadow-sm dark:bg-brand-600' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800',
              )}
            >
              <span className="hidden sm:inline">{d.name}</span>
              <span className="sm:hidden">{d.short}</span>
              {d.no === today && <span className="ml-1.5 inline-block h-1.5 w-1.5 rounded-full bg-accent-400 align-middle" aria-label="today" />}
            </button>
          ))}
        </div>
        <div className="flex flex-col gap-2 sm:flex-row lg:ml-auto">
          <select className="form-input form-input-sm sm:w-56" value={department} onChange={(e) => onChange({ dept: e.target.value || null, program: null })} aria-label="Department">
            <option value="">All departments</option>
            {departments.map((d) => (
              <option key={d.value} value={d.value}>{d.label}</option>
            ))}
          </select>
          <select className="form-input form-input-sm sm:w-60" value={program} onChange={(e) => onChange({ program: e.target.value || null })} aria-label="Program">
            <option value="">All programs</option>
            {programs.map((p) => (
              <option key={p.value} value={p.value}>{p.label}</option>
            ))}
          </select>
        </div>
      </Card>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatTile label="Classes" value={data?.stats.periods ?? 0} icon={CalendarDays} tone="blue" loading={isLoading} sub={data?.day.name} />
        <StatTile label="Sections with classes" value={data?.stats.sections ?? 0} icon={Users} tone="cyan" loading={isLoading} sub={`of ${data?.sections.length ?? 0} sections`} />
        <StatTile label="Faculty teaching" value={data?.stats.faculty ?? 0} icon={UserCheck} tone="green" loading={isLoading} />
        <StatTile label="Rooms in use" value={data?.stats.rooms ?? 0} icon={DoorOpen} tone="amber" loading={isLoading} />
      </div>

      <Card className="overflow-hidden">
        {error ? (
          <Alert variant="error" className="m-5">{(error as ApiError).message}</Alert>
        ) : isLoading ? (
          <div className="space-y-2 p-5">{Array.from({ length: 8 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
        ) : !data?.sections.length ? (
          <EmptyState icon={Users} title="No sections" description="There are no active sections for the selected filters in this session." />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[980px] border-separate border-spacing-0 text-xs" aria-label={`Classes on ${data.day.name}`}>
              <thead>
                <tr className="bg-slate-50 dark:bg-slate-800/60">
                  <th scope="col" className="sticky left-0 z-[1] w-48 bg-slate-50 px-4 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800">
                    Class
                  </th>
                  {data.slots.map((s) =>
                    s.is_break ? (
                      <th key={s.id} scope="col" className="w-8 px-0" aria-label={s.name}>
                        <Coffee className="mx-auto h-3 w-3 text-slate-300" />
                      </th>
                    ) : (
                      <th key={s.id} scope="col" className="px-1.5 py-2.5 text-center font-semibold text-slate-600 dark:text-slate-300">
                        {s.name.replace('Period ', 'P')}
                        <span className="block text-[10px] font-normal text-slate-400">{hm(s.start_time)}</span>
                      </th>
                    ),
                  )}
                </tr>
              </thead>
              <tbody>
                {data.sections.map((sec) => (
                  <tr key={sec.id} className="group">
                    <th scope="row" className="sticky left-0 z-[1] border-t border-slate-100 bg-white px-4 py-2 text-left group-hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:group-hover:bg-slate-800/60">
                      <Link to={`/timetable?section=${sec.id}&session=${sessionId}`} className="block font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                        {sec.program_short} · S{sec.semester_no} · {sec.name}
                      </Link>
                      <span className="text-[11px] font-normal text-slate-400">{sec.strength} students</span>
                    </th>
                    {data.slots.map((s) => {
                      if (s.is_break) return <td key={s.id} className="border-t border-slate-100 bg-slate-50/60 dark:border-slate-800 dark:bg-slate-800/30" />;
                      const e = cells.get(`${sec.id}-${s.id}`);
                      const tone = e ? toneFor(e.subject_id) : null;
                      return (
                        <td key={s.id} className="border-t border-slate-100 p-1 dark:border-slate-800">
                          {e && tone ? (
                            <div
                              className={clsx('rounded-lg border-l-[3px] px-1.5 py-1 leading-tight', tone.card)}
                              title={`${e.subject_code} ${e.subject_name} · ${e.faculty_name ?? 'Faculty TBA'} · ${e.room_code ?? 'No room'}`}
                            >
                              <p className={clsx('truncate font-bold', tone.text)}>{e.subject_code}</p>
                              <p className="flex items-center justify-between gap-1 text-[10px] text-slate-500 dark:text-slate-400">
                                <span className="truncate">{e.faculty_name ? initials(e.faculty_name) : 'TBA'}</span>
                                <span className="font-semibold">{e.room_code}</span>
                              </p>
                            </div>
                          ) : (
                            <div className="h-9 rounded-lg border border-dashed border-slate-100 dark:border-slate-800" aria-label="Free" />
                          )}
                        </td>
                      );
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </div>
  );
}
