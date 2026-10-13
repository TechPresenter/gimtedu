import { Link } from 'react-router-dom';
import { Bus, Fuel, Pencil, Phone, RefreshCcw, Satellite, Wrench } from 'lucide-react';
import { Alert, Badge, Button, Drawer, EmptyState, PersonCell, Skeleton, StatusBadge } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { ExpiryCell, InfoItem, MAINTENANCE_TYPES, OccupancyBar, VEHICLE_TYPES } from '../shared';
import type { VehicleDetail } from '../types';
import type { RenewTarget } from './RenewDocumentModal';

interface Props {
  vehicleId: number | null;
  onClose: () => void;
  onEdit: (id: number) => void;
  onRenew: (doc: RenewTarget) => void;
  onLogFuel: (vehicleId: number) => void;
  onAddMaintenance: (vehicleId: number) => void;
  alertDays?: number;
}

/** Vehicle profile: crew, route, statutory documents, fuel efficiency and maintenance history. */
export function VehicleDrawer({ vehicleId, onClose, onEdit, onRenew, onLogFuel, onAddMaintenance, alertDays = 30 }: Props) {
  const { can } = useAuth();
  const { data, isLoading, error } = useApi<VehicleDetail>(['transport', 'vehicle', vehicleId], `transport/vehicles/${vehicleId}`, undefined, { enabled: !!vehicleId });
  const v = data?.vehicle;
  return (
    <Drawer
      open={!!vehicleId}
      onClose={onClose}
      width="max-w-2xl"
      title={v ? v.vehicle_no : 'Vehicle'}
      description={v ? `${VEHICLE_TYPES[v.type] ?? v.type} · ${[v.make, v.model].filter(Boolean).join(' ')}${v.manufacture_year ? ` · ${v.manufacture_year}` : ''}` : undefined}
      footer={
        v ? (
          <>
            {can('transport', 'create') && <Button variant="secondary" icon={Fuel} onClick={() => onLogFuel(v.id)}>Log fuel</Button>}
            {can('transport', 'create') && <Button variant="secondary" icon={Wrench} onClick={() => onAddMaintenance(v.id)}>Add service</Button>}
            {can('transport', 'edit') && <Button icon={Pencil} onClick={() => onEdit(v.id)}>Edit</Button>}
          </>
        ) : undefined
      }
    >
      {error ? (
        <Alert variant="error">Unable to load this vehicle.</Alert>
      ) : isLoading || !data || !v ? (
        <div className="space-y-4"><Skeleton className="h-20 w-full" /><Skeleton className="h-32 w-full" /><Skeleton className="h-40 w-full" /></div>
      ) : (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={v.status} label={v.status === 'maintenance' ? 'In maintenance' : undefined} />
            <Badge color="slate">{String(v.fuel_type ?? '').toUpperCase() || 'Fuel n/a'}</Badge>
            {v.gps_device_id ? (
              <Badge color="cyan"><Satellite className="h-3 w-3" /> GPS {v.gps_device_id}</Badge>
            ) : (
              <Badge color="slate">No GPS device</Badge>
            )}
          </div>

          <section className="grid grid-cols-2 gap-4 rounded-xl border border-slate-200 p-4 sm:grid-cols-4 dark:border-slate-800">
            <InfoItem label="Route">{v.route_code ? <Link to={`/transport/routes/${v.route_id}`} className="link">{v.route_code}</Link> : 'Standby'}</InfoItem>
            <InfoItem label="Odometer">{v.current_odometer ? `${formatNumber(v.current_odometer)} km` : null}</InfoItem>
            <InfoItem label="Registered">{v.registration_date ? formatDate(v.registration_date) : null}</InfoItem>
            <InfoItem label="Seats">{formatNumber(v.capacity)}</InfoItem>
            <div className="col-span-2 sm:col-span-4">
              <OccupancyBar allocated={v.allocated} capacity={Number(v.capacity)} />
            </div>
          </section>

          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Crew</h3>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              {[
                { role: 'Driver', name: v.driver_name, phone: v.driver_phone, photo: v.driver_photo, extra: v.driver_license_expiry },
                { role: 'Conductor / attendant', name: v.conductor_name, phone: v.conductor_phone, photo: v.conductor_photo, extra: null },
              ].map((c) => (
                <div key={c.role} className="rounded-xl border border-slate-200 p-3 dark:border-slate-800">
                  <p className="mb-2 text-[11px] font-medium uppercase tracking-wide text-slate-500">{c.role}</p>
                  {c.name ? (
                    <div className="flex items-center justify-between gap-2">
                      <PersonCell name={c.name} sub={c.extra ? <>Licence till {formatDate(c.extra)}</> : c.phone} src={c.photo} />
                      {c.phone && (
                        <a href={`tel:${String(c.phone).replace(/\s/g, '')}`} className="btn-icon !h-8 !w-8" aria-label={`Call ${c.name}`}>
                          <Phone className="h-4 w-4" />
                        </a>
                      )}
                    </div>
                  ) : (
                    <p className="text-sm text-slate-400">Not assigned</p>
                  )}
                </div>
              ))}
            </div>
          </section>

          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Documents</h3>
            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
              {data.documents.map((d) => (
                <li key={d.field} className="flex items-center justify-between gap-3 px-4 py-2.5">
                  <span className="text-sm font-medium text-slate-700 dark:text-slate-200">{d.label}</span>
                  <div className="flex items-center gap-2">
                    <ExpiryCell date={d.date} alertDays={alertDays} />
                    {can('transport', 'edit') && (
                      <Button size="xs" variant="ghost" icon={RefreshCcw} onClick={() => onRenew({ vehicleId: v.id, vehicleNo: v.vehicle_no, document: d.renew, currentExpiry: d.date, expiries: Object.fromEntries(data.documents.map((x) => [x.renew, x.date])) })}>
                        Renew
                      </Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          </section>

          <section>
            <h3 className="mb-2 text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Fuel — last 90 days</h3>
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <InfoItem label="Refills">{formatNumber(data.fuel.fills)}</InfoItem>
              <InfoItem label="Quantity">{formatNumber(data.fuel.liters, 1)} L</InfoItem>
              <InfoItem label="Cost">{formatMoney(data.fuel.cost)}</InfoItem>
              <InfoItem label="Mileage">{data.fuel.mileage ? `${data.fuel.mileage} km/L` : null}</InfoItem>
            </div>
            {data.recent_fuel.length > 0 && (
              <div className="table-wrap mt-3 rounded-xl border border-slate-200 dark:border-slate-800">
                <table className="data-table">
                  <thead><tr><th>Date</th><th className="text-right">Qty</th><th className="text-right">Cost</th><th className="text-right">Odometer</th><th className="text-right">km/L</th></tr></thead>
                  <tbody>
                    {data.recent_fuel.map((f) => (
                      <tr key={f.id}>
                        <td className="whitespace-nowrap !py-2">{formatDate(f.fill_date)}</td>
                        <td className="!py-2 text-right tabular-nums">{formatNumber(f.liters, 1)}</td>
                        <td className="whitespace-nowrap !py-2 text-right tabular-nums">{formatMoney(f.cost)}</td>
                        <td className="!py-2 text-right tabular-nums">{f.odometer ? formatNumber(f.odometer) : '—'}</td>
                        <td className="!py-2 text-right tabular-nums">{f.mileage ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </section>

          <section>
            <div className="mb-2 flex items-center justify-between">
              <h3 className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">Maintenance history</h3>
              <span className="text-xs text-slate-500">This session {formatMoney(data.maintenance_cost_session)}</span>
            </div>
            {!data.maintenance.length ? (
              <EmptyState icon={Bus} title="No maintenance recorded" className="!py-6" />
            ) : (
              <ol className="space-y-2">
                {data.maintenance.map((m) => (
                  <li key={m.id} className="flex items-start justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/50">
                    <div className="min-w-0">
                      <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">{MAINTENANCE_TYPES[m.type] ?? m.type} <span className="font-normal text-slate-500">· {formatDate(m.service_date)}</span></p>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">{m.description ?? m.vendor ?? ''}</p>
                    </div>
                    <div className="shrink-0 text-right">
                      <p className="text-sm font-semibold tabular-nums text-slate-800 dark:text-slate-100">{formatMoney(m.cost)}</p>
                      {m.status !== 'completed' && <StatusBadge status={m.status} colors={{ scheduled: 'amber', in_progress: 'blue' }} label={m.status === 'in_progress' ? 'In workshop' : undefined} />}
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </section>
        </div>
      )}
    </Drawer>
  );
}
