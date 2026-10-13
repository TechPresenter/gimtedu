import { useMemo, useState } from 'react';
import clsx from 'clsx';
import { Fuel, Gauge, IndianRupee, Route as RouteIcon } from 'lucide-react';
import { Alert, Card, CardHeader, EmptyState, Select, Skeleton, StatTile, Stagger } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi } from '@/lib/queries';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, isoDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { SummaryStrip, VEHICLE_TYPES, useTransportRefresh } from '../shared';
import type { FuelStats } from '../types';

const PERIODS = [
  { value: 'month', label: 'This month' },
  { value: '3m', label: 'Last 3 months' },
  { value: '6m', label: 'Last 6 months' },
  { value: '12m', label: 'Last 12 months' },
];

function periodRange(p: string): { from: string; to: string } {
  const now = new Date();
  const to = isoDate(now);
  if (p === 'month') return { from: isoDate(new Date(now.getFullYear(), now.getMonth(), 1)), to };
  const months = p === '3m' ? 3 : p === '6m' ? 6 : 12;
  return { from: isoDate(new Date(now.getFullYear(), now.getMonth() - months, now.getDate() + 1)), to };
}

/** Fuel log: period analytics (cost, litres, mileage per vehicle) + the full fuel register. */
export function FuelLogPanel({ vehicleId }: { vehicleId?: number }) {
  const refresh = useTransportRefresh();
  const [period, setPeriod] = useState('3m');
  const range = useMemo(() => periodRange(period), [period]);
  const { data, isLoading, error } = useApi<FuelStats>(['transport', 'fuel-stats', range, vehicleId], 'transport/fuel/stats', { ...range, vehicle_id: vehicleId });
  const t = data?.totals;
  const maxMileage = Math.max(1, ...(data?.vehicles ?? []).map((v) => v.mileage ?? 0));
  const formDefaults = useMemo(() => ({ fill_date: isoDate(), ...(vehicleId ? { vehicle_id: vehicleId } : {}) }), [vehicleId]);

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <p className="text-sm text-slate-500 dark:text-slate-400">Mileage is worked out from the odometer difference between consecutive refills.</p>
        <Select inputSize="sm" value={period} onChange={(e) => setPeriod(e.target.value)} options={PERIODS} aria-label="Analysis period" className="!w-44" />
      </div>
      {error && <Alert variant="error">Unable to load fuel statistics.</Alert>}
      <Stagger className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="c" label="Fuel spend" value={t ? formatMoneyShort(t.cost) : '—'} icon={IndianRupee} tone="orange" loading={isLoading} sub={t ? `${formatNumber(t.fills)} refills` : undefined} />,
          <StatTile key="l" label="Quantity" value={t ? `${formatNumber(t.liters)} L` : '—'} icon={Fuel} tone="blue" loading={isLoading} sub={t?.avg_rate ? `avg ${formatMoney(t.avg_rate, 2)} / unit` : undefined} />,
          <StatTile key="k" label="Distance run" value={t ? `${formatNumber(t.km)} km` : '—'} icon={RouteIcon} tone="green" loading={isLoading} sub="from odometer readings" />,
          <StatTile key="p" label="Running cost" value={t?.cost_per_km ? `${formatMoney(t.cost_per_km, 2)}/km` : '—'} icon={Gauge} tone="purple" loading={isLoading} sub="fuel only" />,
        ]}
      </Stagger>

      {!vehicleId && (
        <Card>
          <CardHeader title="Efficiency by vehicle" subtitle={data ? `${PERIODS.find((p) => p.value === period)?.label} · km per litre (kg for CNG)` : undefined} icon={Gauge} />
          {isLoading ? (
            <div className="space-y-2 p-5">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-8 w-full" />)}</div>
          ) : !data?.vehicles.length ? (
            <EmptyState icon={Fuel} title="No refills in this period" description="Pick a longer period or log a refill." />
          ) : (
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Vehicle</th>
                    <th className="text-right">Refills</th>
                    <th className="text-right">Quantity</th>
                    <th className="text-right">Cost</th>
                    <th className="text-right">Km</th>
                    <th className="min-w-[10rem]">Mileage</th>
                    <th className="text-right">₹ / km</th>
                  </tr>
                </thead>
                <tbody>
                  {data.vehicles.map((v) => (
                    <tr key={v.id}>
                      <td>
                        <div className="font-semibold text-slate-900 dark:text-white">{v.vehicle_no}</div>
                        <div className="text-xs text-slate-500 dark:text-slate-400">{VEHICLE_TYPES[v.type] ?? v.type} · {(v.fuel_type ?? '').toUpperCase()}</div>
                      </td>
                      <td className="text-right tabular-nums">{formatNumber(v.fills)}</td>
                      <td className="text-right tabular-nums">{formatNumber(v.liters, 1)}</td>
                      <td className="whitespace-nowrap text-right font-medium tabular-nums">{formatMoney(v.cost)}</td>
                      <td className="text-right tabular-nums">{formatNumber(v.km)}</td>
                      <td>
                        {v.mileage ? (
                          <div className="flex items-center gap-2">
                            <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                              <div className={clsx('h-full rounded-full', v.fuel_type === 'cng' ? 'bg-emerald-500' : 'bg-brand-600')} style={{ width: `${(v.mileage / maxMileage) * 100}%` }} />
                            </div>
                            <span className="w-16 text-right text-xs font-semibold tabular-nums">{v.mileage.toFixed(2)}</span>
                          </div>
                        ) : (
                          <span className="text-xs text-slate-400">Need 2+ readings</span>
                        )}
                      </td>
                      <td className="text-right tabular-nums">{v.cost_per_km ? formatMoney(v.cost_per_km, 2) : '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Card>
      )}

      <CrudTable
        module="transport_fuel"
        title="Fuel register"
        scope={vehicleId ? { vehicle_id: vehicleId } : undefined}
        urlState={!vehicleId}
        addLabel="Log refill"
        formDefaults={formDefaults}
        emptyTitle="No refills logged yet"
        emptyText="Record every refill with the odometer reading to track mileage."
        onSaved={() => refresh('transport_fuel', 'vehicles')}
        renderers={{
          mileage: (r: Row) => (r.mileage ? <span className={clsx('font-semibold tabular-nums', Number(r.mileage) < 3 ? 'text-red-600' : 'text-slate-800 dark:text-slate-100')}>{Number(r.mileage).toFixed(2)}</span> : <span className="text-slate-400">—</span>),
          rate: (r: Row) => <span className="whitespace-nowrap tabular-nums">{formatMoney(r.rate, 2)}</span>,
          fill_date: (r: Row) => <span className="whitespace-nowrap">{formatDate(r.fill_date)}</span>,
          vehicle_no: (r: Row) => (
            <div className="whitespace-nowrap leading-tight">
              <span className="font-semibold text-slate-900 dark:text-white">{r.vehicle_no}</span>
              <div className="text-xs text-slate-500 dark:text-slate-400">{[r.route_code, String(r.fuel_type ?? '').toUpperCase()].filter(Boolean).join(' · ')}</div>
            </div>
          ),
          cost: (r: Row) => (
            <div className="whitespace-nowrap text-right leading-tight">
              <span className="font-medium tabular-nums">{formatMoney(r.cost)}</span>
              {r.rate ? <div className="text-xs text-slate-500 dark:text-slate-400">@ {formatMoney(r.rate, 2)}</div> : null}
            </div>
          ),
          odometer: (r: Row) => (r.odometer ? <span className="whitespace-nowrap tabular-nums">{formatNumber(Number(r.odometer))} km</span> : <span className="text-slate-400">—</span>),
          km_run: (r: Row) => (r.km_run !== null && r.km_run !== undefined ? <span className="tabular-nums">{formatNumber(Number(r.km_run))}</span> : <span className="text-slate-400">—</span>),
          station: (r: Row) => <span className="block max-w-[9rem] truncate" title={String(r.station ?? '')}>{r.station ?? '—'}</span>,
        }}
        header={({ summary }) =>
          summary ? (
            <SummaryStrip
              items={[
                { label: 'Refills', value: formatNumber(summary.fills) },
                { label: 'Quantity', value: `${formatNumber(summary.liters)} L` },
                { label: 'Cost', value: formatMoney(summary.cost), tone: 'blue' },
                { label: 'Avg rate', value: summary.avg_rate ? formatMoney(summary.avg_rate, 2) : '—' },
              ]}
            />
          ) : null
        }
      />
    </div>
  );
}
