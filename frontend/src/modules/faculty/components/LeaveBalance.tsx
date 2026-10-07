import clsx from 'clsx';
import { Infinity as InfinityIcon } from 'lucide-react';
import { CountUp, Skeleton, Stagger, toneClasses } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { formatNumber } from '@/lib/format';
import { leaveTone, type BalanceRow, type EmployeeType, type SessionWindow } from '../hr';

export function useLeaveBalance(type: EmployeeType, id: number) {
  return useApi<{ session: SessionWindow; balance: BalanceRow[] }>(['leave-balance', type, id], `${type}/${id}/balance`);
}

const fmt = (n: number) => formatNumber(n, n % 1 ? 1 : 0);

/** Compact list of balances (overview sidebar). */
export function LeaveBalanceList({ type, id, limit = 4 }: { type: EmployeeType; id: number; limit?: number }) {
  const { data, isLoading } = useLeaveBalance(type, id);
  if (isLoading) return <div className="space-y-4">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-8" />)}</div>;
  const rows = (data?.balance ?? []).filter((b) => !b.unlimited && (b.quota < 100 || b.used > 0)).slice(0, limit);
  if (!rows.length) return <p className="text-sm text-slate-500">No leave types with a quota.</p>;
  return (
    <ul className="space-y-3.5">
      {rows.map((b) => {
        const tone = toneClasses[leaveTone(b.color)];
        const pct = b.quota ? Math.min(100, ((b.used + b.pending) / b.quota) * 100) : 0;
        return (
          <li key={b.code}>
            <div className="flex items-baseline justify-between gap-2 text-sm">
              <span className="truncate font-medium text-slate-700 dark:text-slate-200">{b.name}</span>
              <span className="shrink-0 text-xs text-slate-500 dark:text-slate-400">
                <span className="font-semibold text-slate-900 dark:text-white">{fmt(b.available ?? 0)}</span> / {fmt(b.quota)} left
              </span>
            </div>
            <div className="mt-1.5 flex h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-label={`${b.name} used`} aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100}>
              <div className={clsx('h-full transition-[width] duration-700 motion-reduce:transition-none', tone.bar)} style={{ width: `${b.quota ? (b.used / b.quota) * 100 : 0}%` }} />
              <div className={clsx('h-full opacity-40', tone.bar)} style={{ width: `${b.quota ? (b.pending / b.quota) * 100 : 0}%` }} />
            </div>
          </li>
        );
      })}
    </ul>
  );
}

/** Balance cards per leave type (Leaves tab). */
export function LeaveBalanceCards({ type, id }: { type: EmployeeType; id: number }) {
  const { data, isLoading } = useLeaveBalance(type, id);
  if (isLoading) {
    return (
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
        {[1, 2, 3, 4, 5].map((i) => <Skeleton key={i} className="h-28 !rounded-2xl" />)}
      </div>
    );
  }
  const rows = (data?.balance ?? []).filter((b) => b.quota < 100 || b.used > 0 || b.pending > 0);
  return (
    <Stagger className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
      {rows.map((b) => {
        const t = toneClasses[leaveTone(b.color)];
        const pct = b.quota ? Math.min(100, (b.used / b.quota) * 100) : 0;
        return (
          <div key={b.code} className={clsx('h-full rounded-2xl border border-slate-200/70 p-4 transition hover:-translate-y-0.5 hover:shadow-card dark:border-slate-800', t.bg)}>
            <p className="truncate text-xs font-semibold text-slate-600 dark:text-slate-300" title={b.name}>{b.name}</p>
            {b.unlimited ? (
              <>
                <p className="mt-1 flex items-center gap-1.5 font-display text-2xl font-bold text-slate-900 dark:text-white">
                  <CountUp value={b.used} /> <span className="text-sm font-medium text-slate-500">days used</span>
                </p>
                <p className="mt-2 inline-flex items-center gap-1 text-[11px] text-slate-500 dark:text-slate-400">
                  <InfinityIcon className="h-3.5 w-3.5" /> No fixed quota{b.is_paid ? '' : ' · loss of pay'}
                </p>
              </>
            ) : (
              <>
                <p className="mt-1 font-display text-2xl font-bold text-slate-900 dark:text-white">
                  <CountUp value={b.available ?? 0} />
                  <span className="text-sm font-medium text-slate-500"> / {fmt(b.quota)} left</span>
                </p>
                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-white/70 dark:bg-slate-800">
                  <div className={clsx('h-full rounded-full transition-[width] duration-700 motion-reduce:transition-none', t.bar)} style={{ width: `${pct}%` }} />
                </div>
                <p className="mt-1.5 text-[11px] text-slate-500 dark:text-slate-400">
                  {fmt(b.used)} used{b.pending ? ` · ${fmt(b.pending)} pending` : ''}
                </p>
              </>
            )}
          </div>
        );
      })}
    </Stagger>
  );
}
