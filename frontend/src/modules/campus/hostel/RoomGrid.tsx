import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { Building, LayoutGrid, Printer, Snowflake, Wrench } from 'lucide-react';
import { Alert, Button, Card, EmptyState, SearchInput, Skeleton } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatNumber } from '@/lib/format';
import { BedDots, HostelTypeBadge, LegendDot, OccupancyBar, ROOM_TYPES, RoomTypeIcon, floorLabel, floorShort, openHostelPrint, roomTone, type GridRoom, type HostelGrid, type HostelStat } from './shared';

type OccFilter = 'all' | 'vacant' | 'partial' | 'full' | 'maintenance' | 'free';

const occFilters: { key: OccFilter; label: string }[] = [
  { key: 'all', label: 'All rooms' },
  { key: 'free', label: 'Has free bed' },
  { key: 'vacant', label: 'Vacant' },
  { key: 'partial', label: 'Partly occupied' },
  { key: 'full', label: 'Full' },
  { key: 'maintenance', label: 'Maintenance' },
];

function matches(r: GridRoom, f: OccFilter) {
  const off = r.status === 'maintenance' || r.status === 'inactive';
  switch (f) {
    case 'vacant': return !off && r.occupied === 0;
    case 'partial': return !off && r.occupied > 0 && r.occupied < r.capacity;
    case 'full': return r.capacity > 0 && r.occupied >= r.capacity;
    case 'maintenance': return off || r.beds.some((b) => b.status === 'maintenance');
    case 'free': return !off && r.free > 0;
    default: return true;
  }
}

/**
 * Visual room map of one hostel: blocks → floors (top floor first) → room tiles coloured by occupancy, with bed dots.
 * Clicking a tile opens the room drawer.
 */
export function RoomGrid({ hostels, hostelId, onHostel, onRoom }: { hostels: HostelStat[]; hostelId: number | null; onHostel: (id: number) => void; onRoom: (id: number) => void }) {
  const { data, isLoading, error } = useApi<HostelGrid>(['hostel', 'grid', hostelId], `hostel/grid/${hostelId}`, undefined, { enabled: !!hostelId });
  const [occ, setOcc] = useState<OccFilter>('all');
  const [q, setQ] = useState('');
  const [blockId, setBlockId] = useState<number | 'all'>('all');
  const hostel = hostels.find((h) => h.id === hostelId) ?? null;

  const blocks = useMemo(() => {
    if (!data) return [];
    const term = q.trim().toLowerCase();
    const rooms = data.rooms.filter(
      (r) =>
        matches(r, occ) &&
        (!term || r.room_no.toLowerCase().includes(term) || r.beds.some((b) => b.occupant && `${b.occupant.name} ${b.occupant.uid}`.toLowerCase().includes(term))),
    );
    const groups = [...data.blocks.map((b) => ({ id: b.id as number | null, name: b.name, floors: b.floors })), { id: null, name: 'Rooms without a block', floors: 0 }];
    return groups
      .filter((g) => blockId === 'all' || g.id === blockId)
      .map((g) => {
        const all = data.rooms.filter((r) => r.block_id === g.id);
        const list = rooms.filter((r) => r.block_id === g.id);
        const floors = new Map<number, GridRoom[]>();
        list.forEach((r) => floors.set(r.floor_no, [...(floors.get(r.floor_no) ?? []), r]));
        const beds = all.flatMap((r) => r.beds);
        return {
          ...g,
          total: all.length,
          occupied: beds.filter((b) => b.status === 'occupied').length,
          free: beds.filter((b) => b.status === 'available').length,
          maintenance: beds.filter((b) => b.status === 'maintenance').length,
          floors: [...floors.entries()].sort((a, b) => b[0] - a[0]),
        };
      })
      .filter((g) => g.total > 0);
  }, [data, occ, q, blockId]);

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
          <div className="pill-tabs min-w-0 max-w-full overflow-x-auto scrollbar-none" role="tablist" aria-label="Hostel">
            {hostels.map((h) => (
              <button key={h.id} type="button" role="tab" aria-selected={h.id === hostelId} onClick={() => { onHostel(h.id); setBlockId('all'); }} className={clsx('pill-tab whitespace-nowrap', h.id === hostelId && 'pill-tab-active')}>
                {h.name}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap items-center gap-2 lg:ml-auto lg:shrink-0 lg:flex-nowrap">
            <SearchInput value={q} onChange={setQ} placeholder="Room no or resident…" size="sm" className="w-full sm:w-56" />
            {hostel && (
              <Button size="sm" variant="secondary" icon={Printer} onClick={() => openHostelPrint(`register/${hostel.id}`, { block_id: blockId === 'all' ? undefined : blockId })}>
                Occupancy register
              </Button>
            )}
          </div>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {data && data.blocks.length > 1 && (
            <select
              className="form-input form-input-sm !w-auto"
              aria-label="Block"
              value={String(blockId)}
              onChange={(e) => setBlockId(e.target.value === 'all' ? 'all' : Number(e.target.value))}
            >
              <option value="all">All blocks</option>
              {data.blocks.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          )}
          <div className="flex flex-wrap gap-1.5" role="group" aria-label="Occupancy filter">
            {occFilters.map((f) => (
              <button
                key={f.key}
                type="button"
                aria-pressed={occ === f.key}
                onClick={() => setOcc(f.key)}
                className={clsx(
                  'rounded-full border px-3 py-1 text-xs font-medium transition',
                  occ === f.key ? 'border-brand-700 bg-brand-700 text-white dark:border-brand-400 dark:bg-brand-500' : 'border-slate-200 text-slate-600 hover:border-brand-300 dark:border-slate-700 dark:text-slate-300',
                )}
              >
                {f.label}
              </button>
            ))}
          </div>
          <div className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400 xl:ml-auto">
            <LegendDot className="bg-emerald-400">Vacant</LegendDot>
            <LegendDot className="bg-cyan-400">Partly occupied</LegendDot>
            <LegendDot className="bg-brand-600">Full</LegendDot>
            <LegendDot className="bg-amber-400">Maintenance</LegendDot>
          </div>
        </div>
      </div>

      <div className="p-4">
        {!hostelId ? (
          <EmptyState icon={LayoutGrid} title="Choose a hostel" description="Select a hostel above to see its room map." />
        ) : error ? (
          <Alert variant="error" title="Unable to load the room map">{(error as Error).message}</Alert>
        ) : isLoading || !data ? (
          <div className="space-y-4">
            {Array.from({ length: 3 }).map((_, i) => (
              <div key={i} className="flex gap-2">
                <Skeleton className="h-16 w-16" />
                {Array.from({ length: 8 }).map((__, j) => <Skeleton key={j} className="h-16 w-20" />)}
              </div>
            ))}
          </div>
        ) : data.rooms.length === 0 ? (
          <EmptyState icon={LayoutGrid} title="No rooms in this hostel yet" description="Add rooms one by one or create a whole floor at once from the Rooms tab." />
        ) : blocks.length === 0 ? (
          <EmptyState icon={LayoutGrid} title="No rooms match" description="Try another filter or clear the search." action={<Button variant="secondary" size="sm" onClick={() => { setOcc('all'); setQ(''); setBlockId('all'); }}>Clear filters</Button>} />
        ) : (
          <div className="space-y-6">
            {hostel && (
              <div className="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <HostelTypeBadge type={hostel.type} />
                <span className="text-slate-600 dark:text-slate-300"><strong className="text-slate-900 dark:text-white">{formatNumber(hostel.occupied)}</strong> of {formatNumber(hostel.beds)} beds occupied</span>
                <span className="text-slate-600 dark:text-slate-300"><strong className="text-accent-700 dark:text-accent-400">{formatNumber(hostel.available)}</strong> free</span>
                {hostel.warden_name && <span className="text-slate-500">Warden: {hostel.warden_name}</span>}
              </div>
            )}
            {blocks.map((b) => (
              <section key={String(b.id)} aria-label={b.name}>
                <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                  <h3 className="flex items-center gap-2 font-display text-sm font-semibold text-slate-900 dark:text-white">
                    <Building className="h-4 w-4 text-brand-700 dark:text-brand-300" /> {b.name}
                  </h3>
                  <span className="text-xs text-slate-500">{b.total} rooms · {b.occupied}/{b.occupied + b.free + b.maintenance} beds occupied</span>
                  <OccupancyBar occupied={b.occupied} available={b.free} maintenance={b.maintenance} className="w-40" />
                </div>
                {b.floors.length === 0 ? (
                  <p className="text-sm text-slate-500">No rooms match in this block.</p>
                ) : (
                  <div className="space-y-2.5">
                    {b.floors.map(([floor, rooms]) => (
                      <div key={floor} className="flex gap-3">
                        <div className="flex w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-slate-100 py-2 text-center dark:bg-slate-800" title={floorLabel(floor)}>
                          <span className="font-display text-base font-bold text-slate-700 dark:text-slate-200">{floorShort(floor)}</span>
                          <span className="text-[10px] uppercase tracking-wide text-slate-400">floor</span>
                        </div>
                        <div className="grid min-w-0 flex-1 grid-cols-[repeat(auto-fill,minmax(76px,1fr))] gap-2">
                          {rooms.map((r) => {
                            const t = roomTone(r);
                            const residents = r.beds.filter((x) => x.occupant).map((x) => x.occupant!.name);
                            return (
                              <button
                                key={r.id}
                                type="button"
                                onClick={() => onRoom(r.id)}
                                title={`Room ${r.room_no} · ${ROOM_TYPES[r.room_type] ?? r.room_type} · ${t.label}${residents.length ? `\n${residents.join(', ')}` : ''}`}
                                className={clsx(
                                  'min-w-0 rounded-xl border px-2 py-1.5 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                                  t.tile,
                                )}
                              >
                                <span className={clsx('flex items-center justify-between text-xs font-bold', t.text)}>
                                  {r.room_no}
                                  {r.status === 'maintenance' ? <Wrench className="h-3 w-3" aria-label="Maintenance" /> : r.is_ac ? <Snowflake className="h-3 w-3" aria-label="AC" /> : <RoomTypeIcon type={r.room_type} className="h-3 w-3 opacity-60" />}
                                </span>
                                <span className="mt-0.5 block text-[10px] text-slate-500 dark:text-slate-400">{r.occupied}/{r.capacity} · {ROOM_TYPES[r.room_type]?.split(' ')[0] ?? r.room_type}</span>
                                <BedDots beds={r.beds} className="mt-1" />
                              </button>
                            );
                          })}
                        </div>
                      </div>
                    ))}
                  </div>
                )}
              </section>
            ))}
          </div>
        )}
      </div>
    </Card>
  );
}
