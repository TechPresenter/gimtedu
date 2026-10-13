import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { BadgeCheck, CalendarOff, CalendarSearch, ChevronDown, CircleDot, ClipboardCheck, ClipboardList, Clock3, History, Info, MapPin, MousePointerClick, Plus, Users } from 'lucide-react';
import { Alert, Select, useConfirm } from '@/components/ui';
import { formatDate } from '@/lib/format';
import { EmptyBlock, ErrorState, PageHead } from '../../../components/Ui';
import { usePortalQuery } from '../../../lib/hooks';
import { hhmm } from '../../../lib/time';
import type { AttendanceDayData, DayPeriod, HistoryRow } from '../../shared/types';
import { ListSkeleton, PillTabs, Stepper, addDays, fpath, shortDay, todayIso } from '../../shared/ui';
import { AttendanceHistory } from './AttendanceHistory';
import { AttendanceSheet, type SheetTarget } from './AttendanceSheet';

type Tab = 'mark' | 'history';

function PeriodButton({ p, active, onClick }: { p: DayPeriod; active: boolean; onClick: () => void }) {
  const status = p.sheet ? (
    <span className={clsx('badge', p.sheet.is_locked ? 'badge-slate' : 'badge-green')}>
      <BadgeCheck className="h-3 w-3" aria-hidden /> {p.sheet.counts.present + p.sheet.counts.late}/{p.sheet.counts.total}
    </span>
  ) : p.time_state === 'done' ? (
    <span className="badge badge-red">Not marked</span>
  ) : p.time_state === 'now' ? (
    <span className="badge badge-flame">
      <CircleDot className="h-3 w-3 animate-pulse" aria-hidden /> Now
    </span>
  ) : (
    <span className="badge badge-navy">Upcoming</span>
  );
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={clsx(
        'flex w-full items-stretch gap-3 rounded-2xl border p-3 text-left transition',
        active ? 'border-brand-900 bg-brand-50/60 ring-2 ring-brand-900/10 dark:border-brand-400 dark:bg-brand-500/10' : 'border-slate-200/80 bg-white hover:border-slate-300 hover:shadow-sm dark:border-slate-800 dark:bg-slate-900 dark:hover:border-slate-700',
      )}
    >
      <span className={clsx('flex w-14 shrink-0 flex-col items-center justify-center rounded-xl text-white', p.sheet ? 'bg-emerald-600' : p.time_state === 'done' ? 'bg-red-600' : 'bg-brand-900 dark:bg-brand-700')}>
        {p.start_time ? (
          <>
            <span className="font-display text-[13px] font-extrabold leading-none">{hhmm(p.start_time).replace(/ (AM|PM)$/, '')}</span>
            <span className="text-[9px] font-semibold uppercase text-white/75">{hhmm(p.start_time).slice(-2)}</span>
          </>
        ) : (
          <Plus className="h-4 w-4" aria-hidden />
        )}
      </span>
      <span className="min-w-0 flex-1">
        <span className="flex items-start justify-between gap-2">
          <span className="min-w-0 truncate text-sm font-bold text-slate-900 dark:text-white">{p.subject}</span>
          {status}
        </span>
        <span className="mt-0.5 block truncate text-xs text-slate-500 dark:text-slate-400">
          {p.subject_code} · {p.program} Sem {p.semester_no}-{p.section}
        </span>
        <span className="mt-0.5 flex flex-wrap gap-x-2.5 text-[11px] text-slate-400">
          <span className="inline-flex items-center gap-1">
            <Clock3 className="h-3 w-3" aria-hidden /> {p.slot}
          </span>
          {p.room_code && (
            <span className="inline-flex items-center gap-1">
              <MapPin className="h-3 w-3" aria-hidden /> {p.room_code}
            </span>
          )}
          <span className="inline-flex items-center gap-1">
            <Users className="h-3 w-3" aria-hidden /> {p.students}
          </span>
        </span>
      </span>
    </button>
  );
}

function OtherClassPicker({ data, onOpen }: { data: AttendanceDayData; onOpen: (t: Omit<SheetTarget, 'date'>) => void }) {
  const [open, setOpen] = useState(false);
  const [cls, setCls] = useState('');
  const [slot, setSlot] = useState('');
  const [section_id, subject_id] = cls ? cls.split(':').map(Number) : [0, 0];
  return (
    <div className="rounded-2xl border border-dashed border-slate-300 dark:border-slate-700">
      <button type="button" className="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-semibold text-slate-700 dark:text-slate-200" onClick={() => setOpen((o) => !o)} aria-expanded={open}>
        <span className="inline-flex items-center gap-2">
          <Plus className="h-4 w-4 text-flame-600" aria-hidden /> Extra or substitute class
        </span>
        <ChevronDown className={clsx('h-4 w-4 transition-transform', open && 'rotate-180')} aria-hidden />
      </button>
      {open && (
        <div className="space-y-3 border-t border-dashed border-slate-300 p-4 dark:border-slate-700">
          <label className="block">
            <span className="form-label">Class</span>
            <Select value={cls} onChange={(e) => setCls(e.target.value)} placeholder="Choose one of your classes" options={data.classes.map((c) => ({ value: `${c.section_id}:${c.subject_id}`, label: `${c.subject_code} · ${c.label}` }))} />
          </label>
          <label className="block">
            <span className="form-label">Period</span>
            <Select value={slot} onChange={(e) => setSlot(e.target.value)} placeholder="No fixed period" options={data.slots.map((s) => ({ value: String(s.id), label: s.label }))} />
          </label>
          <button type="button" className="btn btn-navy btn-sm w-full" disabled={!cls} onClick={() => onOpen({ section_id, subject_id, time_slot_id: slot ? Number(slot) : null })}>
            Open register
          </button>
        </div>
      )}
    </div>
  );
}

export default function AttendancePage() {
  const [params, setParams] = useSearchParams();
  const confirm = useConfirm();
  const today = todayIso();
  const tab: Tab = params.get('tab') === 'history' ? 'history' : 'mark';
  const date = /^\d{4}-\d{2}-\d{2}$/.test(params.get('date') ?? '') ? (params.get('date') as string) : today;
  const sec = Number(params.get('section_id')) || 0;
  const sub = Number(params.get('subject_id')) || 0;
  const slotParam = params.get('slot');
  const startParam = params.get('start');
  const dirtyRef = useRef(false);
  const onDirtyChange = useCallback((d: boolean) => {
    dirtyRef.current = d;
  }, []);

  const { data, isLoading, error, refetch, isFetching } = usePortalQuery<AttendanceDayData>('faculty', ['attendance', 'day'], 'attendance/day', { date });

  const update = (patch: Record<string, string | number | null | undefined>, replace = false) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null || v === undefined || v === '' ? next.delete(k) : next.set(k, String(v))));
    setParams(next, { replace });
  };

  const guard = async (): Promise<boolean> => {
    if (!dirtyRef.current) return true;
    const ok = await confirm({ title: 'Leave this register?', message: 'You have unsaved attendance marks. They will be lost if you continue.', confirmText: 'Discard changes', danger: true });
    if (ok) dirtyRef.current = false;
    return ok;
  };

  // A class chosen without a period (links from the dashboard / roster): pick the matching period of that day.
  useEffect(() => {
    if (!data || !sec || !sub || slotParam !== null || data.date !== date) return;
    const all = [...data.periods, ...data.extra];
    const matches = all.filter((p) => p.section_id === sec && p.subject_id === sub);
    const match = (startParam && matches.find((p) => p.start_time === startParam)) || matches.find((p) => !p.sheet) || matches[0];
    update({ slot: match?.time_slot_id ?? 0, start: null }, true);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data, sec, sub, slotParam, startParam, date]);

  const target: SheetTarget | null = useMemo(() => {
    if (!sec || !sub || slotParam === null) return null;
    const slot = Number(slotParam) || null;
    return { date, section_id: sec, subject_id: sub, time_slot_id: slot };
  }, [date, sec, sub, slotParam]);

  const select = async (t: Omit<SheetTarget, 'date'>) => {
    if (target && target.section_id === t.section_id && target.subject_id === t.subject_id && target.time_slot_id === t.time_slot_id) return;
    if (!(await guard())) return;
    update({ section_id: t.section_id, subject_id: t.subject_id, slot: t.time_slot_id ?? 0, start: null });
  };

  const setDate = async (d: string) => {
    if (d === date || !(await guard())) return;
    update({ date: d === today ? null : d, section_id: null, subject_id: null, slot: null, start: null });
  };

  const setTab = async (t: Tab) => {
    if (t === tab || !(await guard())) return;
    update({ tab: t === 'history' ? 'history' : null });
  };

  const openFromHistory = (r: HistoryRow) => {
    const next = new URLSearchParams();
    if (r.date !== today) next.set('date', r.date);
    next.set('section_id', String(r.section_id));
    next.set('subject_id', String(r.subject_id));
    next.set('slot', String(r.time_slot_id ?? 0));
    setParams(next);
  };

  const isSelected = (p: DayPeriod) => !!target && target.section_id === p.section_id && target.subject_id === p.subject_id && (target.time_slot_id ?? null) === (p.time_slot_id ?? null);
  const pendingToday = data ? data.periods.filter((p) => !p.sheet && p.time_state === 'done').length : 0;

  return (
    <div>
      <PageHead
        area="faculty"
        title="Attendance"
        subtitle={data ? `Mark and correct class registers. Registers can be changed for ${data.window.days} day${data.window.days === 1 ? '' : 's'}; older ones are view-only.` : 'Mark and review class registers.'}
        crumbs={[{ label: 'Dashboard', to: fpath() }, { label: 'Attendance' }]}
        actions={
          <PillTabs
            label="Attendance views"
            value={tab}
            onChange={(t) => void setTab(t)}
            tabs={[
              { value: 'mark', label: <><ClipboardCheck className="h-4 w-4" aria-hidden /> Mark</> },
              { value: 'history', label: <><History className="h-4 w-4" aria-hidden /> History</> },
            ]}
          />
        }
      />

      {tab === 'history' ? (
        <AttendanceHistory classes={data?.classes ?? []} initialClass={sec && sub ? `${sec}:${sub}` : ''} onOpen={openFromHistory} />
      ) : error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,370px)_minmax(0,1fr)]">
          {/* left: date + periods */}
          <div className={clsx('space-y-4', target && 'hidden lg:block')}>
            <div className="card p-3 sm:p-4">
              <div className="flex items-center justify-between gap-2">
                <Stepper
                  label={date === today ? 'Today' : shortDay(date)}
                  sub={formatDate(date)}
                  onPrev={() => void setDate(addDays(date, -1))}
                  onNext={() => void setDate(addDays(date, 1))}
                  nextDisabled={date >= today}
                  prevLabel="Previous day"
                  nextLabel="Next day"
                />
                <label className="relative">
                  <span className="sr-only">Pick a date</span>
                  <CalendarSearch className="pointer-events-none absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
                  <input type="date" value={date} max={today} onChange={(e) => e.target.value && void setDate(e.target.value)} className="form-input form-input-sm w-[9.5rem] pl-8" />
                </label>
              </div>
              {data && !data.window.open && (
                <p className="mt-3 flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                  <Info className="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden /> {data.window.message}
                </p>
              )}
              {data && date !== today && (
                <button type="button" className="link mt-2 text-xs" onClick={() => void setDate(today)}>
                  Jump to today
                </button>
              )}
            </div>

            {isLoading || !data ? (
              <ListSkeleton rows={4} className="h-[84px]" />
            ) : (
              <div className={clsx('space-y-3 transition-opacity', isFetching && 'opacity-70')}>
                {data.day.blocked && (
                  <Alert variant="warning" title={data.day.holiday ? data.day.holiday.title : `${data.day.day_name}`}>
                    {data.day.blocked}
                  </Alert>
                )}
                {!data.day.blocked && data.day.warning && <Alert variant="info">{data.day.warning}</Alert>}
                <div className="flex items-center justify-between px-1">
                  <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">
                    {data.day.day_name}'s periods · {data.periods.length}
                  </p>
                  {pendingToday > 0 && <span className="badge badge-red">{pendingToday} to mark</span>}
                </div>
                {data.periods.length === 0 ? (
                  <div className="card">
                    <EmptyBlock compact icon={data.day.blocked ? CalendarOff : ClipboardList} title={data.day.blocked ? 'No classes' : `No periods on ${data.day.day_name}`} text="Use “Extra or substitute class” for a class outside your timetable." />
                  </div>
                ) : (
                  <div className="space-y-2.5">
                    {data.periods.map((p) => (
                      <PeriodButton key={`${p.timetable_id}-${p.time_slot_id}`} p={p} active={isSelected(p)} onClick={() => void select(p)} />
                    ))}
                  </div>
                )}
                {data.extra.length > 0 && (
                  <>
                    <p className="px-1 pt-1 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Other registers this day</p>
                    <div className="space-y-2.5">
                      {data.extra.map((p) => (
                        <PeriodButton key={`x-${p.sheet?.id}`} p={p} active={isSelected(p)} onClick={() => void select(p)} />
                      ))}
                    </div>
                  </>
                )}
                {!data.day.blocked && data.classes.length > 0 && <OtherClassPicker data={data} onOpen={(t) => void select(t)} />}
              </div>
            )}
          </div>

          {/* right: register */}
          <div className={clsx(!target && 'hidden lg:block')}>
            {target ? (
              <AttendanceSheet key={`${target.date}-${target.section_id}-${target.subject_id}-${target.time_slot_id ?? 0}`} target={target} onDirtyChange={onDirtyChange} onBack={() => void (async () => { if (await guard()) update({ section_id: null, subject_id: null, slot: null }); })()} />
            ) : (
              <div className="card flex h-full min-h-[320px] flex-col items-center justify-center px-6 py-12 text-center">
                <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300">
                  <MousePointerClick className="h-7 w-7" aria-hidden />
                </span>
                <h2 className="mt-4 font-display text-lg font-bold text-slate-900 dark:text-white">Choose a period</h2>
                <p className="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">Select one of your periods on the left to open its register. Mark each student Present, Absent, Late or on Leave and save.</p>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
