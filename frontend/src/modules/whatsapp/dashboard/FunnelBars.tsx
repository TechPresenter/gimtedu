import clsx from 'clsx';
import { ChevronDown } from 'lucide-react';
import { formatNumber } from '@/lib/format';
import type { FunnelStage } from '../admin/lib';

/** Navy → orange gradient steps for the admission funnel (GIMT brand). */
const STEP_COLORS = ['#0B2A5B', '#163C7A', '#1F4A94', '#2F5FB0', '#5480C8', '#E8723F', '#F05A28', '#D03E16'];

/**
 * Horizontal funnel: one bar per stage (width = share of the first stage) with the stage-to-stage conversion between
 * rows. Pure CSS (no chart library) so it stays crisp in light and dark mode and reads well on phones.
 */
export function FunnelBars({ stages, compact }: { stages: FunnelStage[]; compact?: boolean }) {
  const max = Math.max(1, stages[0]?.reached ?? 0);
  return (
    <ol className="space-y-1" aria-label="Admission funnel">
      {stages.map((s, i) => {
        const w = Math.max(4, Math.round((s.reached / max) * 100));
        const color = STEP_COLORS[Math.min(i, STEP_COLORS.length - 1)];
        return (
          <li key={s.key}>
            {i > 0 && (
              <div className="flex items-center gap-1 pl-1 text-[11px] text-slate-400 dark:text-slate-500">
                <ChevronDown className="h-3 w-3" aria-hidden />
                <span>{s.pct_of_prev.toFixed(s.pct_of_prev % 1 ? 1 : 0)}% moved on</span>
              </div>
            )}
            <div className="flex items-center gap-3">
              <div className={clsx('min-w-0 shrink-0 text-slate-600 dark:text-slate-300', compact ? 'w-28 text-xs sm:w-36' : 'w-32 text-sm sm:w-44')}>
                <span className="block truncate" title={s.label}>
                  {s.label}
                </span>
              </div>
              <div className="relative flex h-7 min-w-0 flex-1 items-center overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800/70">
                <div
                  className="flex h-full shrink-0 items-center rounded-lg px-2 text-xs font-semibold text-white transition-[width] duration-500 ease-out motion-reduce:transition-none"
                  style={{ width: `${w}%`, background: color }}
                >
                  {w >= 14 && <span className="truncate tabular-nums">{formatNumber(s.reached)}</span>}
                </div>
                {w < 14 && <span className="ml-2 text-xs font-semibold tabular-nums text-slate-700 dark:text-slate-200">{formatNumber(s.reached)}</span>}
              </div>
              <span className="w-12 shrink-0 text-right text-xs font-medium tabular-nums text-slate-500 dark:text-slate-400">{s.pct_of_total.toFixed(0)}%</span>
            </div>
          </li>
        );
      })}
    </ol>
  );
}
