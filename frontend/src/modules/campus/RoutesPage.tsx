import { Link } from 'react-router-dom';
import { Armchair, Eye, MapPin, Printer, Route as RouteIcon, UserPlus, Users } from 'lucide-react';
import { Badge, PageHeader, StatTile, Stagger } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useCrudList } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatMoney, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { OccupancyBar, SummaryStrip, openPrint, useTransportRefresh } from './transport/shared';

interface RouteSummary {
  total: number;
  active: number;
  seats: number;
  riders: number;
  full: number;
  no_vehicle: number;
  stops: number;
}

export default function RoutesPage() {
  const { can } = useAuth();
  const refresh = useTransportRefresh();
  const summaryQ = useCrudList('transport_routes', { per_page: 1 });
  const s = summaryQ.data?.summary as RouteSummary | undefined;
  const occupancy = s && s.seats ? Math.round((s.riders * 100) / s.seats) : 0;

  const rowMenu = (r: Row) => [
    { label: 'Open route & stops', icon: Eye, to: `/transport/routes/${r.id}` },
    { label: 'Print route sheet', icon: Printer, onClick: () => openPrint(`transport/routes/${r.id}/print`) },
    can('transport', 'create') && r.status === 'active' && { label: 'Allocate a seat on this route', icon: UserPlus, to: `/transport/allocations?new=1&route=${r.id}` },
    { label: 'Riders on this route', icon: Users, to: `/transport/allocations?f.route_id=${r.id}&f.status=active` },
  ];

  return (
    <>
      <PageHeader
        title="Routes & Stops"
        description="Bus routes with timed pickup points, fares, vehicles and seat occupancy. Open a route to manage and reorder its stops."
        breadcrumbs={[{ label: 'Transport', to: '/transport' }, { label: 'Routes' }]}
      />

      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" step={60}>
        {[
          <StatTile key="r" label="Active routes" value={s?.active ?? 0} icon={RouteIcon} tone="navy" loading={summaryQ.isLoading} sub={s ? `${s.total} in total` : undefined} />,
          <StatTile key="s" label="Pickup stops" value={s?.stops ?? 0} icon={MapPin} tone="blue" loading={summaryQ.isLoading} sub="Across all routes" />,
          <StatTile key="o" label="Seats filled" value={s ? `${occupancy}%` : '—'} icon={Armchair} tone="green" loading={summaryQ.isLoading}
            sub={s ? `${formatNumber(s.riders)} of ${formatNumber(s.seats)} seats` : undefined} />,
          <StatTile key="f" label="Full routes" value={s?.full ?? 0} icon={Users} tone={s?.full ? 'amber' : 'cyan'} loading={summaryQ.isLoading}
            sub={s?.no_vehicle ? `${s.no_vehicle} without a vehicle` : 'All routes have vehicles'} />,
        ]}
      </Stagger>

      <CrudTable
        module="transport_routes"
        urlState
        title="All routes"
        addLabel="Add route"
        viewTo={(r) => `/transport/routes/${r.id}`}
        rowMenu={rowMenu}
        onSaved={() => refresh('transport_routes')}
        emptyText="Create the first route, then add its stops and assign a vehicle."
        renderers={{
          code: (r) => (
            <Link to={`/transport/routes/${r.id}`} className="group block leading-tight">
              <span className="flex items-center gap-1.5">
                <span className="font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">{r.code}</span>
                {r.status !== 'active' && <Badge color="slate">Inactive</Badge>}
              </span>
              <span className="block max-w-[12rem] truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.name)}>{r.name}</span>
            </Link>
          ),
          start_point: (r) => <span className="block max-w-[12rem] truncate whitespace-nowrap" title={String(r.path ?? '')}>{r.start_point ?? '—'}</span>,
          allocated: (r) => <OccupancyBar allocated={Number(r.allocated)} capacity={Number(r.capacity)} className="!min-w-[6rem]" />,
          vehicles: (r) =>
            r.vehicles ? (
              <div className="leading-tight">
                <span className="block max-w-[9.5rem] truncate whitespace-nowrap font-medium text-slate-800 dark:text-slate-100" title={String(r.vehicles)}>{r.vehicles}</span>
                {r.driver_name && <div className="text-xs text-slate-500 dark:text-slate-400">{r.driver_name}</div>}
              </div>
            ) : (
              <span className="text-xs font-medium text-amber-600 dark:text-amber-400">No vehicle assigned</span>
            ),
          annual_fare: (r) => (
            <div className="whitespace-nowrap leading-tight">
              <span className="font-medium tabular-nums">{formatMoney(r.max_fare ?? r.annual_fare)}</span>
              <div className="text-xs text-slate-500 dark:text-slate-400">{r.min_fare && r.min_fare !== r.max_fare ? `from ${formatMoney(r.min_fare)}` : 'per year'}</div>
            </div>
          ),
        }}
        header={({ summary }) => {
          const sm = summary as RouteSummary | null | undefined;
          return sm ? (
            <SummaryStrip
              items={[
                { label: 'Routes', value: formatNumber(sm.total) },
                { label: 'Riders', value: formatNumber(sm.riders), tone: 'blue' },
                { label: 'Seats', value: formatNumber(sm.seats) },
                { label: 'Full', value: formatNumber(sm.full), tone: sm.full ? 'amber' : 'green' },
              ]}
            />
          ) : null;
        }}
      />
    </>
  );
}
