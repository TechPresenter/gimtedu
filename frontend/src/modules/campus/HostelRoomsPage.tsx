import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  BedDouble, BedSingle, Building, Building2, CheckCircle2, DoorOpen, Eye, LayoutGrid, Layers, MapPin, Pencil, Phone, Printer, UserPlus, Wrench,
} from 'lucide-react';
import { Alert, Badge, Button, Card, CardSkeleton, EmptyState, PageHeader, StatTile, Stagger, StatusBadge, Tabs, useToast } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatMoney, formatNumber } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';
import { AllocateModal, useHostelRefresh, type AllocateDefaults } from './hostel/AllocationDialogs';
import { AllocationDrawer } from './hostel/AllocationDrawer';
import { GenerateRoomsModal } from './hostel/GenerateRoomsModal';
import { RoomDrawer } from './hostel/RoomDrawer';
import { RoomGrid } from './hostel/RoomGrid';
import { HOSTEL_AMENITIES, HostelTypeBadge, OccupancyBar, ROOM_TYPES, floorLabel, openHostelPrint, type HostelStat } from './hostel/shared';

const BED_STATUS: Record<string, string> = { available: 'Available', occupied: 'Occupied', maintenance: 'Under maintenance' };
const BED_COLORS: Record<string, BadgeColor> = { available: 'green', occupied: 'blue', maintenance: 'amber' };

const TABS = [
  { key: 'grid', label: 'Room map', icon: LayoutGrid },
  { key: 'rooms', label: 'Rooms', icon: DoorOpen },
  { key: 'beds', label: 'Beds', icon: BedSingle },
  { key: 'blocks', label: 'Blocks & floors', icon: Building },
  { key: 'hostels', label: 'Hostels', icon: Building2 },
];

export default function HostelRoomsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useHostelRefresh();
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? String(params.get('tab')) : 'grid';
  const { data, isLoading, error } = useApi<{ hostels: HostelStat[] }>(['hostel', 'overview'], 'hostel/overview');
  const hostels = data?.hostels ?? [];
  const activeHostels = hostels.filter((h) => h.status === 'active');
  const paramHostel = Number(params.get('hostel')) || null;
  const hostelId = paramHostel && hostels.some((h) => h.id === paramHostel) ? paramHostel : activeHostels[0]?.id ?? hostels[0]?.id ?? null;
  const [roomId, setRoomId] = useState<number | null>(Number(params.get('room')) || null);
  const [allocationId, setAllocationId] = useState<number | null>(null);
  const [allocate, setAllocate] = useState<AllocateDefaults | null>(null);
  const [generate, setGenerate] = useState(false);
  const [editHostel, setEditHostel] = useState<number | null | 'new'>(null);
  const { data: hostelMeta } = useCrudMeta('hostels');

  useEffect(() => {
    const r = Number(params.get('room')) || null;
    if (r) setRoomId(r);
  }, [params]);

  const setParam = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null ? next.delete(k) : next.set(k, v)));
    if (patch.tab !== undefined) [...next.keys()].filter((k) => k === 'q' || k === 'page' || k.startsWith('f.')).forEach((k) => next.delete(k));
    setParams(next, { replace: true });
  };
  const closeRoom = () => {
    setRoomId(null);
    if (params.get('room')) setParam({ room: null });
  };

  const totals = hostels.reduce(
    (t, h) => ({ rooms: t.rooms + h.rooms, beds: t.beds + h.beds, occupied: t.occupied + h.occupied, available: t.available + h.available, maintenance: t.maintenance + h.maintenance }),
    { rooms: 0, beds: 0, occupied: 0, available: 0, maintenance: 0 },
  );

  const setBedStatus = async (bedId: number, status: 'available' | 'maintenance') => {
    try {
      const res = await api.post(`hostel/beds/${bedId}/status`, { status });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Hostels & Rooms"
        description="Hostels, blocks, floors, rooms and beds with live occupancy. Click a room for residents and actions."
        breadcrumbs={[{ label: 'Hostel', to: '/hostel' }, { label: 'Hostels & Rooms' }]}
        actions={
          <>
            {can('hostel', 'create') && <Button variant="secondary" icon={Layers} onClick={() => setGenerate(true)} disabled={!hostels.length}>Add rooms in bulk</Button>}
            {can('hostel', 'create') && <Button icon={UserPlus} onClick={() => setAllocate({})}>Allot room</Button>}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatTile key="h" label="Hostels" icon={Building2} tone="navy" loading={isLoading} value={hostels.length} sub={`${activeHostels.length} active`} />,
          <StatTile key="r" label="Rooms" icon={DoorOpen} tone="blue" loading={isLoading} value={totals.rooms} sub={`${formatNumber(hostels.reduce((s, h) => s + h.blocks, 0))} blocks`} />,
          <StatTile key="b" label="Total beds" icon={BedDouble} tone="purple" loading={isLoading} value={totals.beds} sub="all hostels" />,
          <StatTile key="o" label="Occupied" icon={CheckCircle2} tone="cyan" loading={isLoading} value={totals.occupied} sub={totals.beds ? `${((totals.occupied / totals.beds) * 100).toFixed(1)}% full` : undefined} />,
          <StatTile key="f" label="Free beds" icon={BedSingle} tone="green" loading={isLoading} value={totals.available} sub="ready to allot" />,
          <StatTile key="m" label="Maintenance" icon={Wrench} tone="amber" loading={isLoading} value={totals.maintenance} sub="beds blocked" />,
        ]}
      </Stagger>

      <Tabs tabs={TABS} value={tab} onChange={(k) => setParam({ tab: k })} className="mb-5" />

      {error && <Alert variant="error" title="Unable to load hostels" className="mb-4">{(error as Error).message}</Alert>}

      {tab === 'grid' &&
        (isLoading ? (
          <CardSkeleton lines={8} />
        ) : hostels.length === 0 ? (
          <Card>
            <EmptyState
              icon={Building2}
              title="No hostels yet"
              description="Add your first hostel, then its blocks and rooms. Beds are created automatically from each room's capacity."
              action={can('hostel', 'create') && <Button icon={Building2} onClick={() => setEditHostel('new')}>Add hostel</Button>}
            />
          </Card>
        ) : (
          <RoomGrid hostels={hostels} hostelId={hostelId} onHostel={(id) => setParam({ hostel: String(id) })} onRoom={setRoomId} />
        ))}

      {tab === 'rooms' && (
        <CrudTable
          module="hostel_rooms"
          urlState
          title="All rooms"
          addLabel="Add room"
          emptyText="Add rooms one by one, or use “Add rooms in bulk” to create whole floors with beds."
          onView={(r) => setRoomId(Number(r.id))}
          toolbar={can('hostel', 'create') && <Button size="sm" variant="secondary" icon={Layers} onClick={() => setGenerate(true)}>Bulk add</Button>}
          renderers={{
            room_no: (r) => (
              <button type="button" onClick={() => setRoomId(Number(r.id))} className="whitespace-nowrap text-left leading-tight">
                <span className="block font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.room_no)}</span>
                <span className="block text-xs text-slate-500 dark:text-slate-400">{String(r.room_label ?? '')}</span>
              </button>
            ),
            room_type: (r) => <span className="whitespace-nowrap">{ROOM_TYPES[String(r.room_type)] ?? String(r.room_type)}</span>,
            floor_no: (r) => <span className="whitespace-nowrap">{floorLabel(r.floor_no)}</span>,
            occupied: (r) => {
              // free beds of a room under maintenance / inactive cannot be allotted: show them as blocked, not free
              const off = r.status === 'maintenance' || r.status === 'inactive';
              return (
                <div className="min-w-[5.5rem]">
                  <span className="text-xs font-medium tabular-nums text-slate-700 dark:text-slate-200">{Number(r.occupied)}/{Number(r.capacity)}</span>
                  <OccupancyBar
                    occupied={Number(r.occupied)}
                    available={off ? 0 : Number(r.free_beds)}
                    maintenance={off ? Number(r.free_beds) + Number(r.maintenance_beds) : Number(r.maintenance_beds)}
                    className="mt-1"
                  />
                </div>
              );
            },
          }}
          hideColumns={['capacity']}
          rowMenu={(r) => [
            { label: 'Open room', icon: Eye, onClick: () => setRoomId(Number(r.id)) },
            can('hostel', 'create') && Number(r.free_beds) > 0 && r.status === 'available' && { label: 'Allot a free bed', icon: UserPlus, onClick: () => setAllocate({ hostel_id: Number(r.hostel_id), room_id: Number(r.id) }) },
            { label: 'Show on room map', icon: LayoutGrid, onClick: () => setParam({ tab: 'grid', hostel: String(r.hostel_id) }) },
          ]}
          onSaved={() => void refresh()}
        />
      )}

      {tab === 'beds' && (
        <CrudTable
          module="hostel_beds"
          urlState
          title="All beds"
          addLabel="Add bed"
          emptyText="Beds are created automatically when you add rooms. Add an extra bed to a room here."
          onView={(r) => setRoomId(Number(r.room_id))}
          renderers={{
            bed_label: (r) => (
              <button type="button" onClick={() => setRoomId(Number(r.room_id))} className="whitespace-nowrap text-left leading-tight">
                <span className="block font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.bed_label)}</span>
                <span className="block text-xs text-slate-500 dark:text-slate-400">{String(r.location ?? '')}</span>
              </button>
            ),
            resident_name: (r) =>
              r.allocation_id ? (
                <button type="button" onClick={() => setAllocationId(Number(r.allocation_id))} className="text-left">
                  <span className="block font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.resident_name)}</span>
                  <span className="block text-xs text-slate-500">{String(r.student_uid)}{r.program_short ? ` · ${r.program_short}` : ''}</span>
                </button>
              ) : (
                <span className="text-slate-400">—</span>
              ),
            room_type: (r) => <span className="whitespace-nowrap">{ROOM_TYPES[String(r.room_type)] ?? String(r.room_type)}</span>,
            status: (r) =>
              r.status === 'available' && (r.room_status === 'maintenance' || r.room_status === 'inactive') ? (
                <StatusBadge status="maintenance" label={r.room_status === 'maintenance' ? 'Room under repair' : 'Room inactive'} colors={{ maintenance: 'amber' }} />
              ) : (
                <StatusBadge status={String(r.status)} label={BED_STATUS[String(r.status)]} colors={BED_COLORS} />
              ),
          }}
          rowMenu={(r) => [
            r.allocation_id &&{ label: 'View allocation', icon: Eye, onClick: () => setAllocationId(Number(r.allocation_id)) },
            can('hostel', 'create') && r.status === 'available' && r.room_status === 'available' && { label: 'Allot this bed', icon: UserPlus, onClick: () => setAllocate({ hostel_id: Number(r.hostel_id), room_id: Number(r.room_id), bed_id: Number(r.id) }) },
            can('hostel', 'edit') && r.status === 'available' && { label: 'Mark under maintenance', icon: Wrench, onClick: () => void setBedStatus(Number(r.id), 'maintenance') },
            can('hostel', 'edit') && r.status === 'maintenance' && { label: 'Mark available', icon: CheckCircle2, onClick: () => void setBedStatus(Number(r.id), 'available') },
            { label: 'Open room', icon: DoorOpen, onClick: () => setRoomId(Number(r.room_id)) },
          ]}
          onSaved={() => void refresh()}
        />
      )}

      {tab === 'blocks' && (
        <CrudTable
          module="hostel_blocks"
          urlState
          title="Blocks / buildings"
          addLabel="Add block"
          emptyText="Blocks group rooms inside a hostel (e.g. Block A, Block B) and define how many floors they have."
          renderers={{
            occupied_count: (r) => (
              <div className="min-w-[6rem]">
                <span className="text-xs font-medium tabular-nums">{Number(r.occupied_count)}/{Number(r.beds_count)}</span>
                <OccupancyBar occupied={Number(r.occupied_count)} available={Math.max(0, Number(r.beds_count) - Number(r.occupied_count))} maintenance={0} className="mt-1" />
              </div>
            ),
          }}
          rowMenu={(r) => [
            { label: 'Show on room map', icon: LayoutGrid, onClick: () => setParam({ tab: 'grid', hostel: String(r.hostel_id) }) },
            { label: 'Print occupancy register', icon: Printer, onClick: () => openHostelPrint(`register/${r.hostel_id}`, { block_id: Number(r.id) }) },
          ]}
          onSaved={() => void refresh()}
        />
      )}

      {tab === 'hostels' && (
        <div className="space-y-6">
          {isLoading ? (
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">{[0, 1, 2].map((i) => <CardSkeleton key={i} lines={5} />)}</div>
          ) : hostels.length > 0 ? (
            <Stagger className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3" step={70}>
              {hostels.map((h) => (
                <Card key={h.id} className="flex h-full flex-col overflow-hidden transition duration-200 hover:-translate-y-0.5 hover:shadow-soft">
                  <div className="relative h-32 bg-gradient-to-br from-brand-800 to-brand-950">
                    {h.image && <img src={appUrl(h.image)} alt="" loading="lazy" className="h-full w-full object-cover opacity-80" />}
                    <div className="absolute inset-0 bg-gradient-to-t from-brand-950/80 via-brand-950/20 to-transparent" />
                    <div className="absolute bottom-3 left-4 right-4 flex items-end justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate font-display text-lg font-bold text-white">{h.name}</p>
                        <p className="text-xs text-white/80">{h.code} · {h.blocks} block{h.blocks === 1 ? '' : 's'} · {h.total_floors || '—'} floors</p>
                      </div>
                      <div className="flex shrink-0 gap-1">
                        <HostelTypeBadge type={h.type} />
                        {h.status !== 'active' && <StatusBadge status={h.status} />}
                      </div>
                    </div>
                  </div>
                  <div className="flex flex-1 flex-col gap-3 p-4">
                    <div className="grid grid-cols-3 gap-2 text-center">
                      <div className="rounded-xl bg-slate-50 py-2 dark:bg-slate-800/60"><p className="font-display text-lg font-bold text-slate-900 dark:text-white">{h.rooms}</p><p className="text-[11px] text-slate-500">Rooms</p></div>
                      <div className="rounded-xl bg-slate-50 py-2 dark:bg-slate-800/60"><p className="font-display text-lg font-bold text-slate-900 dark:text-white">{h.occupied}</p><p className="text-[11px] text-slate-500">Residents</p></div>
                      <div className="rounded-xl bg-slate-50 py-2 dark:bg-slate-800/60"><p className="font-display text-lg font-bold text-accent-700 dark:text-accent-400">{h.available}</p><p className="text-[11px] text-slate-500">Free beds</p></div>
                    </div>
                    <div>
                      <div className="mb-1 flex justify-between text-xs"><span className="text-slate-500">Occupancy</span><span className="font-semibold text-slate-800 dark:text-slate-100">{h.occupancy_pct}%</span></div>
                      <OccupancyBar occupied={h.occupied} available={h.available} maintenance={h.maintenance} />
                    </div>
                    <dl className="space-y-1.5 text-sm text-slate-600 dark:text-slate-300">
                      {h.warden_name && <div className="flex items-center gap-2"><Phone className="h-3.5 w-3.5 shrink-0 text-slate-400" /><span className="truncate">Warden {h.warden_name}{h.warden_phone ? ` · ${h.warden_phone}` : ''}</span></div>}
                      {h.address && <div className="flex items-center gap-2"><MapPin className="h-3.5 w-3.5 shrink-0 text-slate-400" /><span className="truncate" title={h.address}>{h.address}</span></div>}
                      <div className="flex items-center gap-2"><BedDouble className="h-3.5 w-3.5 shrink-0 text-slate-400" /><span>Rent {h.annual_fee ? formatMoney(h.annual_fee) : '—'} · Mess {h.mess_fee ? formatMoney(h.mess_fee) : '—'} / year</span></div>
                    </dl>
                    {h.amenities.length > 0 && (
                      <div className="flex flex-wrap gap-1">
                        {h.amenities.slice(0, 6).map((a) => <Badge key={a} color="slate">{HOSTEL_AMENITIES[a] ?? a}</Badge>)}
                        {h.amenities.length > 6 && <Badge color="slate">+{h.amenities.length - 6} more</Badge>}
                      </div>
                    )}
                    <div className={clsx('mt-auto flex flex-wrap gap-2 border-t border-slate-100 pt-3 dark:border-slate-800')}>
                      <Button size="sm" variant="secondary" icon={LayoutGrid} onClick={() => setParam({ tab: 'grid', hostel: String(h.id) })}>Room map</Button>
                      <Button size="sm" variant="secondary" icon={Printer} onClick={() => openHostelPrint(`register/${h.id}`)}>Register</Button>
                      {can('hostel', 'edit') && <Button size="sm" variant="ghost" icon={Pencil} onClick={() => setEditHostel(h.id)}>Edit</Button>}
                    </div>
                  </div>
                </Card>
              ))}
            </Stagger>
          ) : null}
          <CrudTable
            module="hostels"
            urlState
            title="Hostel register"
            addLabel="Add hostel"
            emptyTitle="No hostels yet"
            emptyText="Add a boys or girls hostel with its warden, facilities and fees."
            renderers={{ occupancy_pct: (r) => <span className="tabular-nums">{Number(r.occupancy_pct ?? 0).toFixed(1)}%</span> }}
            rowMenu={(r) => [
              { label: 'Room map', icon: LayoutGrid, onClick: () => setParam({ tab: 'grid', hostel: String(r.id) }) },
              { label: 'Print occupancy register', icon: Printer, onClick: () => openHostelPrint(`register/${r.id}`) },
            ]}
            onSaved={() => void refresh()}
          />
        </div>
      )}

      <RoomDrawer id={roomId} onClose={closeRoom} onAllocate={(d) => setAllocate(d)} onOpenAllocation={(id) => setAllocationId(id)} />
      <AllocationDrawer id={allocationId} onClose={() => setAllocationId(null)} />
      <AllocateModal open={allocate !== null} defaults={allocate} onClose={() => setAllocate(null)} />
      <GenerateRoomsModal open={generate} onClose={() => setGenerate(false)} hostels={hostels} defaultHostelId={hostelId} />
      <CrudFormModal
        open={editHostel !== null}
        onClose={() => setEditHostel(null)}
        meta={hostelMeta}
        module="hostels"
        id={editHostel === 'new' ? null : editHostel}
        onSaved={() => void refresh()}
      />
    </>
  );
}
