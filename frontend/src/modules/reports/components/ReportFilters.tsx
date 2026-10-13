import { useId, useState } from 'react';
import clsx from 'clsx';
import { CalendarRange, ChevronDown, Filter, RotateCcw, SlidersHorizontal } from 'lucide-react';
import { Button, Card, Dropdown, Input, SearchInput, Select, type DropdownItem } from '@/components/ui';
import { formatNumber } from '@/lib/format';
import { filterValue, isoDay, shiftDays, type FilterMeta, type ReportMeta } from '../lib';

interface Props {
  meta: ReportMeta;
  params: URLSearchParams;
  search: string;
  onSearch: (v: string) => void;
  onChange: (changes: Record<string, string | null>) => void;
  onReset: () => void;
  activeCount: number;
  applied: string[];
  total: number | null;
}

/** Filter bar: search, date range (with quick ranges), session / department / program / semester / status + report-specific selects. */
export function ReportFilters({ meta, params, search, onSearch, onChange, onReset, activeCount, applied, total }: Props) {
  const dept = meta.filters.find((f) => f.name === 'department');
  // Phones: only the search box shows until the filter panel is expanded (tablet / desktop always show every filter).
  const [expanded, setExpanded] = useState(false);
  const panelId = useId();
  const collapsible = clsx(!expanded && 'max-sm:hidden');
  const deptValue = dept ? filterValue(params, dept) : '';

  const setFilter = (f: FilterMeta, v: string) => {
    const def = f.default ?? '';
    const changes: Record<string, string | null> = { [f.param]: v === def ? null : v === '' ? 'all' : v };
    // A program that belongs to another department no longer makes sense — clear it.
    if (f.name === 'department' && v) {
      const prog = meta.filters.find((x) => x.name === 'program');
      const current = prog ? filterValue(params, prog) : '';
      const opt = prog?.options?.find((o) => o.value === current);
      if (opt && opt.department_id && opt.department_id !== v) changes[prog!.param] = null;
    }
    onChange(changes);
  };

  return (
    <Card className="mb-5 p-4">
      <div id={panelId} className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        {meta.search && (
          <div className="min-w-0">
            <span className="form-label">Search</span>
            <SearchInput size="sm" value={search} onChange={onSearch} placeholder={meta.search} />
          </div>
        )}
        {meta.filters.map((f) =>
          f.type === 'daterange' ? (
            <DateRange key={f.param} meta={meta} filter={f} params={params} onChange={onChange} className={collapsible} />
          ) : (
            <div key={f.param} className={clsx('min-w-0', collapsible)}>
              <label htmlFor={`rf-${f.param}`} className="form-label">
                {f.label}
              </label>
              <Select
                id={`rf-${f.param}`}
                inputSize="sm"
                value={filterValue(params, f)}
                onChange={(e) => setFilter(f, e.target.value)}
                placeholder={f.required ? undefined : (f.all_label ?? 'All')}
                options={(f.options ?? []).filter((o) => f.name !== 'program' || !deptValue || !o.department_id || o.department_id === deptValue)}
              />
            </div>
          ),
        )}
      </div>
      {meta.filters.length > 0 && (
        <button
          type="button"
          aria-expanded={expanded}
          aria-controls={panelId}
          onClick={() => setExpanded((v) => !v)}
          className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-400 sm:hidden dark:border-slate-700 dark:text-slate-200 dark:hover:bg-slate-800"
        >
          <SlidersHorizontal className="h-4 w-4" aria-hidden />
          {expanded ? 'Hide filters' : `Show filters${activeCount ? ` (${activeCount} active)` : ''}`}
          <ChevronDown className={clsx('h-4 w-4 transition-transform motion-reduce:transition-none', expanded && 'rotate-180')} aria-hidden />
        </button>
      )}
      <div className="mt-3 flex flex-col gap-2 border-t border-slate-100 pt-3 text-xs sm:flex-row sm:items-center dark:border-slate-800">
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
          <span className="inline-flex items-center gap-1 font-medium text-slate-500 dark:text-slate-400">
            <Filter className="h-3.5 w-3.5" aria-hidden />
            {total === null ? 'Loading…' : `${formatNumber(total)} ${total === 1 ? 'row' : 'rows'}`}
          </span>
          {applied.map((a) => (
            <span key={a} className="max-w-full truncate rounded-full bg-brand-50 px-2 py-0.5 font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-200" title={a}>
              {a}
            </span>
          ))}
        </div>
        {activeCount > 0 && (
          <Button size="xs" variant="ghost" icon={RotateCcw} onClick={onReset} className="self-start sm:self-auto">
            Reset filters ({activeCount})
          </Button>
        )}
      </div>
    </Card>
  );
}

function DateRange({ meta, filter, params, onChange, className }: { meta: ReportMeta; filter: FilterMeta; params: URLSearchParams; onChange: Props['onChange']; className?: string }) {
  const from = params.get('from') ?? '';
  const to = params.get('to') ?? '';
  const today = meta.today || isoDay(new Date());
  const t = new Date(`${today}T00:00:00`);
  const monthStart = isoDay(new Date(t.getFullYear(), t.getMonth(), 1));
  const lastMonthStart = isoDay(new Date(t.getFullYear(), t.getMonth() - 1, 1));
  const lastMonthEnd = isoDay(new Date(t.getFullYear(), t.getMonth(), 0));
  const sessionFilter = meta.filters.find((f) => f.name === 'session');
  const sessionId = sessionFilter ? filterValue(params, sessionFilter) || sessionFilter.default : null;
  const session = meta.sessions.find((s) => String(s.id) === String(sessionId)) ?? meta.sessions.find((s) => s.start_date <= today && s.end_date >= today);
  const set = (f: string, tt: string) => onChange({ from: f || null, to: tt || null });
  const items: DropdownItem[] = [
    { label: 'Today', onClick: () => set(today, today) },
    { label: 'Last 7 days', onClick: () => set(shiftDays(today, -6), today) },
    { label: 'Last 30 days', onClick: () => set(shiftDays(today, -29), today) },
    { label: 'This month', onClick: () => set(monthStart, today) },
    { label: 'Last month', onClick: () => set(lastMonthStart, lastMonthEnd) },
    ...(session ? [{ label: `Session ${session.name}`, hint: 'full session', onClick: () => set(session.start_date, session.end_date) }] : []),
    { label: '', divider: true },
    { label: 'Any date', onClick: () => set('', ''), disabled: !from && !to },
  ];
  return (
    <div className={clsx('min-w-0 sm:col-span-2 xl:col-span-1', className)}>
      <div className="flex items-center justify-between gap-2">
        <span className="form-label">{filter.label}</span>
        <Dropdown
          label={`Quick ranges for ${filter.label.toLowerCase()}`}
          triggerClassName="mb-1 inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-semibold text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10"
          trigger={<><CalendarRange className="h-3.5 w-3.5" aria-hidden />Quick</>}
          items={items}
          width="w-52"
        />
      </div>
      <div className="flex items-center gap-1.5">
        <Input inputSize="sm" type="date" aria-label={`${filter.label} from`} value={from} max={to || undefined} onChange={(e) => onChange({ from: e.target.value || null })} className="min-w-0" />
        <span className="text-xs text-slate-400" aria-hidden>–</span>
        <Input inputSize="sm" type="date" aria-label={`${filter.label} to`} value={to} min={from || undefined} onChange={(e) => onChange({ to: e.target.value || null })} className="min-w-0" />
      </div>
    </div>
  );
}
