import { useMemo, useState, type ReactNode } from 'react';
import clsx from 'clsx';
import { Check, ChevronDown, Minus, Search } from 'lucide-react';
import type { MatrixGroup, MatrixModule } from '../types';

export const ACTION_ORDER = ['view', 'create', 'edit', 'delete', 'export', 'import', 'approve', 'publish', 'manage'];

type Sel = Set<string>;
const key = (m: string, a: string) => `${m}.${a}`;

/** Apply a toggle with "manage implies everything" consistency rules. */
export function toggleCells(sel: Sel, cells: { module: MatrixModule; action: string }[], on: boolean): Sel {
  const next = new Set(sel);
  cells.forEach(({ module, action }) => {
    if (!module.actions.includes(action)) return;
    if (on) {
      if (action === 'manage') module.actions.forEach((a) => next.add(key(module.key, a)));
      else next.add(key(module.key, action));
    } else {
      next.delete(key(module.key, action));
      if (action !== 'manage') next.delete(key(module.key, 'manage'));
    }
  });
  return next;
}

/** Tri-state checkbox (checked / indeterminate / empty). */
function TriBox({ state, onClick, label, disabled, size = 'md' }: { state: 'all' | 'some' | 'none'; onClick: () => void; label: string; disabled?: boolean; size?: 'sm' | 'md' }) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={state === 'all' ? true : state === 'some' ? 'mixed' : false}
      aria-label={label}
      title={label}
      disabled={disabled}
      onClick={onClick}
      className={clsx(
        'inline-flex shrink-0 items-center justify-center rounded-md border transition duration-150 focus-visible:ring-2 focus-visible:ring-brand-500 disabled:cursor-not-allowed',
        size === 'sm' ? 'h-4 w-4' : 'h-[22px] w-[22px]',
        state === 'all' ? 'border-accent-600 bg-accent-600 text-white shadow-sm' : state === 'some' ? 'border-accent-600 bg-accent-50 text-accent-700 dark:bg-accent-500/15' : 'border-slate-300 bg-white hover:border-accent-500 dark:border-slate-600 dark:bg-slate-900',
        !disabled && 'active:scale-90',
        disabled && state !== 'none' && 'opacity-70',
      )}
    >
      {state === 'all' ? <Check className={size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5'} strokeWidth={3} /> : state === 'some' ? <Minus className="h-3 w-3" strokeWidth={3} /> : null}
    </button>
  );
}

interface Props {
  groups: MatrixGroup[];
  actions: Record<string, { label: string; description: string }>;
  selected: Sel;
  original: Sel;
  onChange: (s: Sel) => void;
  readOnly?: boolean;
  /** Super admin: everything granted */
  all?: boolean;
}

/** Modules (grouped by registry group) × actions grid with row / column / group select-all. */
export function PermissionMatrix({ groups, actions, selected, original, onChange, readOnly, all }: Props) {
  const [q, setQ] = useState('');
  const [collapsed, setCollapsed] = useState<Record<string, boolean>>({});
  const term = q.trim().toLowerCase();
  const visibleGroups = useMemo(
    () => groups.map((g) => ({ ...g, modules: g.modules.filter((m) => !term || m.label.toLowerCase().includes(term) || m.key.includes(term) || g.group.toLowerCase().includes(term)) })).filter((g) => g.modules.length),
    [groups, term],
  );
  const visibleModules = visibleGroups.flatMap((g) => g.modules);
  const has = (m: string, a: string) => all || selected.has(key(m, a));
  const stateOf = (cells: { module: MatrixModule; action: string }[]): 'all' | 'some' | 'none' => {
    const applicable = cells.filter((c) => c.module.actions.includes(c.action));
    if (!applicable.length) return 'none';
    const n = applicable.filter((c) => has(c.module.key, c.action)).length;
    return n === 0 ? 'none' : n === applicable.length ? 'all' : 'some';
  };
  const cellsFor = (mods: MatrixModule[], acts = ACTION_ORDER) => mods.flatMap((m) => acts.filter((a) => m.actions.includes(a)).map((a) => ({ module: m, action: a })));
  const flip = (cells: { module: MatrixModule; action: string }[]) => {
    if (readOnly) return;
    onChange(toggleCells(selected, cells, stateOf(cells) !== 'all'));
  };

  return (
    <div>
      <div className="relative mb-3">
        <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" aria-hidden />
        <input
          type="search"
          value={q}
          onChange={(e) => setQ(e.target.value)}
          placeholder="Filter modules… (e.g. fees, website)"
          aria-label="Filter modules"
          className="form-input form-input-sm pl-9 sm:max-w-xs"
        />
      </div>
      <div className="max-h-[68vh] overflow-auto rounded-xl border border-slate-200 dark:border-slate-800">
        <table className="w-full min-w-[720px] border-separate border-spacing-0 text-sm">
          <thead>
            <tr>
              <th className="sticky left-0 top-0 z-[3] w-[140px] border-b sm:w-[190px] border-slate-200 bg-slate-50 px-4 py-3 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-800 dark:bg-slate-900 dark:text-slate-400">
                Module
              </th>
              {ACTION_ORDER.map((a) => {
                const cells = cellsFor(visibleModules, [a]);
                return (
                  <th key={a} className="sticky top-0 z-[2] border-b border-slate-200 bg-slate-50 px-1 py-2 text-center dark:border-slate-800 dark:bg-slate-900">
                    <div className="flex flex-col items-center gap-1.5">
                      <span tabIndex={0} className="group relative inline-flex cursor-help items-center text-[10.5px] font-semibold uppercase tracking-wide text-slate-500 outline-none dark:text-slate-400">
                        {actions[a]?.label ?? a}
                        <span role="tooltip" className="pointer-events-none absolute left-1/2 top-full z-20 mt-2 w-52 -translate-x-1/2 rounded-lg bg-brand-950 px-3 py-2 text-left text-[11px] font-normal normal-case tracking-normal text-white opacity-0 shadow-pop transition duration-150 group-hover:opacity-100 group-focus:opacity-100">
                          {actions[a]?.description}
                        </span>
                      </span>
                      <TriBox size="sm" state={stateOf(cells)} onClick={() => flip(cells)} label={`Toggle ${a} for all listed modules`} disabled={readOnly || !cells.length} />
                    </div>
                  </th>
                );
              })}
            </tr>
          </thead>
          <tbody>
            {visibleGroups.map((g) => {
              const gCells = cellsFor(g.modules);
              const isCollapsed = collapsed[g.group] && !term;
              return (
                <GroupRows key={g.group}>
                  <tr className="bg-brand-50/60 dark:bg-brand-500/[.06]">
                    <td colSpan={ACTION_ORDER.length + 1} className="sticky left-0 border-b border-slate-200 px-4 py-2 dark:border-slate-800">
                      <div className="flex items-center gap-3">
                        <TriBox size="sm" state={stateOf(gCells)} onClick={() => flip(gCells)} label={`Toggle every permission in ${g.group}`} disabled={readOnly} />
                        <button type="button" onClick={() => setCollapsed((c) => ({ ...c, [g.group]: !c[g.group] }))} className="inline-flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-brand-800 dark:text-brand-200" aria-expanded={!isCollapsed}>
                          <ChevronDown className={clsx('h-3.5 w-3.5 transition-transform duration-200', isCollapsed && '-rotate-90')} />
                          {g.group}
                        </button>
                        <span className="text-[11px] text-slate-500">
                          {gCells.filter((c) => has(c.module.key, c.action)).length}/{gCells.length}
                        </span>
                      </div>
                    </td>
                  </tr>
                  {!isCollapsed &&
                    g.modules.map((m) => {
                      const rowCells = cellsFor([m]);
                      const granted = rowCells.filter((c) => has(m.key, c.action)).length;
                      return (
                        <tr key={m.key} className="group/row transition-colors hover:bg-slate-50/80 dark:hover:bg-slate-800/40">
                          <td className="sticky left-0 z-[1] border-b border-slate-100 bg-white px-3 py-2.5 transition-colors group-hover/row:bg-slate-50 dark:border-slate-800/80 dark:bg-slate-900 dark:group-hover/row:bg-slate-800/60">
                            <div className="flex items-center gap-3">
                              <TriBox size="sm" state={stateOf(rowCells)} onClick={() => flip(rowCells)} label={`Toggle all ${m.label} permissions`} disabled={readOnly} />
                              <div className="min-w-0">
                                <p className="max-w-[92px] truncate font-medium sm:max-w-[150px] text-slate-800 dark:text-slate-100" title={m.label}>{m.label}</p>
                                <p className="text-[11px] text-slate-400">
                                  {granted ? `${granted} of ${rowCells.length} granted` : 'No access'}
                                </p>
                              </div>
                            </div>
                          </td>
                          {ACTION_ORDER.map((a) => {
                            const applicable = m.actions.includes(a);
                            if (!applicable) {
                              return (
                                <td key={a} className="border-b border-slate-100 text-center dark:border-slate-800/80">
                                  <span className="text-slate-200 dark:text-slate-700" aria-label="Not applicable">—</span>
                                </td>
                              );
                            }
                            const on = has(m.key, a);
                            const changed = !all && on !== original.has(key(m.key, a));
                            const implied = !all && !on && a !== 'manage' && has(m.key, 'manage');
                            return (
                              <td key={a} className={clsx('border-b border-slate-100 px-1 py-2 text-center dark:border-slate-800/80', changed && 'bg-amber-50/70 dark:bg-amber-500/[.07]')}>
                                <span className="inline-flex">
                                  <TriBox state={on || implied ? 'all' : 'none'} onClick={() => flip([{ module: m, action: a }])} label={`${actions[a]?.label ?? a} ${m.label}`} disabled={readOnly} />
                                </span>
                              </td>
                            );
                          })}
                        </tr>
                      );
                    })}
                </GroupRows>
              );
            })}
            {visibleGroups.length === 0 && (
              <tr>
                <td colSpan={ACTION_ORDER.length + 1} className="px-4 py-10 text-center text-sm text-slate-500">
                  No modules match “{q}”.
                </td>
              </tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  );
}

function GroupRows({ children }: { children: ReactNode }) {
  return <>{children}</>;
}
