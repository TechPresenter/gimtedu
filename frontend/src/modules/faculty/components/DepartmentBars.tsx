import clsx from 'clsx';
import { Building2, X } from 'lucide-react';
import { Card, CardHeader, EmptyState, Skeleton } from '@/components/ui';
import { chartColor } from '@/components/charts';
import { CountUp } from '@/components/ui';

export interface DeptRow {
  id: number | null;
  code: string;
  name: string;
  total: number;
}

/** Ranked horizontal bars (department distribution) that act as table filters. */
export function DepartmentBars({ title, subtitle, rows, loading, activeId, onSelect }: {
  title: string;
  subtitle?: string;
  rows?: DeptRow[];
  loading?: boolean;
  activeId?: string | null;
  onSelect: (id: number | null) => void;
}) {
  const max = Math.max(1, ...(rows ?? []).map((r) => r.total));
  const total = (rows ?? []).reduce((a, r) => a + r.total, 0);
  return (
    <Card className="h-full">
      <CardHeader
        title={title}
        subtitle={subtitle}
        icon={Building2}
        actions={
          activeId ? (
            <button type="button" onClick={() => onSelect(null)} className="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 dark:hover:text-white">
              <X className="h-3.5 w-3.5" /> Clear
            </button>
          ) : undefined
        }
      />
      <div className="p-3 sm:p-4">
        {loading ? (
          <div className="space-y-4 p-2">
            {[1, 2, 3, 4, 5].map((i) => (
              <div key={i} className="space-y-2">
                <Skeleton className="h-3 w-1/3" />
                <Skeleton className="h-2.5" />
              </div>
            ))}
          </div>
        ) : !rows?.length ? (
          <EmptyState icon={Building2} title="No departments yet" description="Assign departments to see the distribution." className="!py-8" />
        ) : (
          <ul className={clsx(rows.length > 6 ? 'grid grid-cols-1 gap-x-3 gap-y-1 sm:grid-cols-2' : 'space-y-1')}>
            {rows.map((r, i) => {
              const selected = activeId !== null && activeId !== undefined && String(r.id) === activeId;
              return (
                <li key={`${r.id}-${r.code}`}>
                  <button
                    type="button"
                    disabled={r.id === null}
                    onClick={() => onSelect(selected ? null : r.id)}
                    className={clsx(
                      'group w-full rounded-xl px-3 py-2 text-left transition',
                      selected ? 'bg-brand-50 ring-1 ring-brand-200 dark:bg-brand-500/10 dark:ring-brand-500/30' : 'hover:bg-slate-50 dark:hover:bg-slate-800/60',
                    )}
                    aria-pressed={selected}
                  >
                    <div className="flex items-center justify-between gap-3 text-sm">
                      <span className="flex min-w-0 items-center gap-2">
                        <span className="inline-flex h-6 min-w-[2.75rem] items-center justify-center rounded-md px-1.5 text-[11px] font-bold text-white" style={{ background: chartColor(i) }}>
                          {r.code}
                        </span>
                        <span className="truncate font-medium text-slate-700 group-hover:text-slate-900 dark:text-slate-200 dark:group-hover:text-white">{r.name}</span>
                      </span>
                      <span className="shrink-0 tabular-nums text-slate-500 dark:text-slate-400">
                        <span className="font-semibold text-slate-900 dark:text-white"><CountUp value={r.total} /></span> · {total ? Math.round((r.total / total) * 100) : 0}%
                      </span>
                    </div>
                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                      <div className="h-full origin-left rounded-full transition-[width] duration-700 ease-out motion-reduce:transition-none" style={{ width: `${(r.total / max) * 100}%`, background: chartColor(i) }} />
                    </div>
                  </button>
                </li>
              );
            })}
          </ul>
        )}
      </div>
    </Card>
  );
}
