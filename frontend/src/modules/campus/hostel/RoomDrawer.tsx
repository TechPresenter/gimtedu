import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { BedDouble, CheckCircle2, History, MessageSquareWarning, Pencil, Snowflake, UserPlus, Wrench } from 'lucide-react';
import { Alert, Avatar, Badge, Button, Drawer, PageLoader, StatusBadge, useToast } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney } from '@/lib/format';
import type { Row } from '@/lib/types';
import { useHostelRefresh, type AllocateDefaults } from './AllocationDialogs';
import { ALLOCATION_COLORS, ALLOCATION_STATUS, COMPLAINT_STATUS_COLORS, INVOICE_COLORS, INVOICE_LABELS, ROOM_TYPES, roomTone } from './shared';

interface RoomDetail {
  room: Row & { amenity_labels: string[]; free: number; effective_rent: number | null; floor_label: string };
  beds: Row[];
  history: Row[];
  complaints: Row[];
}

/** Room slide-over: beds with residents, allot a free bed, bed maintenance, edit room, history and complaints. */
export function RoomDrawer({ id, onClose, onAllocate, onOpenAllocation }: { id: number | null; onClose: () => void; onAllocate: (d: AllocateDefaults) => void; onOpenAllocation: (id: number) => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useHostelRefresh();
  const { data, isLoading, error } = useApi<RoomDetail>(['hostel', 'room', id], `hostel/rooms/${id}`, undefined, { enabled: !!id });
  const { data: roomMeta } = useCrudMeta('hostel_rooms');
  const [editing, setEditing] = useState(false);
  const [busyBed, setBusyBed] = useState<number | null>(null);
  const r = data?.room;
  const canEdit = can('hostel', 'edit');
  const canCreate = can('hostel', 'create');
  const roomOff = r && (r.status === 'maintenance' || r.status === 'inactive');

  const setBed = async (bedId: number, status: 'available' | 'maintenance') => {
    setBusyBed(bedId);
    try {
      const res = await api.post(`hostel/beds/${bedId}/status`, { status });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusyBed(null);
    }
  };

  const tone = r ? roomTone({ status: String(r.status), capacity: Number(r.capacity), occupied: Number(r.occupied) }) : null;
  return (
    <>
      <Drawer
        open={!!id}
        onClose={onClose}
        width="max-w-xl"
        title={r ? `Room ${r.room_no}` : 'Room'}
        description={r ? `${r.hostel_name} · ${[r.block_name, r.floor_label].filter(Boolean).join(' · ')}` : undefined}
        footer={
          r && canEdit ? (
            <Button variant="secondary" icon={Pencil} onClick={() => setEditing(true)}>
              Edit room
            </Button>
          ) : undefined
        }
      >
        {error ? (
          <Alert variant="error" title="Unable to load the room">{(error as Error).message}</Alert>
        ) : isLoading || !r ? (
          <PageLoader label="Loading room…" />
        ) : (
          <div className="space-y-6">
            <div className={clsx('rounded-2xl border p-4', tone?.tile)}>
              <div className="flex flex-wrap items-center gap-2">
                <span className={clsx('font-display text-2xl font-bold', tone?.text)}>{String(r.room_no)}</span>
                <Badge color="navy">{ROOM_TYPES[String(r.room_type)] ?? String(r.room_type)}</Badge>
                {r.is_ac && <Badge color="cyan"><Snowflake className="h-3 w-3" /> AC</Badge>}
                <span className={clsx('ml-auto text-sm font-semibold', tone?.text)}>{tone?.label}</span>
              </div>
              <div className="mt-3 grid grid-cols-3 gap-3 text-center">
                <div><p className="font-display text-xl font-bold text-slate-900 dark:text-white">{Number(r.capacity)}</p><p className="text-xs text-slate-500">Beds</p></div>
                <div><p className="font-display text-xl font-bold text-slate-900 dark:text-white">{Number(r.occupied)}</p><p className="text-xs text-slate-500">Occupied</p></div>
                <div><p className="font-display text-xl font-bold text-accent-700 dark:text-accent-400">{Number(r.free)}</p><p className="text-xs text-slate-500">Free</p></div>
              </div>
              <p className="mt-3 text-sm text-slate-600 dark:text-slate-300">
                Rent {r.effective_rent !== null ? `${formatMoney(r.effective_rent)} / year` : 'not set'}{r.annual_fee === null && r.effective_rent !== null ? ' (hostel default)' : ''}
                {r.mess_fee ? ` · Mess ${formatMoney(r.mess_fee)} / year` : ''}
              </p>
              {r.amenity_labels.length > 0 && (
                <div className="mt-3 flex flex-wrap gap-1.5">
                  {r.amenity_labels.map((a) => <span key={a} className="badge badge-slate">{a}</span>)}
                </div>
              )}
            </div>
            {roomOff && <Alert variant="warning" title={r.status === 'maintenance' ? 'Room under maintenance' : 'Room inactive'}>New students cannot be allotted here until the room is marked available (Edit room).</Alert>}

            <section>
              <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><BedDouble className="h-4 w-4 text-slate-400" /> Beds</h3>
              <ul className="space-y-2">
                {data.beds.map((b) => {
                  const bid = Number(b.id);
                  return (
                    <li key={bid} className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
                      <span
                        className={clsx(
                          'inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-sm font-bold',
                          b.status === 'occupied' ? 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200' : b.status === 'maintenance' ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-200' : 'bg-accent-100 text-accent-800 dark:bg-accent-500/20 dark:text-accent-200',
                        )}
                        aria-label={`Bed ${b.bed_no}`}
                      >
                        {String(b.bed_no)}
                      </span>
                      {b.allocation_id ? (
                        <button type="button" onClick={() => onOpenAllocation(Number(b.allocation_id))} className="flex min-w-0 flex-1 items-center gap-2.5 text-left">
                          <Avatar name={String(b.student_name)} src={b.photo as string | null} size="sm" />
                          <span className="min-w-0">
                            <span className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(b.student_name)}</span>
                            <span className="block truncate text-xs text-slate-500">
                              {String(b.student_uid)}{b.program_short ? ` · ${b.program_short}` : ''} · since {formatDate(b.allocated_on)}
                            </span>
                          </span>
                        </button>
                      ) : (
                        <span className="min-w-0 flex-1 text-sm text-slate-600 dark:text-slate-300">{b.status === 'maintenance' ? 'Under maintenance' : 'Free bed'}</span>
                      )}
                      {b.allocation_id ? (
                        <StatusBadge status={String(b.invoice_status ?? 'not_invoiced')} label={`Fee ${INVOICE_LABELS[String(b.invoice_status ?? 'not_invoiced')]?.toLowerCase()}`} colors={INVOICE_COLORS} dot={false} />
                      ) : (
                        <div className="flex gap-1.5">
                          {b.status === 'available' && canCreate && !roomOff && (
                            <Button size="xs" icon={UserPlus} onClick={() => onAllocate({ hostel_id: Number(r.hostel_id), room_id: Number(r.id), bed_id: bid })}>Allot</Button>
                          )}
                          {canEdit && (
                            <Button
                              size="xs"
                              variant="secondary"
                              loading={busyBed === bid}
                              icon={b.status === 'maintenance' ? CheckCircle2 : Wrench}
                              onClick={() => setBed(bid, b.status === 'maintenance' ? 'available' : 'maintenance')}
                            >
                              {b.status === 'maintenance' ? 'Mark available' : 'Maintenance'}
                            </Button>
                          )}
                        </div>
                      )}
                    </li>
                  );
                })}
              </ul>
            </section>

            <section>
              <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><MessageSquareWarning className="h-4 w-4 text-slate-400" /> Complaints for this room</h3>
              {data.complaints.length ? (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.complaints.map((c) => (
                    <li key={String(c.id)} className="flex items-center justify-between gap-3 py-2 text-sm">
                      <Link to={`/hostel/complaints?view=${c.id}`} className="min-w-0 truncate font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100">{String(c.subject)}</Link>
                      <span className="flex shrink-0 items-center gap-2 text-xs text-slate-500">{formatDate(c.created_at)} <StatusBadge status={String(c.status)} colors={COMPLAINT_STATUS_COLORS} dot={false} /></span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-slate-500">No complaints recorded for this room.</p>
              )}
            </section>

            <section>
              <h3 className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white"><History className="h-4 w-4 text-slate-400" /> Previous residents</h3>
              {data.history.length ? (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.history.map((h) => (
                    <li key={String(h.id)} className="flex items-center gap-3 py-2 text-sm">
                      <Avatar name={String(h.student_name)} src={h.photo as string | null} size="xs" />
                      <button type="button" onClick={() => onOpenAllocation(Number(h.id))} className="min-w-0 flex-1 truncate text-left font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100">
                        {String(h.student_name)} <span className="font-normal text-slate-500">· Bed {String(h.bed_no ?? '—')}</span>
                      </button>
                      <span className="shrink-0 text-xs text-slate-500">{formatDate(h.allocated_on)} – {formatDate(h.vacated_on)}</span>
                      <StatusBadge status={String(h.status)} label={ALLOCATION_STATUS[String(h.status)]} colors={ALLOCATION_COLORS} dot={false} />
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-slate-500">No earlier residents.</p>
              )}
            </section>
          </div>
        )}
      </Drawer>
      <CrudFormModal
        open={editing && !!r}
        onClose={() => setEditing(false)}
        meta={roomMeta}
        module="hostel_rooms"
        id={r ? Number(r.id) : null}
        onSaved={() => void refresh()}
      />
    </>
  );
}
