import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle, ArrowRight, Bus, CalendarClock, Fuel, IdCard, MessageSquareWarning, Plus, RefreshCw, Route as RouteIcon, ShieldAlert, Users, Wrench,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, EmptyState, IconButton, PageHeader, Reveal, Skeleton, StatCard, Stagger, StatusBadge,
} from '@/components/ui';
import { BarChart, chartColor } from '@/components/charts';
import { CrudFormModal } from '@/components/crud';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { useTheme } from '@/lib/theme';
import { formatMoney, formatMoneyShort, formatNumber, formatTime, isoDate, timeAgo } from '@/lib/format';
import type { TransportDashboard } from './transport/types';
import { COMPLAINT_COLORS, MAINTENANCE_TYPES, OccupancyBar, PRIORITY_COLORS, daysText, useTransportRefresh } from './transport/shared';
import { RenewDocumentModal, type RenewTarget } from './transport/components/RenewDocumentModal';
import { TransportSettingsCard } from './transport/components/TransportSettingsCard';

export default function TransportDashboardPage() {
  const { can } = useAuth();
  const { resolved } = useTheme();
  const refresh = useTransportRefresh();
  const { data, isLoading, error, refetch, isFetching } = useApi<TransportDashboard>(['transport', 'dashboard'], 'transport/dashboard');
  const fuelMeta = useCrudMeta('transport_fuel');
  const [fuelOpen, setFuelOpen] = useState(false);
  const [renew, setRenew] = useState<RenewTarget | null>(null);
  const k = data?.kpis;
  const fuelDefaults = useMemo(() => ({ fill_date: isoDate() }), []);

  const fuelTrend = useMemo(() => {
    if (!k || !k.fuel_prev_to_date) return undefined;
    const pct = ((k.fuel_month - k.fuel_prev_to_date) / k.fuel_prev_to_date) * 100;
    return { value: `${Math.abs(pct).toFixed(0)}%`, dir: pct > 0.5 ? 'up' : pct < -0.5 ? 'down' : 'flat', label: 'vs last month', positive: pct <= 0 } as const;
  }, [k]);

  const routes = data?.routes ?? [];
  const activeRoutes = routes.filter((r) => r.status === 'active');
  const chartH = 300;

  return (
    <>
      <PageHeader
        title="Transport"
        description="Fleet, routes, riders, fuel and maintenance at a glance."
        breadcrumbs={[{ label: 'Transport' }]}
        actions={
          <>
            <IconButton icon={RefreshCw} label="Refresh" onClick={() => refetch()} className={clsx(isFetching && '[&_svg]:animate-spin')} />
            {can('transport', 'create') && (
              <Button variant="secondary" icon={Fuel} onClick={() => setFuelOpen(true)}>
                Log fuel
              </Button>
            )}
            {can('transport', 'create') && (
              <Button icon={Plus} to="/transport/allocations?new=1">
                Allocate seat
              </Button>
            )}
          </>
        }
      />

      {error ? (
        <Alert variant="error" title="Unable to load the transport dashboard" className="mb-5" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as Error).message}
        </Alert>
      ) : null}

      <Stagger className="mb-5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-4 xl:grid-cols-6" step={60}>
        {[
          <StatCard key="v" label="Active vehicles" value={k?.vehicles_active ?? 0} icon={Bus} tone="navy" loading={isLoading} to="/transport/vehicles"
            hint={k ? `${k.vehicles_maintenance} in workshop · ${k.vehicles_total} total` : undefined} />,
          <StatCard key="r" label="Routes" value={k?.routes_active ?? 0} icon={RouteIcon} tone="blue" loading={isLoading} to="/transport/routes"
            hint={k ? `${formatNumber(k.stops)} stops · ${formatNumber(k.seats)} seats` : undefined} />,
          <StatCard key="s" label="Student riders" value={k?.students ?? 0} icon={Users} tone="green" loading={isLoading} to="/transport/allocations"
            hint={k ? `+${k.staff_riders} faculty & staff` : undefined} />,
          <StatCard key="d" label="Drivers" value={k?.drivers ?? 0} icon={IdCard} tone="purple" loading={isLoading} to="/transport/drivers"
            hint={k ? `+${k.crew} conductors & aides` : undefined} />,
          <StatCard key="m" label="Maintenance due" value={k?.maintenance_due ?? 0} icon={Wrench} tone="amber" loading={isLoading} to="/transport/maintenance?tab=upcoming"
            hint={k ? `${k.in_workshop} in workshop now` : undefined} />,
          <StatCard key="f" label="Fuel cost this month" value={k ? formatMoneyShort(k.fuel_month) : '—'} icon={Fuel} tone="orange" loading={isLoading} to="/transport/maintenance?tab=fuel"
            trend={fuelTrend} hint={k ? `${formatNumber(k.fuel_month_liters)} L / kg` : undefined} />,
        ]}
      </Stagger>

      {/* Attention strip */}
      {k && (k.documents_expired > 0 || k.pending_requests > 0 || k.open_complaints > 0) && (
        <Reveal className="mb-5">
          <div className="flex flex-wrap gap-2">
            {k.documents_expired > 0 && (
              <a href="#transport-alerts" className="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200 transition hover:bg-red-100 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30">
                <ShieldAlert className="h-3.5 w-3.5" /> {k.documents_expired} document{k.documents_expired === 1 ? '' : 's'} expired
              </a>
            )}
            {k.documents_due > 0 && (
              <a href="#transport-alerts" className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-200 transition hover:bg-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30">
                <CalendarClock className="h-3.5 w-3.5" /> {k.documents_due} due within {data?.alert_days} days
              </a>
            )}
            {k.pending_requests > 0 && (
              <Link to="/transport/allocations?tab=pending" className="inline-flex items-center gap-1.5 rounded-full bg-brand-50 px-3 py-1.5 text-xs font-semibold text-brand-800 ring-1 ring-inset ring-brand-200 transition hover:bg-brand-100 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/30">
                <Users className="h-3.5 w-3.5" /> {k.pending_requests} students awaiting a seat
              </Link>
            )}
            {k.open_complaints > 0 && (
              <Link to="/transport/maintenance?tab=complaints" className="inline-flex items-center gap-1.5 rounded-full bg-slate-100 px-3 py-1.5 text-xs font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 transition hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700">
                <MessageSquareWarning className="h-3.5 w-3.5" /> {k.open_complaints} open complaints
              </Link>
            )}
          </div>
        </Reveal>
      )}

      {/* Charts */}
      <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-5">
        <Reveal className="xl:col-span-3">
          <Card className="h-full">
            <CardHeader title="Fuel & maintenance cost" subtitle="Last 12 months" icon={Fuel}
              actions={k ? <span className="text-xs text-slate-500 dark:text-slate-400">This month <strong className="text-slate-900 dark:text-white">{formatMoney(k.fuel_month)}</strong></span> : undefined} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[300px] w-full" />
              ) : data.months.every((m) => !m.fuel && !m.maintenance) ? (
                <EmptyState icon={Fuel} title="No fuel or maintenance records yet" description="Log refills and services to see monthly running costs." />
              ) : (
                <BarChart
                  labels={data.months.map((m) => m.label)}
                  series={[
                    { label: 'Fuel', data: data.months.map((m) => m.fuel), color: chartColor(0) },
                    { label: 'Maintenance', data: data.months.map((m) => m.maintenance), color: chartColor(1) },
                  ]}
                  stacked
                  money
                  height={chartH}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="xl:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Students per route" subtitle={k ? `${formatNumber(k.students)} students · ${Math.round(k.occupancy)}% of seats filled` : "Students, staff and free seats"} icon={Users} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-[300px] w-full" />
              ) : !activeRoutes.length ? (
                <EmptyState icon={RouteIcon} title="No routes yet" action={can('transport', 'create') ? <Button icon={Plus} to="/transport/routes">Add route</Button> : undefined} />
              ) : (
                <BarChart
                  labels={activeRoutes.map((r) => r.code)}
                  series={[
                    { label: 'Students', data: activeRoutes.map((r) => r.students), color: resolved === 'dark' ? '#60A5FA' : chartColor(7) },
                    { label: 'Staff', data: activeRoutes.map((r) => Math.max(0, r.allocated - r.students)), color: chartColor(2) },
                    { label: 'Free seats', data: activeRoutes.map((r) => Math.max(0, r.capacity - r.allocated)), color: 'rgba(148,163,184,0.45)' },
                  ]}
                  stacked
                  height={chartH}
                />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      {/* Alerts + routes overview */}
      <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-5">
        <Reveal className="xl:col-span-2">
          <Card className="h-full" id="transport-alerts">
            <CardHeader title="Document & licence expiry" subtitle={`Insurance, fitness, permit, PUC & licences · alert window ${data?.alert_days ?? 30} days`} icon={AlertTriangle}
              actions={<Link to="/transport/vehicles?f.documents=due" className="link text-xs">All vehicles</Link>} />
            <div className="divide-y divide-slate-100 dark:divide-slate-800">
              {isLoading || !data ? (
                <div className="space-y-3 p-5">{[0, 1, 2, 3, 4].map((i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
              ) : !data.alerts.length ? (
                <EmptyState icon={ShieldAlert} title="All documents are valid" description="No insurance, fitness, permit, PUC or licence expiries coming up." />
              ) : (
                data.alerts.map((a) => {
                  const expired = a.days < 0;
                  const due = !expired && a.days <= data.alert_days;
                  return (
                    <div key={`${a.kind}-${a.id}-${a.field}`} className="flex items-center gap-3 px-5 py-3">
                      <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', expired ? 'bg-red-100 text-red-600 dark:bg-red-500/15 dark:text-red-300' : due ? 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300')}>
                        {a.kind === 'vehicle' ? <Bus className="h-4 w-4" /> : <IdCard className="h-4 w-4" />}
                      </span>
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{a.label} <span className="font-normal text-slate-500 dark:text-slate-400">· {a.document}</span></p>
                        <p className={clsx('text-xs', expired ? 'text-red-600 dark:text-red-400' : due ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500')}>
                          {expired ? 'Expired' : 'Expires'} {daysText(a.days)}{a.sub ? ` · ${a.sub}` : ''}
                        </p>
                      </div>
                      {a.kind === 'vehicle' && a.renew && can('transport', 'edit') ? (
                        <Button size="xs" variant="secondary" onClick={() => setRenew({ vehicleId: a.id, vehicleNo: a.label, document: a.renew, currentExpiry: a.date })}>Renew</Button>
                      ) : a.kind === 'driver' ? (
                        <Button size="xs" variant="ghost" to={`/transport/drivers?q=${encodeURIComponent(a.label)}`}>Open</Button>
                      ) : null}
                    </div>
                  );
                })
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="xl:col-span-3" delay={80}>
          <Card className="h-full">
            <CardHeader title="Routes overview" subtitle={k ? `${k.routes_active} active routes · ${formatNumber(k.riders)} riders` : undefined} icon={RouteIcon}
              actions={<Link to="/transport/routes" className="link inline-flex items-center gap-1 text-xs">Manage routes <ArrowRight className="h-3.5 w-3.5" /></Link>} />
            {isLoading || !data ? (
              <div className="space-y-3 p-5">{[0, 1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
            ) : !routes.length ? (
              <EmptyState icon={RouteIcon} title="No routes yet" description="Create a route with its stops and assign a vehicle to start allocating seats."
                action={can('transport', 'create') ? <Button icon={Plus} to="/transport/routes">Add route</Button> : undefined} />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {routes.slice(0, 12).map((r) => (
                  <li key={r.id}>
                    <Link to={`/transport/routes/${r.id}`} className="grid grid-cols-[auto_1fr] items-center gap-x-3 gap-y-2 px-5 py-3 transition hover:bg-slate-50 sm:grid-cols-[auto_1fr_9rem_8rem] dark:hover:bg-slate-800/50">
                      <span className="inline-flex h-9 w-12 items-center justify-center rounded-lg bg-brand-50 text-xs font-bold text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">{r.code}</span>
                      <div className="min-w-0">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                          {r.name}
                          {r.status !== 'active' && <Badge color="slate" className="ml-2">Inactive</Badge>}
                          {r.open_complaints > 0 && <Badge color="red" className="ml-2">{r.open_complaints} complaint{r.open_complaints > 1 ? 's' : ''}</Badge>}
                        </p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                          {r.vehicles ?? 'No vehicle'}{r.driver_name ? ` · ${r.driver_name}` : ''} · {r.stops} stops{r.morning_departure ? ` · departs ${formatTime(r.morning_departure)}` : ''}
                        </p>
                      </div>
                      <OccupancyBar allocated={r.allocated} capacity={r.capacity} className="col-span-2 sm:col-span-1" />
                      <span className="hidden text-right text-xs text-slate-500 sm:block dark:text-slate-400">{r.distance_km ? `${formatNumber(r.distance_km, 1)} km` : '—'}</span>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
            {routes.length > 12 && (
              <div className="border-t border-slate-100 px-5 py-3 text-center dark:border-slate-800">
                <Link to="/transport/routes" className="link text-xs">View all {routes.length} routes</Link>
              </div>
            )}
          </Card>
        </Reveal>
      </div>

      {/* Services, complaints, tracking */}
      <div className="grid grid-cols-1 gap-5 lg:grid-cols-2 xl:grid-cols-3">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Upcoming services" subtitle="Workshop jobs and services falling due" icon={Wrench}
              actions={<Link to="/transport/maintenance?tab=upcoming" className="link text-xs">View all</Link>} />
            {isLoading || !data ? (
              <div className="space-y-3 p-5">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
            ) : !data.upcoming.length ? (
              <EmptyState icon={Wrench} title="Nothing due" description="No service is due in the next 30 days." />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.upcoming.map((u) => (
                  <li key={`${u.source}-${u.id}`} className="flex items-start gap-3 px-5 py-3">
                    <div className="min-w-0 flex-1">
                      <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">
                        {u.vehicle_no} <span className="font-normal text-slate-500 dark:text-slate-400">· {MAINTENANCE_TYPES[u.type] ?? u.type}</span>
                      </p>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">{u.description ?? u.vendor ?? ''}</p>
                    </div>
                    <div className="shrink-0 text-right">
                      <StatusBadge status={u.status} colors={{ due: 'amber', overdue: 'red', scheduled: 'blue', in_progress: 'purple' }} label={u.status === 'in_progress' ? 'In workshop' : undefined} />
                      <p className="mt-0.5 text-[11px] text-slate-500 dark:text-slate-400">{u.status === 'in_progress' ? `since ${daysText(u.days)}` : u.due_date ? daysText(u.days) : u.due_km ? `at ${formatNumber(u.due_km)} km` : ''}</p>
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Open complaints" subtitle={k ? `${k.open_complaints} awaiting resolution` : undefined} icon={MessageSquareWarning}
              actions={<Link to="/transport/maintenance?tab=complaints" className="link text-xs">View all</Link>} />
            {isLoading || !data ? (
              <div className="space-y-3 p-5">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10 w-full" />)}</div>
            ) : !data.complaints.length ? (
              <EmptyState icon={MessageSquareWarning} title="No open complaints" description="All transport complaints have been resolved." />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {data.complaints.map((c) => (
                  <li key={c.id}>
                    <Link to={`/transport/maintenance?tab=complaints&complaint=${c.id}`} className="flex items-start gap-3 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                      <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{c.subject}</p>
                        <p className="truncate text-xs text-slate-500 dark:text-slate-400">{c.complaint_no}{c.route_code ? ` · ${c.route_code}` : ''} · {c.complainant_name} · {timeAgo(c.created_at)}</p>
                      </div>
                      <div className="flex shrink-0 flex-col items-end gap-1">
                        <StatusBadge status={c.priority} colors={PRIORITY_COLORS} />
                        <StatusBadge status={c.status} colors={COMPLAINT_COLORS} dot={false} />
                      </div>
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </Reveal>
        <Reveal delay={160} className="lg:col-span-2 xl:col-span-1">
          <TransportSettingsCard className="h-full" />
        </Reveal>
      </div>

      <CrudFormModal open={fuelOpen} onClose={() => setFuelOpen(false)} meta={fuelMeta.data} module="transport_fuel" title="Log fuel refill"
        defaults={fuelDefaults} onSaved={() => refresh('transport_fuel', 'vehicles')} />
      <RenewDocumentModal target={renew} onClose={() => setRenew(null)} />
    </>
  );
}
