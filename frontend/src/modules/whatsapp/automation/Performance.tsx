import { useState } from 'react';
import clsx from 'clsx';
import { formatNumber } from '@/lib/format';
import type { ApiError } from '@/lib/api';
import { useWorkflowStats } from './api';
import { NODE_SPECS, PORT_LABEL } from './nodeTypes';
import type { Port } from './types';

function duration(min: number | null): string {
  if (min === null) return '—';
  if (min < 60) return `${min} min`;
  if (min < 1440) return `${Math.round(min / 60)} h`;
  return `${(min / 1440).toFixed(1)} d`;
}

/** Automation performance of one workflow: outcomes, time to finish, message delivery and per-step funnel. */
export function PerformancePanel({ workflowId, onFocusNode }: { workflowId: number; onFocusNode: (id: string) => void }) {
  const [days, setDays] = useState(30);
  const { data, isLoading, error, refetch } = useWorkflowStats(workflowId, days);
  const nodes = (data?.nodes ?? []).filter((n) => n.type !== 'trigger' && n.reached > 0);
  const max = Math.max(1, ...nodes.map((n) => n.reached));
  const readRate = data && data.messages.sent ? Math.round((data.messages.read / data.messages.sent) * 100) : null;
  return (
    <div>
      <div className="mb-2 flex items-center justify-between gap-2">
        <p className="text-[11px] font-bold uppercase tracking-wider text-slate-500 dark:text-slate-400">Performance</p>
        <select className="form-input form-input-sm w-auto py-0.5 pr-7 text-xs" value={days} onChange={(e) => setDays(Number(e.target.value))} aria-label="Performance period">
          <option value={7}>7 days</option>
          <option value={30}>30 days</option>
          <option value={90}>90 days</option>
        </select>
      </div>
      {isLoading ? (
        <div className="space-y-2">
          <div className="skeleton h-14 w-full rounded-xl" />
          <div className="skeleton h-24 w-full rounded-xl" />
        </div>
      ) : error ? (
        <p className="rounded-lg bg-red-50 px-2.5 py-2 text-xs text-red-700 dark:bg-red-500/10 dark:text-red-200">
          {(error as ApiError).message}{' '}
          <button type="button" className="font-semibold underline" onClick={() => refetch()}>
            Retry
          </button>
        </p>
      ) : !data || data.runs.total === 0 ? (
        <p className="rounded-xl bg-slate-50 px-3 py-2.5 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">No live runs in the last {days} days. Test runs are not counted.</p>
      ) : (
        <div className="space-y-3">
          <div className="grid grid-cols-2 gap-2 text-center">
            {[
              ['Completed', data.runs.completion_rate !== null ? `${data.runs.completion_rate}%` : '—'],
              ['Avg. time to finish', duration(data.runs.avg_minutes)],
              ['Messages queued', formatNumber(data.messages.queued)],
              ['Read (tracked)', readRate !== null ? `${readRate}%` : '—'],
            ].map(([l, v]) => (
              <div key={l} className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/60">
                <p className="font-display text-sm font-bold tabular-nums text-slate-900 dark:text-white">{v}</p>
                <p className="text-[10px] text-slate-500 dark:text-slate-400">{l}</p>
              </div>
            ))}
          </div>
          <ul className="space-y-1.5" aria-label="Runs per step">
            {nodes.map((n) => {
              const S = NODE_SPECS[n.type];
              const branches = Object.entries(n.branches ?? {});
              return (
                <li key={n.id}>
                  <button type="button" onClick={() => onFocusNode(n.id)} className="w-full rounded-lg px-1.5 py-1 text-left hover:bg-slate-50 dark:hover:bg-slate-800/60">
                    <span className="flex items-center gap-1.5 text-[11.5px]">
                      {S && <S.icon className="h-3 w-3 shrink-0 text-slate-400" aria-hidden />}
                      <span className="min-w-0 flex-1 truncate font-medium text-slate-700 dark:text-slate-200">{n.title}</span>
                      <span className="tabular-nums text-slate-500 dark:text-slate-400">{formatNumber(n.reached)}</span>
                    </span>
                    <span className="mt-0.5 block h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                      <span className={clsx('block h-full rounded-full', n.failed ? 'bg-red-500' : 'bg-brand-600 dark:bg-brand-400')} style={{ width: `${Math.max(4, (n.reached / max) * 100)}%` }} />
                    </span>
                    {(n.failed > 0 || n.skipped > 0 || n.waiting > 0 || branches.length > 0) && (
                      <span className="mt-0.5 flex flex-wrap gap-x-2 text-[10.5px] text-slate-500 dark:text-slate-400">
                        {n.failed > 0 && <span className="font-semibold text-red-600 dark:text-red-300">{n.failed} failed</span>}
                        {n.skipped > 0 && <span>{n.skipped} skipped</span>}
                        {n.waiting > 0 && <span className="text-amber-700 dark:text-amber-300">{n.waiting} waiting</span>}
                        {branches.map(([b, c]) => (
                          <span key={b}>
                            {PORT_LABEL[b as Port] ?? b} {c}
                          </span>
                        ))}
                      </span>
                    )}
                  </button>
                </li>
              );
            })}
          </ul>
          <p className="text-[10.5px] text-slate-400 dark:text-slate-500">
            {formatNumber(data.runs.total)} live runs · {formatNumber(data.runs.failed)} failed · {formatNumber(data.runs.cancelled)} stopped. Test runs excluded.
          </p>
        </div>
      )}
    </div>
  );
}
