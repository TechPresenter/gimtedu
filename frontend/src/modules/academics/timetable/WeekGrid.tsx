import { useMemo, useRef, useState, type DragEvent } from 'react';
import clsx from 'clsx';
import { Coffee, FlaskConical, GripVertical, MapPin, Plus, UserRound } from 'lucide-react';
import { Skeleton } from '@/components/ui';
import { useMediaQuery } from '@/lib/hooks';
import type { TtDay, TtEntry, TtSlot } from '../types';
import { hm, slotRange, todayDow, toneFor, TYPE_LABEL } from './helpers';

export type GridMode = 'class' | 'faculty' | 'room';

interface WeekGridProps {
  days: TtDay[];
  slots: TtSlot[];
  entries: TtEntry[];
  mode: GridMode;
  /** Empty cells become "add class" buttons. */
  canAdd?: boolean;
  /** Periods can be dragged to another cell. */
  canMove?: boolean;
  subjectOrder?: Map<number, number>;
  onAdd?: (day: number, slot: TtSlot) => void;
  onOpen?: (entry: TtEntry) => void;
  onMove?: (entry: TtEntry, day: number, slotId: number, target: TtEntry | null) => void;
  loading?: boolean;
}

/** Weekly timetable grid: days as columns, periods as rows (desktop) or a per-day agenda (mobile). */
export function WeekGrid(props: WeekGridProps) {
  const desktop = useMediaQuery('(min-width: 1024px)');
  const map = useMemo(() => {
    const m = new Map<string, TtEntry>();
    props.entries.forEach((e) => m.set(`${e.day_of_week}-${e.time_slot_id}`, e));
    return m;
  }, [props.entries]);
  if (props.loading) return <GridSkeleton rows={props.slots.length || 8} />;
  return desktop ? <DesktopGrid {...props} map={map} /> : <AgendaList {...props} map={map} />;
}

function GridSkeleton({ rows }: { rows: number }) {
  return (
    <div className="space-y-2 p-4">
      {Array.from({ length: Math.min(rows, 9) }).map((_, i) => (
        <div key={i} className="grid grid-cols-7 gap-2">
          {Array.from({ length: 7 }).map((__, j) => (
            <Skeleton key={j} className={clsx('h-14 rounded-xl', j === 0 && 'opacity-60')} />
          ))}
        </div>
      ))}
    </div>
  );
}

type InnerProps = WeekGridProps & { map: Map<string, TtEntry> };

function DesktopGrid({ days, slots, mode, canAdd, canMove, subjectOrder, onAdd, onOpen, onMove, map }: InnerProps) {
  const today = todayDow();
  const [dragId, setDragId] = useState<number | null>(null);
  // Ref mirrors the dragged id so dragover handlers work before React re-renders.
  const dragRef = useRef<number | null>(null);
  const [over, setOver] = useState<string | null>(null);
  const dragged = dragId ? [...map.values()].find((e) => e.id === dragId) ?? null : null;

  const drop = (e: DragEvent, day: number, slot: TtSlot) => {
    e.preventDefault();
    setOver(null);
    const id = dragRef.current ?? (Number(e.dataTransfer.getData('text/plain')) || null);
    const moving = id ? [...map.values()].find((x) => x.id === id) ?? null : null;
    dragRef.current = null;
    setDragId(null);
    if (!moving || !onMove) return;
    if (moving.day_of_week === day && moving.time_slot_id === slot.id) return;
    onMove(moving, day, slot.id, map.get(`${day}-${slot.id}`) ?? null);
  };

  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[920px] table-fixed border-separate border-spacing-1.5 px-2.5 pb-2.5" aria-label="Weekly timetable">
        <thead>
          <tr>
            <th scope="col" className="w-[104px] px-2 py-2 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-400">
              Period
            </th>
            {days.map((d) => (
              <th
                key={d.no}
                scope="col"
                className={clsx(
                  'rounded-xl px-2 py-2 text-center text-xs font-semibold',
                  d.no === today ? 'bg-brand-800 text-white dark:bg-brand-600' : 'bg-slate-50 text-slate-600 dark:bg-slate-800/60 dark:text-slate-300',
                )}
              >
                {d.name}
                {d.no === today && <span className="ml-1.5 rounded-full bg-white/20 px-1.5 py-px text-[10px] font-medium">Today</span>}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {slots.map((slot) =>
            slot.is_break ? (
              <tr key={slot.id}>
                <th scope="row" className="px-2 text-left text-[11px] font-medium text-slate-400">
                  {hm(slot.start_time)}
                </th>
                <td colSpan={days.length} className="h-7 rounded-lg bg-[repeating-linear-gradient(135deg,transparent,transparent_6px,rgba(148,163,184,.12)_6px,rgba(148,163,184,.12)_12px)] text-center">
                  <span className="inline-flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-400">
                    <Coffee className="h-3 w-3" /> {slot.name} · {slotRange(slot)}
                  </span>
                </td>
              </tr>
            ) : (
              <tr key={slot.id}>
                <th scope="row" className="px-2 py-1 text-left align-top">
                  <span className="block text-xs font-semibold text-slate-800 dark:text-slate-100">{slot.name}</span>
                  <span className="block text-[11px] text-slate-400">{hm(slot.start_time)}</span>
                  <span className="block text-[11px] text-slate-400">{hm(slot.end_time)}</span>
                </th>
                {days.map((d) => {
                  const key = `${d.no}-${slot.id}`;
                  const entry = map.get(key);
                  const isOver = over === key && dragged && dragged.id !== entry?.id;
                  return (
                    <td
                      key={key}
                      className={clsx('h-[84px] rounded-xl align-top transition-colors', d.no === today && 'bg-brand-50/40 dark:bg-brand-500/[.04]', isOver && '!bg-accent-50 ring-2 ring-accent-500 dark:!bg-accent-500/10')}
                      onDragOver={canMove ? (e) => { if (dragRef.current === null) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move'; setOver(key); } : undefined}
                      onDragLeave={canMove ? () => setOver((o) => (o === key ? null : o)) : undefined}
                      onDrop={canMove ? (e) => drop(e, d.no, slot) : undefined}
                    >
                      {entry ? (
                        <EntryCard
                          entry={entry}
                          mode={mode}
                          subjectOrder={subjectOrder}
                          draggable={!!canMove}
                          dragging={dragId === entry.id}
                          onDragStart={() => { dragRef.current = entry.id; setDragId(entry.id); }}
                          onDragEnd={() => {
                            dragRef.current = null;
                            setDragId(null);
                            setOver(null);
                          }}
                          onClick={onOpen ? () => onOpen(entry) : undefined}
                        />
                      ) : canAdd && onAdd ? (
                        <button
                          type="button"
                          onClick={() => onAdd(d.no, slot)}
                          aria-label={`Add class on ${d.name}, ${slot.name}`}
                          className="group flex h-full min-h-[84px] w-full items-center justify-center rounded-xl border border-dashed border-slate-200 text-slate-300 transition hover:border-brand-300 hover:bg-brand-50/60 hover:text-brand-600 focus-visible:border-brand-400 focus-visible:text-brand-600 dark:border-slate-700/70 dark:text-slate-600 dark:hover:border-brand-500/50 dark:hover:bg-brand-500/10 dark:hover:text-brand-300"
                        >
                          <Plus className="h-4 w-4 opacity-0 transition group-hover:opacity-100 group-focus-visible:opacity-100" />
                        </button>
                      ) : (
                        <div className="h-full min-h-[84px] rounded-xl border border-dashed border-slate-100 dark:border-slate-800/80" aria-hidden />
                      )}
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

function EntryCard({
  entry: e, mode, subjectOrder, draggable, dragging, onDragStart, onDragEnd, onClick, compact,
}: {
  entry: TtEntry;
  mode: GridMode;
  subjectOrder?: Map<number, number>;
  draggable?: boolean;
  dragging?: boolean;
  onDragStart?: () => void;
  onDragEnd?: () => void;
  onClick?: () => void;
  compact?: boolean;
}) {
  const tone = toneFor(e.subject_id, subjectOrder);
  const shortSection = `${e.program_short} · S${e.semester_no}-${e.section_name}`;
  const line2 = mode === 'class' ? e.subject_name : shortSection;
  const line3 = mode === 'room' ? e.faculty_name ?? 'Faculty TBA' : mode === 'faculty' ? e.subject_name : e.faculty_name ?? 'Faculty to be assigned';
  const label = `${e.day_name}, ${e.slot_name}: ${e.subject_code} ${e.subject_name}${e.faculty_name ? `, ${e.faculty_name}` : ''}${e.room_code ? `, room ${e.room_code}` : ''}${e.status === 'draft' ? ' (draft)' : ''}`;
  const Tag = onClick ? 'button' : 'div';
  return (
    <Tag
      {...(onClick ? { type: 'button' as const, onClick } : {})}
      aria-label={label}
      title={label}
      draggable={draggable}
      onDragStart={
        draggable
          ? (ev: DragEvent) => {
              ev.dataTransfer.effectAllowed = 'move';
              ev.dataTransfer.setData('text/plain', String(e.id));
              onDragStart?.();
            }
          : undefined
      }
      onDragEnd={draggable ? onDragEnd : undefined}
      className={clsx(
        'group relative flex h-full w-full flex-col rounded-xl border-l-[3px] px-2.5 py-2 text-left transition duration-150',
        compact ? 'min-h-[64px]' : 'min-h-[84px]',
        tone.card,
        e.status === 'draft' && 'outline-dashed outline-1 outline-offset-[-1px] outline-amber-400/80',
        onClick && 'hover:-translate-y-0.5 hover:shadow-soft focus-visible:ring-2 focus-visible:ring-brand-500 motion-reduce:transform-none',
        draggable && 'cursor-grab active:cursor-grabbing',
        dragging && 'opacity-40',
      )}
    >
      <span className="flex items-center justify-between gap-1">
        <span className={clsx('truncate text-xs font-bold', tone.text)}>{e.subject_code}</span>
        <span className="flex shrink-0 items-center gap-1">
          {e.type !== 'lecture' && (
            <span className="inline-flex items-center gap-0.5 rounded bg-white/70 px-1 text-[10px] font-semibold text-slate-600 dark:bg-slate-900/50 dark:text-slate-300">
              {e.type === 'lab' && <FlaskConical className="h-2.5 w-2.5" />}
              {TYPE_LABEL[e.type]}
            </span>
          )}
          {e.status === 'draft' && <span className="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden />}
          {draggable && <GripVertical className="h-3 w-3 text-slate-400 opacity-0 transition group-hover:opacity-100" aria-hidden />}
        </span>
      </span>
      <span className="mt-0.5 line-clamp-1 text-[11px] font-medium leading-snug text-slate-700 dark:text-slate-200">{line2}</span>
      <span className="mt-auto flex items-center justify-between gap-1 pt-1 text-[10.5px] text-slate-500 dark:text-slate-400">
        <span className="flex min-w-0 items-center gap-1 truncate">
          {mode !== 'faculty' && <UserRound className="h-3 w-3 shrink-0" />}
          <span className={clsx('truncate', !e.faculty_id && mode !== 'faculty' && 'italic text-amber-600 dark:text-amber-400')}>{line3}</span>
        </span>
        {mode !== 'room' && e.room_code && (
          <span className="inline-flex shrink-0 items-center gap-0.5 rounded bg-white/70 px-1 font-semibold text-slate-600 dark:bg-slate-900/50 dark:text-slate-300">
            <MapPin className="h-2.5 w-2.5" />
            {e.room_code}
          </span>
        )}
      </span>
    </Tag>
  );
}

/** Mobile: pick a day, then see the periods of that day as an agenda. */
function AgendaList({ days, slots, mode, canAdd, subjectOrder, onAdd, onOpen, map }: InnerProps) {
  const today = todayDow();
  const [day, setDay] = useState(days.some((d) => d.no === today) ? today : days[0]?.no ?? 1);
  const current = days.find((d) => d.no === day);
  return (
    <div className="p-3">
      <div className="mb-3 flex gap-1.5 overflow-x-auto scrollbar-none" role="tablist" aria-label="Day">
        {days.map((d) => {
          const count = slots.filter((s) => map.has(`${d.no}-${s.id}`)).length;
          return (
            <button
              key={d.no}
              type="button"
              role="tab"
              aria-selected={d.no === day}
              onClick={() => setDay(d.no)}
              className={clsx(
                'flex min-w-[3.25rem] flex-col items-center rounded-xl border px-2 py-1.5 text-xs font-semibold transition',
                d.no === day ? 'border-brand-800 bg-brand-800 text-white dark:border-brand-500 dark:bg-brand-600' : 'border-slate-200 bg-white text-slate-600 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300',
              )}
            >
              {d.short}
              <span className={clsx('text-[10px] font-medium', d.no === day ? 'text-brand-100' : 'text-slate-400')}>{count}</span>
            </button>
          );
        })}
      </div>
      <p className="mb-2 px-1 text-xs font-semibold uppercase tracking-wider text-slate-400">{current?.name}</p>
      <ol className="space-y-2">
        {slots.map((slot) => {
          if (slot.is_break) {
            return (
              <li key={slot.id} className="flex items-center gap-2 px-1 text-[11px] font-medium uppercase tracking-wider text-slate-400">
                <span className="h-px flex-1 bg-slate-200 dark:bg-slate-800" />
                <Coffee className="h-3 w-3" /> {slot.name}
                <span className="h-px flex-1 bg-slate-200 dark:bg-slate-800" />
              </li>
            );
          }
          const entry = map.get(`${day}-${slot.id}`);
          return (
            <li key={slot.id} className="flex gap-3">
              <div className="w-16 shrink-0 pt-1.5 text-right">
                <p className="text-xs font-semibold text-slate-700 dark:text-slate-200">{hm(slot.start_time)}</p>
                <p className="text-[10px] text-slate-400">{slot.name}</p>
              </div>
              <div className="min-w-0 flex-1">
                {entry ? (
                  <EntryCard entry={entry} mode={mode} subjectOrder={subjectOrder} compact onClick={onOpen ? () => onOpen(entry) : undefined} />
                ) : canAdd && onAdd ? (
                  <button
                    type="button"
                    onClick={() => onAdd(day, slot)}
                    className="flex min-h-[52px] w-full items-center justify-center gap-1.5 rounded-xl border border-dashed border-slate-200 text-xs font-medium text-slate-400 dark:border-slate-700"
                  >
                    <Plus className="h-3.5 w-3.5" /> Add class
                  </button>
                ) : (
                  <div className="flex min-h-[44px] items-center rounded-xl border border-dashed border-slate-100 px-3 text-xs text-slate-400 dark:border-slate-800">Free period</div>
                )}
              </div>
            </li>
          );
        })}
      </ol>
    </div>
  );
}
