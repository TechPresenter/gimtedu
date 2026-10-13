import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import {
  ArrowLeft, Armchair, Bus, Clock, IndianRupee, MessageSquareWarning, Pencil, Phone, Printer, Route as RouteIcon, UserPlus, Users,
} from 'lucide-react';
import { Alert, Button, Card, CardHeader, CardSkeleton, EmptyState, PageHeader, PersonCell, Reveal, StatTile, Stagger, StatusBadge } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import type { ApiError } from '@/lib/api';
import { formatMoney, formatMoneyShort, formatNumber, formatTime, timeAgo } from '@/lib/format';
import { COMPLAINT_COLORS, ExpiryCell, OccupancyBar, PRIORITY_COLORS, VEHICLE_TYPES, openPrint, useTransportRefresh } from './shared';
import { StopsManager } from './components/StopsManager';
import type { RouteDetail } from './types';

export default function RouteDetailPage() {
  const { id } = useParams();
  const routeId = Number(id);
  const { can } = useAuth();
  const refresh = useTransportRefresh();
  const meta = useCrudMeta('transport_routes');
  const [editOpen, setEditOpen] = useState(false);
  const { data, isLoading, error } = useApi<RouteDetail>(['transport', 'route', routeId], `transport/routes/${routeId}`, undefined, { enabled: Number.isFinite(routeId) && routeId > 0 });
  const r = data?.route;

  if (error) {
    const notFound = (error as ApiError).status === 404;
    return (
      <>
        <PageHeader title={notFound ? 'Route not found' : 'Route'} breadcrumbs={[{ label: 'Transport', to: '/transport' }, { label: 'Routes', to: '/transport/routes' }, { label: 'Route' }]} />
        <Card>
          <EmptyState icon={RouteIcon} title={notFound ? 'This route does not exist' : 'Unable to load the route'} description={notFound ? 'It may have been deleted.' : (error as Error).message}
            action={<Button icon={ArrowLeft} to="/transport/routes" variant="secondary">Back to routes</Button>} />
        </Card>
      </>
    );
  }

  return (
    <>
      <PageHeader
        title={r ? `${r.code} — ${r.name}` : 'Route'}
        description={r ? `${r.start_point ?? '—'} → ${r.end_point ?? '—'}${r.distance_km ? ` · ${formatNumber(r.distance_km, 1)} km` : ''}` : 'Loading route…'}
        breadcrumbs={[{ label: 'Transport', to: '/transport' }, { label: 'Routes', to: '/transport/routes' }, { label: r?.code ?? 'Route' }]}
        actions={
          r && (
            <>
              <Button variant="secondary" icon={Printer} onClick={() => openPrint(`transport/routes/${r.id}/print`)}>Route sheet</Button>
              {can('transport', 'edit') && <Button variant="secondary" icon={Pencil} onClick={() => setEditOpen(true)}>Edit route</Button>}
              {can('transport', 'create') && r.status === 'active' && <Button icon={UserPlus} to={`/transport/allocations?new=1&route=${r.id}`}>Allocate seat</Button>}
            </>
          )
        }
      >
        {r && r.status !== 'active' && <Alert variant="warning">This route is inactive — new seats cannot be allocated on it.</Alert>}
      </PageHeader>

      {isLoading || !data || !r ? (
        <div className="grid grid-cols-1 gap-5 lg:grid-cols-3"><CardSkeleton lines={3} /><CardSkeleton lines={3} /><CardSkeleton lines={3} /><div className="lg:col-span-3"><CardSkeleton lines={8} /></div></div>
      ) : (
        <>
          <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" step={60}>
            {[
              <div key="o" className="card p-4">
                <div className="mb-2 flex items-center gap-3">
                  <span className="kpi-icon bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300"><Armchair className="h-5 w-5" /></span>
                  <div>
                    <p className="text-xs font-medium text-slate-500 dark:text-slate-400">Seats</p>
                    <p className="font-display text-xl font-bold text-slate-900 dark:text-white">{r.load.available} free</p>
                  </div>
                </div>
                <OccupancyBar allocated={r.load.allocated} capacity={r.load.capacity} />
              </div>,
              <StatTile key="p" label="Riders" value={r.load.allocated} icon={Users} tone="blue"
                sub={`${data.by_type.student ?? 0} students · ${(data.by_type.faculty ?? 0) + (data.by_type.staff ?? 0)} staff`} />,
              <StatTile key="t" label="Timings" value={r.morning_departure ? formatTime(r.morning_departure) : '—'} icon={Clock} tone="purple"
                sub={`Return trip ${r.evening_departure ? formatTime(r.evening_departure) : '—'}`} />,
              <StatTile key="f" label="Annual fee value" value={formatMoneyShort(data.fee_total)} icon={IndianRupee} tone="orange"
                sub={r.annual_fare ? `Fares up to ${formatMoney(r.annual_fare)}/yr` : 'Stop-wise fares'} />,
            ]}
          </Stagger>

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <div className="space-y-5 xl:col-span-2">
              <Reveal>
                <StopsManager routeId={r.id} routeCode={r.code} stops={data.stops} />
              </Reveal>
              {data.unassigned.length > 0 && (
                <Alert variant="warning" title={`${data.unassigned.length} riders have no stop`}>
                  Their stop was removed. Transfer them to a stop of this route from Allocations.
                </Alert>
              )}
            </div>
            <div className="space-y-5">
              <Reveal delay={60}>
                <Card>
                  <CardHeader title="Vehicles & crew" subtitle={`${data.vehicles.length} vehicle${data.vehicles.length === 1 ? '' : 's'} on this route`} icon={Bus}
                    actions={<Link to="/transport/vehicles" className="link text-xs">Fleet</Link>} />
                  {!data.vehicles.length ? (
                    <EmptyState icon={Bus} title="No vehicle assigned" description="Assign a vehicle to this route to create seats."
                      action={can('transport', 'edit') ? <Button size="sm" to="/transport/vehicles">Assign from Vehicles</Button> : undefined} />
                  ) : (
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                      {data.vehicles.map((v) => (
                        <li key={v.id} className="space-y-3 px-5 py-4">
                          <div className="flex items-start justify-between gap-2">
                            <div>
                              <Link to={`/transport/vehicles?q=${encodeURIComponent(v.vehicle_no)}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{v.vehicle_no}</Link>
                              <p className="text-xs text-slate-500 dark:text-slate-400">{VEHICLE_TYPES[v.type] ?? v.type} · {[v.make, v.model].filter(Boolean).join(' ')}</p>
                            </div>
                            <StatusBadge status={v.status} label={v.status === 'maintenance' ? 'In workshop' : undefined} />
                          </div>
                          <OccupancyBar allocated={v.allocated} capacity={v.capacity} />
                          <div className="grid grid-cols-1 gap-2">
                            {[{ role: 'Driver', name: v.driver_name, phone: v.driver_phone, photo: v.driver_photo }, { role: 'Conductor', name: v.conductor_name, phone: v.conductor_phone, photo: v.conductor_photo }].map((c) => (
                              <div key={c.role} className="flex items-center justify-between gap-2">
                                {c.name ? <PersonCell name={c.name} sub={c.role} src={c.photo} size="sm" /> : <span className="text-xs text-slate-400">{c.role}: not assigned</span>}
                                {c.phone && (
                                  <a href={`tel:${String(c.phone).replace(/\s/g, '')}`} className="btn-icon !h-8 !w-8" aria-label={`Call ${c.name}`}>
                                    <Phone className="h-4 w-4" />
                                  </a>
                                )}
                              </div>
                            ))}
                          </div>
                          {v.next_expiry && (
                            <div className="flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
                              <span>Next document expiry</span>
                              <ExpiryCell date={v.next_expiry} compact />
                            </div>
                          )}
                        </li>
                      ))}
                    </ul>
                  )}
                </Card>
              </Reveal>
              <Reveal delay={120}>
                <Card>
                  <CardHeader title="Complaints" subtitle="Latest on this route" icon={MessageSquareWarning}
                    actions={<Link to={`/transport/maintenance?tab=complaints&f.route_id=${r.id}`} className="link text-xs">All</Link>} />
                  {!data.complaints.length ? (
                    <EmptyState icon={MessageSquareWarning} title="No complaints" description="Nothing reported for this route." className="!py-8" />
                  ) : (
                    <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                      {data.complaints.map((c) => (
                        <li key={c.id}>
                          <Link to={`/transport/maintenance?tab=complaints&complaint=${c.id}`} className="flex items-start justify-between gap-3 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                            <div className="min-w-0">
                              <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{c.subject}</p>
                              <p className="text-xs text-slate-500">{c.complaint_no} · {timeAgo(c.created_at)}</p>
                            </div>
                            <div className="flex shrink-0 flex-col items-end gap-1">
                              <StatusBadge status={c.status} colors={COMPLAINT_COLORS} dot={false} />
                              <StatusBadge status={c.priority} colors={PRIORITY_COLORS} dot={false} />
                            </div>
                          </Link>
                        </li>
                      ))}
                    </ul>
                  )}
                </Card>
              </Reveal>
              {r.description && (
                <Reveal delay={160}>
                  <Card className="p-5">
                    <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Notes</p>
                    <p className="mt-1 text-sm text-slate-700 dark:text-slate-200">{r.description}</p>
                    <p className="mt-3 flex items-center gap-2 text-[11px] text-slate-400">Status <StatusBadge status={r.status} /></p>
                  </Card>
                </Reveal>
              )}
            </div>
          </div>
        </>
      )}
      <CrudFormModal open={editOpen} onClose={() => setEditOpen(false)} meta={meta.data} module="transport_routes" id={routeId} onSaved={() => refresh('transport_routes')} />
    </>
  );
}
