import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { Armchair, Bus, Eye, FileWarning, Fuel, Printer, RefreshCcw, Route as RouteIcon, Satellite, Wrench } from 'lucide-react';
import { PageHeader, PersonCell, StatTile, Stagger, StatusBadge, Tabs } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { useCrudList, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatNumber, isoDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { DocumentChips, ExpiryCell, OccupancyBar, SummaryStrip, VEHICLE_TYPES, openPrint, useTransportRefresh } from './transport/shared';
import { FuelLogPanel } from './transport/components/FuelLogPanel';
import { VehicleDrawer } from './transport/components/VehicleDrawer';
import { RenewDocumentModal, type RenewTarget } from './transport/components/RenewDocumentModal';

interface VehicleSummary {
  total: number;
  active: number;
  maintenance: number;
  seats: number;
  expired: number;
  due: number;
  alert_days: number;
}

export default function VehiclesPage() {
  const { can } = useAuth();
  const refresh = useTransportRefresh();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'fuel' ? 'fuel' : 'vehicles';
  const summaryQ = useCrudList('vehicles', { per_page: 1 });
  const s = summaryQ.data?.summary as VehicleSummary | undefined;
  const alertDays = s?.alert_days ?? 30;

  const vehicleMeta = useCrudMeta('vehicles');
  const fuelMeta = useCrudMeta('transport_fuel');
  const maintMeta = useCrudMeta('transport_maintenance');
  const [viewId, setViewId] = useState<number | null>(null);
  const [editId, setEditId] = useState<number | null>(null);
  const [renew, setRenew] = useState<RenewTarget | null>(null);
  const [fuelFor, setFuelFor] = useState<number | null>(null);
  const [maintFor, setMaintFor] = useState<number | null>(null);
  const fuelDefaults = useMemo(() => ({ fill_date: isoDate(), vehicle_id: fuelFor ?? '' }), [fuelFor]);
  const maintDefaults = useMemo(() => ({ service_date: isoDate(), vehicle_id: maintFor ?? '', status: 'completed', type: 'service' }), [maintFor]);

  const setTab = (t: string) => {
    const next = new URLSearchParams();
    if (t !== 'vehicles') next.set('tab', t);
    setParams(next, { replace: true });
  };

  const rowMenu = (r: Row) => [
    { label: 'Vehicle details', icon: Eye, onClick: () => setViewId(Number(r.id)) },
    can('transport', 'edit') && { label: 'Renew a document…', icon: RefreshCcw, onClick: () => setRenew(renewTarget(r)) },
    can('transport', 'create') && { label: 'Log fuel refill', icon: Fuel, onClick: () => setFuelFor(Number(r.id)) },
    can('transport', 'create') && { label: 'Add maintenance record', icon: Wrench, onClick: () => setMaintFor(Number(r.id)) },
    r.route_id && { label: `Open route ${r.route_code}`, icon: RouteIcon, to: `/transport/routes/${r.route_id}` },
    r.route_id && { label: 'Print route sheet', icon: Printer, onClick: () => openPrint(`transport/routes/${r.route_id}/print`) },
  ];

  /** Renew dialog defaults to the document that expires first. */
  const renewTarget = (r: Row): RenewTarget => {
    const expiries: Record<string, string | null> = { insurance: r.insurance_expiry ?? null, fitness: r.fitness_expiry ?? null, permit: r.permit_expiry ?? null, puc: r.pollution_expiry ?? null };
    const first = Object.entries(expiries).filter(([, d]) => d).sort((a, b) => String(a[1]).localeCompare(String(b[1])))[0]?.[0] ?? 'insurance';
    return { vehicleId: Number(r.id), vehicleNo: String(r.vehicle_no), document: first, currentExpiry: expiries[first], expiries };
  };

  const expiry = (key: string) => (r: Row) => <ExpiryCell date={r[key]} alertDays={alertDays} compact />;

  return (
    <>
      <PageHeader
        title="Vehicles"
        description="Fleet register with crew, routes, seat usage and statutory document expiries — plus the fuel log."
        breadcrumbs={[{ label: 'Transport', to: '/transport' }, { label: 'Vehicles' }]}
      >
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'vehicles', label: 'Vehicles', icon: Bus, count: s?.total },
            { key: 'fuel', label: 'Fuel log', icon: Fuel },
          ]}
        />
      </PageHeader>

      {tab === 'vehicles' ? (
        <>
          <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" step={60}>
            {[
              <StatTile key="t" label="Fleet" value={s?.total ?? 0} icon={Bus} tone="navy" loading={summaryQ.isLoading} sub={s ? `${s.active} on road` : undefined} />,
              <StatTile key="m" label="In maintenance" value={s?.maintenance ?? 0} icon={Wrench} tone="amber" loading={summaryQ.isLoading} sub="Workshop jobs open" />,
              <StatTile key="s" label="Seats in service" value={s?.seats ?? 0} icon={Armchair} tone="green" loading={summaryQ.isLoading} sub="On road, in workshop & standby" />,
              <StatTile key="d" label="Documents" value={s ? `${s.expired} expired` : '—'} icon={FileWarning} tone={s?.expired ? 'red' : 'cyan'} loading={summaryQ.isLoading}
                sub={s ? `${s.due} due within ${s.alert_days} days` : undefined} />,
            ]}
          </Stagger>

          <CrudTable
            module="vehicles"
            urlState
            title="Fleet register"
            addLabel="Add vehicle"
            onView={(r) => setViewId(Number(r.id))}
            onEdit={(r) => setEditId(Number(r.id))}
            rowMenu={rowMenu}
            onSaved={() => refresh('vehicles', 'transport_routes')}
            emptyText="Add the college buses, vans and cars to assign routes and crew."
            renderers={{
              vehicle_no: (r) => (
                <button type="button" onClick={() => setViewId(Number(r.id))} className="min-w-0 text-left leading-tight">
                  <span className="flex items-center gap-1.5 font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                    {r.vehicle_no}
                    {r.gps_device_id && <Satellite className="h-3.5 w-3.5 text-cyan-600" aria-label="GPS device mapped" />}
                  </span>
                  <span className="block max-w-[9.5rem] truncate text-xs text-slate-500 dark:text-slate-400" title={`${VEHICLE_TYPES[String(r.type)] ?? r.type} · ${r.make_model ?? ''}`}>{VEHICLE_TYPES[String(r.type)] ?? r.type} · {r.make_model}</span>
                </button>
              ),
              allocated: (r) => (r.route_id ? <OccupancyBar allocated={Number(r.allocated)} capacity={Number(r.capacity)} className="!min-w-[6rem]" /> : <span className="text-xs text-slate-400">Standby · {formatNumber(Number(r.capacity))} seats</span>),
              status: (r) => <StatusBadge status={r.status} label={r.status === 'maintenance' ? 'Workshop' : undefined} />,
              route_code: (r) =>
                r.route_code ? (
                  <div className="leading-tight">
                    <span className="font-semibold text-slate-900 dark:text-white">{r.route_code}</span>
                    <div className="max-w-[6.5rem] truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.route_name ?? '')}>{r.route_name}</div>
                  </div>
                ) : (
                  <span className="text-xs text-slate-400">Standby</span>
                ),
              insurance_expiry: expiry('insurance_expiry'),
              fitness_expiry: expiry('fitness_expiry'),
              permit_expiry: expiry('permit_expiry'),
              pollution_expiry: expiry('pollution_expiry'),
              next_expiry: (r) => <DocumentChips row={r} alertDays={alertDays} />,
              driver_name: (r) =>
                r.driver_name ? (
                  <div className="whitespace-nowrap"><PersonCell name={String(r.driver_name)} sub={r.driver_phone} src={r.driver_photo} size="sm" /></div>
                ) : (
                  <span className="text-xs font-medium text-amber-600 dark:text-amber-400">No driver</span>
                ),
            }}
            header={({ summary }) => {
              const sm = summary as VehicleSummary | null | undefined;
              return sm ? (
                <SummaryStrip
                  items={[
                    { label: 'Vehicles', value: formatNumber(sm.total) },
                    { label: 'Seats', value: formatNumber(sm.seats) },
                    { label: 'Documents expired', value: formatNumber(sm.expired), tone: sm.expired ? 'red' : 'green' },
                    { label: `Due in ${sm.alert_days} days`, value: formatNumber(sm.due), tone: sm.due ? 'amber' : 'green' },
                  ]}
                />
              ) : null;
            }}
          />
        </>
      ) : (
        <FuelLogPanel />
      )}

      <VehicleDrawer
        vehicleId={viewId}
        alertDays={alertDays}
        onClose={() => setViewId(null)}
        onEdit={(id) => setEditId(id)}
        onRenew={(t) => setRenew(t)}
        onLogFuel={(id) => setFuelFor(id)}
        onAddMaintenance={(id) => setMaintFor(id)}
      />
      <CrudFormModal open={editId !== null} onClose={() => setEditId(null)} meta={vehicleMeta.data} module="vehicles" id={editId} onSaved={() => refresh('vehicles', 'transport_routes')} />
      <CrudFormModal open={fuelFor !== null} onClose={() => setFuelFor(null)} meta={fuelMeta.data} module="transport_fuel" title="Log fuel refill" defaults={fuelDefaults}
        onSaved={() => refresh('transport_fuel', 'vehicles')} />
      <CrudFormModal open={maintFor !== null} onClose={() => setMaintFor(null)} meta={maintMeta.data} module="transport_maintenance" title="Add maintenance record" defaults={maintDefaults}
        onSaved={() => refresh('transport_maintenance', 'vehicles')} />
      <RenewDocumentModal target={renew} onClose={() => setRenew(null)} />
    </>
  );
}
