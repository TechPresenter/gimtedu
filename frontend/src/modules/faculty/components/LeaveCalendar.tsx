import { useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { CalendarDays, CalendarRange, ChevronLeft, ChevronRight, Sun } from 'lucide-react';
import { Alert, Avatar, Button, Card, CardHeader, EmptyState, IconButton, Input, Select, Skeleton, StatusBadge, Toggle, toneClasses } from '@/components/ui';
import { useApi, useLookup } from '@/lib/queries';
import { formatDate, isoDate } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { currentMonth, daysLabel, dow, leaveChip, leaveTone, monthGrid, monthLabel, profilePath, shiftMonth, WEEKDAYS, type EmployeeType, type LeaveType } from '../hr';

interface CalLeave {
  id: number;
  ref: string;
  employee_type: EmployeeType;
  employee_id: number;
  leave_type: string;
  leave_type_name: string;
  color: string | null;
  from_date: string;
  to_date: string;
  days: number;
  is_half_day: boolean;
  status: string;
  reason: string | null;
  name: string;
  code: string;
  photo: string | null;
  designation: string;
  department_code: string | null;
}
interface Payload {
  month: string;
  today: string;
  leaves: CalLeave[];
  holidays: Record<string, string>;
  weekly_offs: number[];
  types: LeaveType[];
}

const shortName = (n: string) => n.replace(/^(Dr|Prof|Mr|Ms|Mrs)\.?\s+/i, '').split(' ').slice(0, 2).map((p, i) => (i ? `${p[0]}.` : p)).join(' ');

/** Month calendar of who is on leave, with a day panel. */
export function LeaveCalendar({ onOpen }: { onOpen: (id: number) => void }) {
  const [month, setMonth] = useState(currentMonth());
  const [type, setType] = useState('');
  const [dept, setDept] = useState('');
  const [pending, setPending] = useState(true);
  const [day, setDay] = useState<string>(isoDate());
  const { data: depts = [] } = useLookup('departments');
  const query = { month, employee_type: type || undefined, department_id: dept || undefined, include_pending: pending ? 1 : 0 };
  const { data, isLoading, isFetching, error } = useApi<Payload>(['leave-calendar', query], 'leaves/calendar', query, { placeholderData: (prev) => prev });

  useEffect(() => {
    // keep the selected day inside the visible month
    if (!day.startsWith(month)) setDay(month === currentMonth() ? isoDate() : `${month}-01`);
  }, [month, day]);

  const byDay = useMemo(() => {
    const m: Record<string, CalLeave[]> = {};
    (data?.leaves ?? []).forEach((l) => {
      monthGrid(month).forEach((d) => {
        if (d && d >= l.from_date && d <= l.to_date && !data?.holidays[d] && !(data?.weekly_offs ?? [0]).includes(dow(d))) (m[d] ??= []).push(l);
      });
    });
    return m;
  }, [data, month]);

  const selected = byDay[day] ?? [];
  const offs = data?.weekly_offs ?? [0];
  const typesInView = useMemo(() => {
    const seen = new Map<string, { name: string; color: string | null }>();
    (data?.leaves ?? []).forEach((l) => seen.set(l.leave_type, { name: l.leave_type_name, color: l.color }));
    return [...seen.entries()];
  }, [data]);

  if (error) return <Alert variant="error" title="Unable to load the leave calendar">{(error as ApiError).message}</Alert>;

  return (
    <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
      <Card className="overflow-hidden">
        <div className="space-y-3 border-b border-slate-100 p-4 dark:border-slate-800">
          <div className="flex items-center gap-2">
            <IconButton icon={ChevronLeft} label="Previous month" onClick={() => setMonth((m) => shiftMonth(m, -1))} />
            <h2 className="min-w-[9.5rem] text-center font-display text-lg font-bold text-slate-900 dark:text-white">{monthLabel(month)}</h2>
            <IconButton icon={ChevronRight} label="Next month" onClick={() => setMonth((m) => shiftMonth(m, 1))} />
            <Button size="sm" variant="secondary" onClick={() => { setMonth(currentMonth()); setDay(isoDate()); }}>Today</Button>
            <Input inputSize="sm" type="month" value={month} onChange={(e) => e.target.value && setMonth(e.target.value)} aria-label="Jump to month" className="!ml-auto hidden !w-40 sm:block" />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Select inputSize="sm" value={type} onChange={(e) => setType(e.target.value)} options={[{ value: 'faculty', label: 'Faculty' }, { value: 'staff', label: 'Staff' }]} placeholder="Everyone" aria-label="Employee type" className="!w-32" />
            <Select inputSize="sm" value={dept} onChange={(e) => setDept(e.target.value)} options={depts.map((d) => ({ value: String(d.value), label: d.sub ? `${d.sub} — ${d.label}` : d.label }))} placeholder="All departments" aria-label="Department" className="!w-48" />
            <Toggle checked={pending} onChange={setPending} label="Show pending" />
          </div>
        </div>
        <div className={clsx('p-3 transition-opacity sm:p-4', isFetching && 'opacity-60')}>
          {isLoading ? (
            <Skeleton className="h-[30rem] !rounded-xl" />
          ) : (
            <>
              <div className="grid grid-cols-7 gap-1 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-500">
                {WEEKDAYS.map((d) => <div key={d} className="py-1">{d}</div>)}
              </div>
              <div className="grid grid-cols-7 gap-1">
                {monthGrid(month).map((d, i) => {
                  if (!d) return <div key={`p${i}`} aria-hidden />;
                  const list = byDay[d] ?? [];
                  const holiday = data?.holidays[d];
                  const off = offs.includes(dow(d));
                  const isToday = d === data?.today;
                  const isSel = d === day;
                  return (
                    <button
                      key={d}
                      type="button"
                      onClick={() => setDay(d)}
                      aria-pressed={isSel}
                      aria-label={`${formatDate(d)}: ${list.length} on leave${holiday ? `, ${holiday}` : ''}`}
                      className={clsx(
                        'group flex min-h-[3.5rem] flex-col rounded-xl border p-1.5 text-left transition sm:min-h-[6.5rem]',
                        isSel ? 'border-brand-500 bg-brand-50/60 ring-2 ring-brand-500/20 dark:bg-brand-500/10' : 'border-slate-100 hover:border-brand-200 hover:bg-slate-50 dark:border-slate-800 dark:hover:bg-slate-800/50',
                        (holiday || off) && !isSel && 'bg-slate-50/80 dark:bg-slate-800/30',
                      )}
                    >
                      <div className="flex items-center justify-between gap-1">
                        <span className={clsx('inline-flex h-6 w-6 items-center justify-center rounded-full text-xs font-semibold', isToday ? 'bg-brand-800 text-white dark:bg-brand-500' : off || holiday ? 'text-slate-400' : 'text-slate-700 dark:text-slate-200')}>
                          {Number(d.slice(8))}
                        </span>
                        {list.length > 0 && <span className="rounded-full bg-slate-900/5 px-1.5 text-[10px] font-semibold text-slate-600 dark:bg-white/10 dark:text-slate-300 sm:hidden">{list.length}</span>}
                      </div>
                      {holiday && <span className="mt-0.5 hidden truncate text-[10px] font-medium text-amber-700 dark:text-amber-300 sm:block" title={holiday}>{holiday}</span>}
                      <div className="mt-1 hidden space-y-0.5 sm:block">
                        {list.slice(0, holiday ? 2 : 3).map((l) => (
                          <span
                            key={l.id}
                            className={clsx('block truncate rounded-md px-1.5 py-px text-[10px] font-medium ring-1 ring-inset', leaveChip[leaveTone(l.color)], l.status === 'pending' && 'border border-dashed border-current opacity-80')}
                            title={`${l.name} · ${l.leave_type_name}${l.status === 'pending' ? ' (pending)' : ''}`}
                          >
                            {shortName(l.name)}
                          </span>
                        ))}
                        {list.length > (holiday ? 2 : 3) && <span className="block px-1 text-[10px] font-semibold text-slate-500">+{list.length - (holiday ? 2 : 3)} more</span>}
                      </div>
                      <div className="mt-auto flex gap-0.5 sm:hidden">
                        {list.slice(0, 4).map((l) => <span key={l.id} className={clsx('h-1.5 w-1.5 rounded-full', toneClasses[leaveTone(l.color)].bar)} />)}
                      </div>
                    </button>
                  );
                })}
              </div>
              {typesInView.length > 0 && (
                <div className="mt-4 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                  {typesInView.map(([code, t]) => (
                    <span key={code} className={clsx('rounded-full px-2 py-0.5 font-medium ring-1 ring-inset', leaveChip[leaveTone(t.color)])}>{t.name}</span>
                  ))}
                  {pending && <span className="rounded-full border border-dashed border-slate-400 px-2 py-0.5">Dashed = pending</span>}
                </div>
              )}
            </>
          )}
        </div>
      </Card>

      <Card className="h-fit xl:sticky xl:top-20">
        <CardHeader title={formatDate(day)} subtitle={`${selected.length} ${selected.length === 1 ? 'person' : 'people'} on leave`} icon={CalendarDays} />
        <div className="p-2">
          {data?.holidays[day] && (
            <p className="mx-2 mb-2 mt-1 flex items-center gap-1.5 rounded-lg bg-amber-50 px-3 py-2 text-xs font-medium text-amber-800 dark:bg-amber-500/10 dark:text-amber-200">
              <Sun className="h-3.5 w-3.5" /> {data.holidays[day]}
            </p>
          )}
          {selected.length ? (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {selected.map((l) => (
                <li key={l.id}>
                  <div className="flex items-start gap-3 rounded-xl px-3 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <Avatar name={l.name} src={l.photo} size="sm" />
                    <div className="min-w-0 flex-1">
                      <Link to={profilePath(l.employee_type, l.employee_id)} className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{l.name}</Link>
                      <p className="truncate text-xs text-slate-500">{l.designation}{l.department_code ? ` · ${l.department_code}` : ''}</p>
                      <div className="mt-1.5 flex flex-wrap items-center gap-1.5">
                        <span className={clsx('badge ring-1 ring-inset', leaveChip[leaveTone(l.color)])}>{l.leave_type_name}</span>
                        {l.status !== 'approved' && <StatusBadge status={l.status} />}
                      </div>
                      <button type="button" onClick={() => onOpen(l.id)} className="mt-1 text-left text-[11px] text-slate-500 hover:text-brand-700 dark:hover:text-white">
                        {formatDate(l.from_date)}{l.to_date !== l.from_date ? ` – ${formatDate(l.to_date)}` : ''} · {daysLabel(l.days)}{l.is_half_day ? ' (half)' : ''} · <span className="underline">details</span>
                      </button>
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState icon={CalendarRange} title={data?.holidays[day] || offs.includes(dow(day)) ? 'Non-working day' : 'Everyone is in'} description={data?.holidays[day] || offs.includes(dow(day)) ? 'Holidays and weekly offs are not counted as leave.' : 'No approved or pending leave on this day.'} className="!py-8" />
          )}
        </div>
      </Card>
    </div>
  );
}
