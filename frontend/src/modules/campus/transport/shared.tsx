import type { ReactNode } from 'react';
import clsx from 'clsx';
import { useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, CheckCircle2, Clock } from 'lucide-react';
import { useApi } from '@/lib/queries';
import { apiUrl, type Query } from '@/lib/api';
import { formatDate, formatNumber } from '@/lib/format';
import type { Option } from '@/lib/types';
import type { Tone } from '@/components/ui';
import type { ExpiryState, RouteOption, TransportSettings } from './types';

export const VEHICLE_TYPES: Record<string, string> = { bus: 'Bus', mini_bus: 'Mini Bus', van: 'Van / Tempo Traveller', car: 'Car', other: 'Other' };
export const MAINTENANCE_TYPES: Record<string, string> = {
  service: 'Periodic service', repair: 'Repair', tyre: 'Tyres', battery: 'Battery', insurance: 'Insurance renewal', fitness: 'Fitness certificate',
  permit: 'Permit renewal', puc: 'PUC / Pollution', other: 'Other',
};
export const COMPLAINT_STATUSES: Record<string, string> = { open: 'Open', in_progress: 'In progress', resolved: 'Resolved', closed: 'Closed' };
export const COMPLAINT_CATEGORIES: Record<string, string> = {
  delay: 'Late / missed pickup', driver_behaviour: 'Driver / staff behaviour', rash_driving: 'Rash driving / safety', overcrowding: 'Overcrowding',
  cleanliness: 'Cleanliness / AC', breakdown: 'Breakdown', route_change: 'Route / stop request', fee: 'Transport fee', other: 'Other',
};
export const PRIORITY_COLORS = { low: 'slate', medium: 'blue', high: 'amber', urgent: 'red' } as const;
export const COMPLAINT_COLORS = { open: 'red', in_progress: 'amber', resolved: 'green', closed: 'slate' } as const;
export const RENEWABLE_DOCS: Option[] = [
  { value: 'insurance', label: 'Insurance' },
  { value: 'fitness', label: 'Fitness certificate' },
  { value: 'permit', label: 'Permit' },
  { value: 'puc', label: 'PUC certificate' },
];

/** Every transport-specific query key starts with 'transport'. */
export function useTransportRefresh() {
  const qc = useQueryClient();
  return (...modules: string[]) =>
    Promise.all([
      qc.invalidateQueries({ queryKey: ['transport'] }),
      ...modules.map((m) => qc.invalidateQueries({ queryKey: ['crud', m] })),
      ...modules.map((m) => qc.invalidateQueries({ queryKey: ['crud-record', m] })),
    ]);
}

export function useRouteOptions(enabled = true) {
  return useApi<RouteOption[]>(['transport', 'route-options'], 'transport/route-options', undefined, { enabled, staleTime: 15_000 });
}

export function useTransportSettings() {
  return useApi<TransportSettings>(['transport', 'settings'], 'transport/settings', undefined, { staleTime: 60_000 });
}

/** Open a server-rendered print view of the transport API in a new tab. */
export function openPrint(path: string, query?: Query) {
  window.open(apiUrl(path, query), '_blank', 'noopener');
}

export function expiryState(date: string | null | undefined, alertDays = 30): ExpiryState {
  if (!date) return null;
  const d = daysUntil(date);
  if (d === null) return null;
  if (d < 0) return 'expired';
  return d <= alertDays ? 'due' : 'ok';
}

export function daysUntil(date: string | null | undefined): number | null {
  if (!date) return null;
  const t = new Date(`${String(date).slice(0, 10)}T00:00:00`).getTime();
  if (Number.isNaN(t)) return null;
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime();
  return Math.round((t - today) / 86400000);
}

export function daysText(days: number | null | undefined): string {
  if (days === null || days === undefined) return '';
  if (days === 0) return 'today';
  if (days < 0) return `${formatNumber(Math.abs(days))} day${days === -1 ? '' : 's'} ago`;
  return `in ${formatNumber(days)} day${days === 1 ? '' : 's'}`;
}

export const expiryTone: Record<'expired' | 'due' | 'ok', Tone> = { expired: 'red', due: 'amber', ok: 'green' };

const chipCls: Record<'expired' | 'due' | 'ok', string> = {
  expired: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
  due: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
  ok: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
};

/** Date with a coloured "expired / due in n days" hint (document & licence expiries). */
export function ExpiryCell({ date, alertDays = 30, compact }: { date: string | null | undefined; alertDays?: number; compact?: boolean }) {
  if (!date) return <span className="text-xs text-slate-400">Not recorded</span>;
  const state = expiryState(date, alertDays) ?? 'ok';
  const days = daysUntil(date);
  const Icon = state === 'expired' ? AlertTriangle : state === 'due' ? Clock : CheckCircle2;
  return (
    <div className="whitespace-nowrap leading-tight">
      <span className={clsx('inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium ring-1 ring-inset', chipCls[state])}>
        <Icon className="h-3 w-3" aria-hidden />
        {formatDate(date)}
      </span>
      {!compact && state !== 'ok' && <div className={clsx('mt-0.5 text-[11px]', state === 'expired' ? 'text-red-600 dark:text-red-400' : 'text-amber-600 dark:text-amber-400')}>{state === 'expired' ? `Expired ${daysText(days)}` : `Due ${daysText(days)}`}</div>}
    </div>
  );
}

const DOCS: [string, string, string][] = [
  ['insurance_expiry', 'INS', 'Insurance'],
  ['fitness_expiry', 'FIT', 'Fitness'],
  ['permit_expiry', 'PMT', 'Permit'],
  ['pollution_expiry', 'PUC', 'PUC'],
];
const dotCls: Record<'expired' | 'due' | 'ok' | 'none', string> = {
  expired: 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
  due: 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
  ok: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
  none: 'bg-slate-50 text-slate-400 ring-slate-200 dark:bg-slate-800 dark:text-slate-500 dark:ring-slate-700',
};

/** Four colour-coded chips (insurance, fitness, permit, PUC) + the next expiry date. */
export function DocumentChips({ row, alertDays = 30 }: { row: Record<string, unknown>; alertDays?: number }) {
  const next = row.next_expiry as string | null | undefined;
  const nextDays = daysUntil(next);
  return (
    <div className="leading-tight">
      <div className="flex gap-1">
        {DOCS.map(([key, short, label]) => {
          const d = row[key] as string | null | undefined;
          const st = expiryState(d, alertDays) ?? 'none';
          return (
            <span key={key} title={`${label}: ${d ? `${formatDate(d)} (${daysUntil(d)! < 0 ? 'expired ' : ''}${daysText(daysUntil(d))})` : 'not recorded'}`}
              className={clsx('rounded px-1 py-0.5 text-[10px] font-bold ring-1 ring-inset', dotCls[st])}>
              {short}
            </span>
          );
        })}
      </div>
      {next && (
        <div className={clsx('mt-1 whitespace-nowrap text-[11px]', nextDays !== null && nextDays < 0 ? 'text-red-600 dark:text-red-400' : nextDays !== null && nextDays <= alertDays ? 'text-amber-600 dark:text-amber-400' : 'text-slate-500 dark:text-slate-400')}>
          Next {formatDate(next)}
        </div>
      )}
    </div>
  );
}

/** Small "allocated / capacity" bar. */
export function OccupancyBar({ allocated, capacity, className, showLabel = true }: { allocated: number; capacity: number; className?: string; showLabel?: boolean }) {
  const pct = capacity ? Math.min(100, (allocated * 100) / capacity) : 0;
  const tone = !capacity ? 'bg-slate-300' : pct >= 100 ? 'bg-red-500' : pct >= 85 ? 'bg-amber-500' : 'bg-emerald-500';
  return (
    <div className={clsx('min-w-[7rem]', className)}>
      {showLabel && (
        <div className="mb-1 flex items-baseline justify-between gap-2 text-xs">
          <span className="font-semibold tabular-nums text-slate-800 dark:text-slate-100">
            {formatNumber(allocated)}
            <span className="font-normal text-slate-400">/{formatNumber(capacity)}</span>
          </span>
          <span className="tabular-nums text-slate-500 dark:text-slate-400">{capacity ? `${Math.round(pct)}%` : 'No seats'}</span>
        </div>
      )}
      <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label="Seat occupancy">
        <div className={clsx('h-full rounded-full transition-all duration-500', tone)} style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

/** Label/value pair used in compact info grids. */
export function InfoItem({ label, children, className }: { label: string; children: ReactNode; className?: string }) {
  return (
    <div className={clsx('min-w-0', className)}>
      <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{label}</p>
      <div className="mt-0.5 truncate text-sm font-medium text-slate-800 dark:text-slate-100">{children ?? <span className="text-slate-400">—</span>}</div>
    </div>
  );
}

/** Rounded summary chips rendered under CrudTable toolbars. */
export function SummaryStrip({ items }: { items: { label: string; value: ReactNode; tone?: 'red' | 'amber' | 'green' | 'blue' | 'slate' }[] }) {
  const tones = {
    red: 'text-red-700 dark:text-red-300',
    amber: 'text-amber-700 dark:text-amber-300',
    green: 'text-emerald-700 dark:text-emerald-300',
    blue: 'text-brand-700 dark:text-brand-200',
    slate: 'text-slate-900 dark:text-white',
  };
  return (
    <div className="flex flex-wrap gap-x-6 gap-y-2 border-b border-slate-100 bg-slate-50/60 px-4 py-2.5 text-xs dark:border-slate-800 dark:bg-slate-800/30">
      {items.map((it) => (
        <span key={it.label} className="inline-flex items-baseline gap-1.5 text-slate-500 dark:text-slate-400">
          {it.label}
          <strong className={clsx('text-sm font-semibold tabular-nums', tones[it.tone ?? 'slate'])}>{it.value}</strong>
        </span>
      ))}
    </div>
  );
}
