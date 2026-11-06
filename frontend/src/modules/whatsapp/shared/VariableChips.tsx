import clsx from 'clsx';
import { Braces } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Skeleton } from '@/components/ui';
import { useWaVariables } from './api';
import type { WaVariable } from './types';

/**
 * Clickable chips of the GIMT variable catalog (GET whatsapp/variables), grouped (Contact, Admission, Fees, …).
 * `onPick(key)` receives the variable key (e.g. 'student_name'); insert it as {{student_name}} or map it to a
 * positional {{n}}. `used` highlights keys already in use. `search` adds a filter box.
 */
export function VariableChips({
  onPick,
  used = [],
  groups,
  search = false,
  className,
  disabled,
}: {
  onPick?: (key: string, v: WaVariable) => void;
  used?: string[];
  groups?: string[];
  search?: boolean;
  className?: string;
  disabled?: boolean;
}) {
  const { data, isLoading, error } = useWaVariables();
  const [q, setQ] = useState('');
  const grouped = useMemo(() => {
    const out = new Map<string, WaVariable[]>();
    const needle = q.trim().toLowerCase();
    for (const v of data ?? []) {
      if (groups && !groups.includes(v.group)) continue;
      if (needle && !`${v.key} ${v.label}`.toLowerCase().includes(needle)) continue;
      if (!out.has(v.group)) out.set(v.group, []);
      out.get(v.group)!.push(v);
    }
    return out;
  }, [data, groups, q]);

  if (isLoading) {
    return (
      <div className={clsx('flex flex-wrap gap-1.5', className)}>
        {Array.from({ length: 8 }, (_, i) => (
          <Skeleton key={i} className="h-6 w-24 rounded-full" />
        ))}
      </div>
    );
  }
  if (error) {
    return <p className={clsx('text-xs text-red-600 dark:text-red-400', className)}>Could not load the variable list.</p>;
  }

  return (
    <div className={clsx('space-y-2.5', className)}>
      {search && (
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Search variables…"
          aria-label="Search variables"
          className="form-input form-input-sm w-full"
        />
      )}
      {grouped.size === 0 && <p className="text-xs text-slate-500 dark:text-slate-400">No variables match.</p>}
      {[...grouped.entries()].map(([group, vars]) => (
        <div key={group}>
          <p className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">{group}</p>
          <div className="flex flex-wrap gap-1.5">
            {vars.map((v) => {
              const active = used.includes(v.key);
              return (
                <button
                  key={v.key}
                  type="button"
                  disabled={disabled || !onPick}
                  onClick={() => onPick?.(v.key, v)}
                  title={`${v.label} — e.g. ${v.sample}`}
                  className={clsx(
                    'inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-mono text-[11px] ring-1 ring-inset transition',
                    'focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                    active
                      ? 'bg-brand-900 text-white ring-brand-900 dark:bg-brand-500 dark:ring-brand-400'
                      : 'bg-white text-slate-700 ring-slate-200 hover:bg-accent-50 hover:text-accent-700 hover:ring-accent-200 dark:bg-slate-800 dark:text-slate-200 dark:ring-slate-700 dark:hover:bg-slate-700',
                    (disabled || !onPick) && 'cursor-default opacity-70 hover:bg-white dark:hover:bg-slate-800',
                  )}
                >
                  <Braces size={11} aria-hidden className="opacity-60" />
                  {v.key}
                </button>
              );
            })}
          </div>
        </div>
      ))}
    </div>
  );
}

export default VariableChips;
