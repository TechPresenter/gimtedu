import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { BedDouble, Check, DoorOpen, Snowflake, Wrench } from 'lucide-react';
import { Alert, EmptyState, SearchInput, Skeleton } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatMoney } from '@/lib/format';
import { BedDots, ROOM_TYPES, floorLabel, type GridRoom, type HostelGrid } from './shared';

export interface BedChoice {
  room_id: number | null;
  bed_id: number | null;
}

interface BedPickerProps {
  hostelId: number | null;
  value: BedChoice;
  onChange: (v: BedChoice, room: GridRoom | null) => void;
  /** Bed the student currently occupies (transfer) - shown but not selectable */
  currentBedId?: number | null;
  errors?: { room_id?: string; bed_id?: string };
  /** Called once the preselected room's details are loaded (e.g. to prefill the rent) */
  onRoomLoaded?: (room: GridRoom) => void;
}

/**
 * Visual free-bed picker: rooms of a hostel that still have a free bed, grouped by block and floor.
 * Only free beds can be selected; occupied beds show their resident, maintenance beds are disabled.
 */
export function BedPicker({ hostelId, value, onChange, currentBedId, errors, onRoomLoaded }: BedPickerProps) {
  const { data, isLoading, error } = useApi<HostelGrid>(['hostel', 'grid', hostelId, 'free'], `hostel/grid/${hostelId}`, { free: 1 }, { enabled: !!hostelId, staleTime: 10_000 });
  const [block, setBlock] = useState<number | 'all'>('all');
  const [q, setQ] = useState('');

  const rooms = useMemo(() => {
    const list = (data?.rooms ?? []).filter((r) => (block === 'all' || r.block_id === block) && (!q || r.room_no.toLowerCase().includes(q.toLowerCase())));
    const byFloor = new Map<number, GridRoom[]>();
    list.forEach((r) => byFloor.set(r.floor_no, [...(byFloor.get(r.floor_no) ?? []), r]));
    return [...byFloor.entries()].sort((a, b) => a[0] - b[0]);
  }, [data, block, q]);
  const selectedRoom = data?.rooms.find((r) => r.id === value.room_id) ?? null;
  const blockName = (id: number | null) => data?.blocks.find((b) => b.id === id)?.name;
  useEffect(() => {
    if (selectedRoom) onRoomLoaded?.(selectedRoom);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedRoom?.id]);

  if (!hostelId) {
    return <p className="rounded-xl border border-dashed border-slate-200 px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-700">Choose a hostel to see rooms with free beds.</p>;
  }
  if (error) return <Alert variant="error">Unable to load rooms. Please try again.</Alert>;
  if (isLoading || !data) {
    return (
      <div className="grid grid-cols-3 gap-2 sm:grid-cols-6">
        {Array.from({ length: 12 }).map((_, i) => <Skeleton key={i} className="h-14" />)}
      </div>
    );
  }
  if (!data.rooms.length) {
    return <EmptyState icon={BedDouble} title="No free beds" description={`Every bed in ${data.hostel.name} is occupied or under maintenance. Choose another hostel.`} className="py-8" />;
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        {data.blocks.length > 1 && (
          <div className="pill-tabs" role="tablist" aria-label="Block">
            {[{ id: 'all' as const, name: 'All blocks' }, ...data.blocks].map((b) => (
              <button key={String(b.id)} type="button" role="tab" aria-selected={block === b.id} onClick={() => setBlock(b.id)} className={clsx('pill-tab whitespace-nowrap', block === b.id && 'pill-tab-active')}>
                {b.name}
              </button>
            ))}
          </div>
        )}
        <SearchInput value={q} onChange={setQ} placeholder="Find room no…" size="sm" className="sm:ml-auto sm:w-44" />
      </div>

      <div className={clsx('max-h-64 space-y-3 overflow-y-auto rounded-xl border p-3', errors?.room_id ? 'border-red-300 dark:border-red-500/50' : 'border-slate-200 dark:border-slate-700')}>
        {rooms.length === 0 && <p className="py-4 text-center text-sm text-slate-500">No room matches “{q}”.</p>}
        {rooms.map(([floor, list]) => (
          <div key={floor}>
            <p className="mb-1.5 text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
              {floorLabel(floor)} · {list.length} room{list.length === 1 ? '' : 's'} with free beds
            </p>
            <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">
              {list.map((r) => {
                const active = r.id === value.room_id;
                return (
                  <button
                    key={r.id}
                    type="button"
                    onClick={() => {
                      const firstFree = r.beds.find((b) => b.status === 'available' && b.id !== currentBedId);
                      onChange({ room_id: r.id, bed_id: firstFree?.id ?? null }, r);
                    }}
                    aria-pressed={active}
                    className={clsx(
                      'rounded-xl border px-2.5 py-2 text-left transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                      active
                        ? 'border-brand-600 bg-brand-50 ring-2 ring-brand-500/30 dark:border-brand-400 dark:bg-brand-500/15'
                        : 'border-slate-200 bg-white hover:border-brand-300 hover:bg-brand-50/40 dark:border-slate-700 dark:bg-slate-900 dark:hover:border-brand-500/50',
                    )}
                  >
                    <span className="flex items-center justify-between gap-1">
                      <span className="text-sm font-semibold text-slate-900 dark:text-white">{r.room_no}</span>
                      {r.is_ac && <Snowflake className="h-3.5 w-3.5 text-cyan-600" aria-label="AC" />}
                    </span>
                    <span className="mt-0.5 block truncate text-[11px] text-slate-500 dark:text-slate-400">
                      {ROOM_TYPES[r.room_type] ?? r.room_type} · {r.free} free
                    </span>
                    <BedDots beds={r.beds} className="mt-1.5" />
                  </button>
                );
              })}
            </div>
          </div>
        ))}
      </div>
      {errors?.room_id && <p className="form-error">{errors.room_id}</p>}

      {selectedRoom && (
        <div className="rounded-xl border border-slate-200 bg-slate-50/60 p-3 dark:border-slate-700 dark:bg-slate-800/40">
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <DoorOpen className="h-4 w-4 text-brand-700 dark:text-brand-300" aria-hidden />
            <span className="font-semibold text-slate-900 dark:text-white">Room {selectedRoom.room_no}</span>
            <span className="text-slate-500 dark:text-slate-400">
              {[blockName(selectedRoom.block_id), floorLabel(selectedRoom.floor_no), ROOM_TYPES[selectedRoom.room_type], selectedRoom.is_ac ? 'AC' : 'Non-AC'].filter(Boolean).join(' · ')}
            </span>
            <span className="ml-auto font-medium text-slate-700 dark:text-slate-200">{formatMoney(selectedRoom.annual_fee ?? data.hostel.annual_fee ?? 0)}/yr</span>
          </div>
          <p className="mt-2 text-xs font-medium text-slate-500 dark:text-slate-400">Choose a bed</p>
          <div className="mt-1.5 grid grid-cols-1 gap-2 sm:grid-cols-2">
            {selectedRoom.beds.map((b) => {
              const free = b.status === 'available' && b.id !== currentBedId;
              const active = value.bed_id === b.id;
              return (
                <button
                  key={b.id}
                  type="button"
                  disabled={!free}
                  onClick={() => onChange({ room_id: selectedRoom.id, bed_id: b.id }, selectedRoom)}
                  aria-pressed={active}
                  className={clsx(
                    'flex items-center gap-2.5 rounded-lg border px-3 py-2 text-left text-sm transition',
                    active && 'border-accent-600 bg-accent-50 ring-2 ring-accent-500/30 dark:border-accent-400 dark:bg-accent-500/10',
                    !active && free && 'border-slate-200 bg-white hover:border-accent-400 dark:border-slate-700 dark:bg-slate-900',
                    !free && 'cursor-not-allowed border-slate-200 bg-slate-100/70 opacity-75 dark:border-slate-700 dark:bg-slate-800',
                  )}
                >
                  <span
                    className={clsx(
                      'inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg text-xs font-bold',
                      active ? 'bg-accent-600 text-white' : free ? 'bg-accent-100 text-accent-800 dark:bg-accent-500/20 dark:text-accent-200' : b.status === 'maintenance' ? 'bg-amber-100 text-amber-700' : 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200',
                    )}
                  >
                    {active ? <Check className="h-4 w-4" /> : b.status === 'maintenance' ? <Wrench className="h-3.5 w-3.5" /> : b.bed_no}
                  </span>
                  <span className="min-w-0">
                    <span className="block font-medium text-slate-800 dark:text-slate-100">Bed {b.bed_no}</span>
                    <span className="block truncate text-xs text-slate-500 dark:text-slate-400">
                      {b.id === currentBedId ? 'Current bed' : b.occupant ? `${b.occupant.name}${b.occupant.program ? ` · ${b.occupant.program}` : ''}` : b.status === 'maintenance' ? 'Under maintenance' : 'Free'}
                    </span>
                  </span>
                </button>
              );
            })}
          </div>
          {errors?.bed_id && <p className="form-error mt-1.5">{errors.bed_id}</p>}
        </div>
      )}
    </div>
  );
}
