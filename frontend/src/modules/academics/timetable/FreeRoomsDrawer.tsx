import { useEffect, useState } from 'react';
import { DoorOpen, Search, Users } from 'lucide-react';
import { Alert, Badge, Drawer, EmptyState, Field, Input, Skeleton } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { labelize } from '@/lib/format';
import type { FreeRoom, TtDay, TtSlot } from '../types';
import { currentSlotId, slotRange, todayDow } from './helpers';

const TYPES = [
  { value: '', label: 'Any type' },
  { value: 'classroom', label: 'Classrooms' },
  { value: 'lab', label: 'Laboratories' },
  { value: 'seminar_hall', label: 'Seminar halls' },
  { value: 'exam_hall', label: 'Examination halls' },
  { value: 'auditorium', label: 'Auditorium' },
];

/** Which rooms are free in a given period? */
export function FreeRoomsDrawer({ open, onClose, days, slots, sessionId }: { open: boolean; onClose: () => void; days: TtDay[]; slots: TtSlot[]; sessionId: number }) {
  const teaching = slots.filter((s) => !s.is_break);
  const [day, setDay] = useState(1);
  const [slot, setSlot] = useState(0);
  const [type, setType] = useState('');
  const [minCap, setMinCap] = useState('');

  useEffect(() => {
    if (!open) return;
    const t = todayDow();
    setDay(days.some((d) => d.no === t) ? t : days[0]?.no ?? 1);
    setSlot(currentSlotId(slots) ?? teaching[0]?.id ?? 0);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const { data, isLoading, error, isFetching } = useApi<{ rows: FreeRoom[]; total: number; busy: number }>(
    ['tt', 'free-rooms', sessionId, day, slot, type, minCap],
    'timetable/free-rooms',
    { session_id: sessionId, day, time_slot_id: slot, type: type || undefined, min_capacity: minCap || undefined },
    { enabled: open && !!slot },
  );
  const slotObj = teaching.find((s) => s.id === slot);

  return (
    <Drawer open={open} onClose={onClose} title="Free rooms finder" description="Find classrooms and labs with no class in a period." width="max-w-lg">
      <div className="grid grid-cols-2 gap-3">
        <Field label="Day" htmlFor="fr-day">
          <select id="fr-day" className="form-input" value={day} onChange={(e) => setDay(Number(e.target.value))}>
            {days.map((d) => (
              <option key={d.no} value={d.no}>{d.name}</option>
            ))}
          </select>
        </Field>
        <Field label="Period" htmlFor="fr-slot">
          <select id="fr-slot" className="form-input" value={slot} onChange={(e) => setSlot(Number(e.target.value))}>
            {teaching.map((s) => (
              <option key={s.id} value={s.id}>{s.name}</option>
            ))}
          </select>
        </Field>
        <Field label="Room type" htmlFor="fr-type">
          <select id="fr-type" className="form-input" value={type} onChange={(e) => setType(e.target.value)}>
            {TYPES.map((t) => (
              <option key={t.value} value={t.value}>{t.label}</option>
            ))}
          </select>
        </Field>
        <Field label="Min. capacity" htmlFor="fr-cap">
          <Input id="fr-cap" type="number" min={1} inputMode="numeric" placeholder="e.g. 60" value={minCap} onChange={(e) => setMinCap(e.target.value)} />
        </Field>
      </div>

      <div className="mt-5 flex items-center justify-between">
        <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">
          {slotObj ? `${days.find((d) => d.no === day)?.name}, ${slotRange(slotObj)}` : 'Choose a period'}
        </p>
        {data && (
          <span className="text-xs text-slate-500">
            <span className="font-semibold text-emerald-600 dark:text-emerald-400">{data.total} free</span> · {data.busy} in use
          </span>
        )}
      </div>

      <div className="mt-3">
        {error ? (
          <Alert variant="error">{(error as ApiError).message}</Alert>
        ) : isLoading || (isFetching && !data) ? (
          <div className="space-y-2">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-14 w-full rounded-xl" />)}</div>
        ) : !data?.rows.length ? (
          <EmptyState icon={Search} title="No free rooms" description="Every matching room is booked in this period. Try another period or relax the filters." />
        ) : (
          <ul className="space-y-2">
            {data.rows.map((r) => (
              <li key={r.id} className="flex items-center gap-3 rounded-xl border border-slate-200 px-3 py-2.5 transition hover:border-emerald-300 hover:bg-emerald-50/40 dark:border-slate-700 dark:hover:border-emerald-500/40 dark:hover:bg-emerald-500/5">
                <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300">
                  <DoorOpen className="h-4 w-4" />
                </span>
                <div className="min-w-0 flex-1">
                  <p className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
                    {r.code}
                    {r.name !== `Room ${r.code}` && <span className="truncate font-normal text-slate-500">· {r.name}</span>}
                  </p>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                    {r.building ?? '—'}
                    {r.floor ? ` · Floor ${r.floor}` : ''} · {r.periods_today} class{r.periods_today === 1 ? '' : 'es'} today
                  </p>
                </div>
                <div className="flex shrink-0 flex-col items-end gap-1">
                  <Badge color={r.type === 'lab' ? 'purple' : r.type === 'classroom' ? 'blue' : 'cyan'}>{labelize(r.type)}</Badge>
                  <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                    <Users className="h-3 w-3" /> {r.capacity}
                  </span>
                </div>
              </li>
            ))}
          </ul>
        )}
      </div>
    </Drawer>
  );
}
