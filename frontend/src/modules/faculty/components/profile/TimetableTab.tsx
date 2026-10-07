import { Fragment, useMemo } from 'react';
import clsx from 'clsx';
import { CalendarDays, Coffee, ExternalLink, MapPin, Printer } from 'lucide-react';
import { printUrl } from '@/lib/config';
import { Alert, Button, Card, CardHeader, EmptyState, PageLoader, Reveal } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatTime } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { WEEKDAYS } from '../../hr';

interface Slot {
  id: number;
  name: string;
  start_time: string;
  end_time: string;
  is_break: boolean;
}
interface Period {
  id: number;
  day_of_week: number;
  time_slot_id: number;
  type: string;
  status: string;
  subject_code: string;
  subject_name: string;
  program: string;
  semester_no: number;
  section: string;
  room: string | null;
}

const typeStyle: Record<string, string> = {
  lecture: 'border-l-brand-600 bg-brand-50/80 dark:bg-brand-500/10',
  lab: 'border-l-cyan-500 bg-cyan-50/80 dark:bg-cyan-500/10',
  tutorial: 'border-l-violet-500 bg-violet-50/80 dark:bg-violet-500/10',
  seminar: 'border-l-amber-500 bg-amber-50/80 dark:bg-amber-500/10',
};

/** Weekly timetable grid of a faculty member (from the timetable module). */
export default function TimetableTab({ id }: { id: number }) {
  const { data, isLoading, error } = useApi<{ slots: Slot[]; periods: Period[]; can_manage: boolean }>(['hr-timetable', id], `faculty/${id}/timetable`);
  const byCell = useMemo(() => {
    const m = new Map<string, Period>();
    (data?.periods ?? []).forEach((p) => m.set(`${p.day_of_week}-${p.time_slot_id}`, p));
    return m;
  }, [data]);
  if (isLoading) return <PageLoader label="Loading timetable…" />;
  if (error) return <Alert variant="error">{(error as ApiError).message}</Alert>;
  if (!data || !data.periods.length) {
    return (
      <Card>
        <EmptyState
          icon={CalendarDays}
          title="No classes scheduled"
          description="This faculty member has no periods in the published timetable for the current session."
          action={<Button to="/timetable" icon={ExternalLink}>Open timetable</Button>}
        />
      </Card>
    );
  }
  const maxDay = Math.max(6, ...data.periods.map((p) => p.day_of_week));
  const days = Array.from({ length: maxDay }, (_, i) => i + 1);
  const perDay = days.map((d) => data.periods.filter((p) => p.day_of_week === d).length);
  const cell = (p: Period) => (
    <div className={clsx('h-full rounded-lg border-l-[3px] px-2 py-1.5 text-left transition hover:-translate-y-0.5 hover:shadow-card', typeStyle[p.type] ?? typeStyle.lecture)} title={`${p.subject_name} · ${p.type}`}>
      <p className="truncate text-xs font-bold text-slate-900 dark:text-white">{p.subject_code}</p>
      <p className="truncate text-[11px] text-slate-600 dark:text-slate-300">{p.program} · S{p.semester_no}{p.section}</p>
      {p.room && <p className="mt-0.5 inline-flex items-center gap-0.5 text-[10px] text-slate-500"><MapPin className="h-2.5 w-2.5" />{p.room}</p>}
    </div>
  );
  return (
    <Reveal>
      <Card className="overflow-hidden">
        <CardHeader
          title="Weekly timetable"
          subtitle={`${data.periods.length} periods per week · busiest day ${WEEKDAYS[perDay.indexOf(Math.max(...perDay))]}`}
          icon={CalendarDays}
          actions={
            <div className="flex gap-2">
              <Button size="sm" variant="secondary" icon={Printer} href={printUrl('timetable.php', { view: 'faculty', id })} target="_blank">Print</Button>
              <Button size="sm" variant="secondary" icon={ExternalLink} to="/timetable">Full timetable</Button>
            </div>
          }
        />
        {/* Desktop grid */}
        <div className="hidden overflow-x-auto md:block">
          <table className="w-full min-w-[760px] border-separate border-spacing-1 p-3 text-sm" aria-label="Weekly timetable">
            <thead>
              <tr>
                <th className="w-28 px-2 py-1 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">Time</th>
                {days.map((d) => (
                  <th key={d} className="px-2 py-1 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500">{WEEKDAYS[d - 1]}</th>
                ))}
              </tr>
            </thead>
            <tbody>
              {data.slots.map((s) => (
                <tr key={s.id}>
                  <th scope="row" className="whitespace-nowrap px-2 py-1 text-left align-top">
                    <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">{s.name}</p>
                    <p className="text-[10px] font-normal text-slate-500">{formatTime(s.start_time)} – {formatTime(s.end_time)}</p>
                  </th>
                  {s.is_break ? (
                    <td colSpan={days.length} className="rounded-lg bg-slate-50 px-3 py-1.5 text-center text-[11px] font-medium uppercase tracking-widest text-slate-400 dark:bg-slate-800/40">
                      <Coffee className="mr-1 inline h-3 w-3" /> {s.name}
                    </td>
                  ) : (
                    days.map((d) => {
                      const p = byCell.get(`${d}-${s.id}`);
                      return (
                        <td key={d} className={clsx('h-16 align-top', !p && 'rounded-lg border border-dashed border-slate-100 dark:border-slate-800')}>
                          {p ? cell(p) : null}
                        </td>
                      );
                    })
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {/* Mobile: list per day */}
        <div className="space-y-4 p-4 md:hidden">
          {days.map((d) => {
            const list = data.slots.filter((s) => byCell.has(`${d}-${s.id}`));
            if (!list.length) return null;
            return (
              <div key={d}>
                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">{WEEKDAYS[d - 1]}</p>
                <div className="space-y-2">
                  {list.map((s) => (
                    <Fragment key={s.id}>
                      <div className="flex gap-3">
                        <div className="w-16 shrink-0 pt-1.5 text-[11px] text-slate-500">{formatTime(s.start_time)}</div>
                        <div className="flex-1">{cell(byCell.get(`${d}-${s.id}`) as Period)}</div>
                      </div>
                    </Fragment>
                  ))}
                </div>
              </div>
            );
          })}
        </div>
        <div className="flex flex-wrap gap-3 border-t border-slate-100 px-5 py-3 text-xs text-slate-500 dark:border-slate-800">
          {Object.entries(typeStyle).map(([k, cls]) => (
            <span key={k} className="inline-flex items-center gap-1.5">
              <span className={clsx('h-3 w-3 rounded border-l-[3px]', cls)} /> {k[0].toUpperCase() + k.slice(1)}
            </span>
          ))}
        </div>
      </Card>
    </Reveal>
  );
}
