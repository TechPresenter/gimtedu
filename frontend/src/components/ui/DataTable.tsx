import type { ReactNode } from 'react';
import clsx from 'clsx';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Inbox } from 'lucide-react';
import { EmptyState, Skeleton } from './Display';
import { formatNumber } from '@/lib/format';

export interface Column<T> {
  key: string;
  header: ReactNode;
  render?: (row: T, index: number) => ReactNode;
  sortable?: boolean;
  align?: 'left' | 'center' | 'right';
  className?: string;
  headerClassName?: string;
  width?: string;
  hidden?: boolean;
}

export interface SortState {
  key: string;
  dir: 'asc' | 'desc';
}

interface DataTableProps<T> {
  columns: Column<T>[];
  rows: T[];
  rowKey?: (row: T, index: number) => string | number;
  loading?: boolean;
  sort?: SortState | null;
  onSort?: (s: SortState) => void;
  selectable?: boolean;
  selected?: Set<string | number>;
  onSelectedChange?: (s: Set<string | number>) => void;
  empty?: ReactNode;
  onRowClick?: (row: T) => void;
  rowClassName?: (row: T) => string | undefined;
  /** Renders right-aligned action cell */
  actions?: (row: T) => ReactNode;
  dense?: boolean;
  skeletonRows?: number;
  caption?: string;
}

/** Presentational data table with sorting, selection, skeleton loading and empty state. */
export function DataTable<T extends Record<string, any>>({ // eslint-disable-line @typescript-eslint/no-explicit-any
  columns,
  rows,
  rowKey = (r, i) => (r.id as number) ?? i,
  loading,
  sort,
  onSort,
  selectable,
  selected,
  onSelectedChange,
  empty,
  onRowClick,
  rowClassName,
  actions,
  dense,
  skeletonRows = 8,
  caption,
}: DataTableProps<T>) {
  const visible = columns.filter((c) => !c.hidden);
  const keys = rows.map((r, i) => rowKey(r, i));
  const allSelected = selectable && rows.length > 0 && keys.every((k) => selected?.has(k));
  const someSelected = selectable && keys.some((k) => selected?.has(k));
  const toggleAll = () => {
    const next = new Set(selected);
    if (allSelected) keys.forEach((k) => next.delete(k));
    else keys.forEach((k) => next.add(k));
    onSelectedChange?.(next);
  };
  const toggle = (k: string | number) => {
    const next = new Set(selected);
    if (next.has(k)) next.delete(k);
    else next.add(k);
    onSelectedChange?.(next);
  };
  const colCount = visible.length + (selectable ? 1 : 0) + (actions ? 1 : 0);
  const cellPad = dense ? '!py-2' : '';

  return (
    <div className="table-wrap">
      <table className="data-table">
        {caption && <caption className="sr-only">{caption}</caption>}
        <thead>
          <tr>
            {selectable && (
              <th className="w-10 !pr-0">
                <input
                  type="checkbox"
                  className="form-checkbox"
                  aria-label="Select all rows"
                  checked={!!allSelected}
                  ref={(el) => {
                    if (el) el.indeterminate = !allSelected && !!someSelected;
                  }}
                  onChange={toggleAll}
                />
              </th>
            )}
            {visible.map((c) => {
              const active = sort?.key === c.key;
              return (
                <th key={c.key} className={clsx(c.align === 'right' && 'text-right', c.align === 'center' && 'text-center', c.headerClassName)} style={c.width ? { width: c.width } : undefined} aria-sort={active ? (sort?.dir === 'asc' ? 'ascending' : 'descending') : undefined}>
                  {c.sortable && onSort ? (
                    <button
                      type="button"
                      className={clsx('inline-flex items-center gap-1 uppercase tracking-wider hover:text-slate-800 dark:hover:text-white', active && 'text-slate-800 dark:text-white')}
                      onClick={() => onSort({ key: c.key, dir: active && sort?.dir === 'asc' ? 'desc' : 'asc' })}
                    >
                      {c.header}
                      {active ? sort?.dir === 'asc' ? <ArrowUp className="h-3 w-3" /> : <ArrowDown className="h-3 w-3" /> : <ArrowUpDown className="h-3 w-3 opacity-40" />}
                    </button>
                  ) : (
                    c.header
                  )}
                </th>
              );
            })}
            {actions && <th className="w-px text-right">Actions</th>}
          </tr>
        </thead>
        <tbody>
          {loading && rows.length === 0
            ? Array.from({ length: skeletonRows }).map((_, i) => (
                <tr key={`sk${i}`}>
                  {Array.from({ length: colCount }).map((__, j) => (
                    <td key={j} className={cellPad}>
                      <Skeleton className={clsx('h-4', j === 0 ? 'w-4' : j % 3 === 1 ? 'w-40' : 'w-24')} />
                    </td>
                  ))}
                </tr>
              ))
            : rows.map((row, i) => {
                const k = keys[i];
                return (
                  <tr
                    key={k}
                    className={clsx(onRowClick && 'cursor-pointer', selected?.has(k) && '!bg-brand-50/60 dark:!bg-brand-500/10', loading && 'opacity-60', rowClassName?.(row))}
                    onClick={onRowClick ? (e) => !(e.target as HTMLElement).closest('a,button,input,label') && onRowClick(row) : undefined}
                  >
                    {selectable && (
                      <td className={clsx('!pr-0', cellPad)}>
                        <input type="checkbox" className="form-checkbox" aria-label="Select row" checked={!!selected?.has(k)} onChange={() => toggle(k)} />
                      </td>
                    )}
                    {visible.map((c) => (
                      <td key={c.key} className={clsx(cellPad, c.align === 'right' && 'text-right', c.align === 'center' && 'text-center', c.className)}>
                        {c.render ? c.render(row, i) : (row[c.key] ?? <span className="text-slate-400">—</span>)}
                      </td>
                    ))}
                    {actions && <td className={clsx('whitespace-nowrap text-right', cellPad)}>{actions(row)}</td>}
                  </tr>
                );
              })}
          {!loading && rows.length === 0 && (
            <tr>
              <td colSpan={colCount} className="!border-0">
                {empty ?? <EmptyState icon={Inbox} title="No records found" description="Try adjusting your search or filters." />}
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
}

interface PaginationProps {
  page: number;
  pages: number;
  total: number;
  perPage: number;
  onPage: (p: number) => void;
  onPerPage?: (n: number) => void;
  perPageOptions?: number[];
  className?: string;
}

export function Pagination({ page, pages, total, perPage, onPage, onPerPage, perPageOptions = [10, 25, 50, 100], className }: PaginationProps) {
  const from = total ? (page - 1) * perPage + 1 : 0;
  const to = Math.min(total, page * perPage);
  const nums: (number | '…')[] = [];
  const window = 1;
  for (let i = 1; i <= pages; i++) {
    if (i === 1 || i === pages || (i >= page - window && i <= page + window)) nums.push(i);
    else if (nums[nums.length - 1] !== '…') nums.push('…');
  }
  const btn = 'inline-flex h-8 min-w-[2rem] items-center justify-center rounded-lg px-2 text-sm font-medium transition disabled:pointer-events-none disabled:opacity-40';
  return (
    <div className={clsx('flex flex-col items-center justify-between gap-3 px-4 py-3 text-sm sm:flex-row', className)}>
      <div className="flex items-center gap-3 text-slate-500 dark:text-slate-400">
        <span>
          Showing <strong className="font-semibold text-slate-700 dark:text-slate-200">{formatNumber(from)}</strong>–<strong className="font-semibold text-slate-700 dark:text-slate-200">{formatNumber(to)}</strong> of{' '}
          <strong className="font-semibold text-slate-700 dark:text-slate-200">{formatNumber(total)}</strong>
        </span>
        {onPerPage && (
          <select className="form-input form-input-sm !w-auto !py-1" value={perPage} onChange={(e) => onPerPage(Number(e.target.value))} aria-label="Rows per page">
            {perPageOptions.map((n) => (
              <option key={n} value={n}>
                {n} / page
              </option>
            ))}
          </select>
        )}
      </div>
      <nav className="flex items-center gap-1" aria-label="Pagination">
        <button type="button" className={clsx(btn, 'hover:bg-slate-100 dark:hover:bg-slate-800')} disabled={page <= 1} onClick={() => onPage(1)} aria-label="First page">
          <ChevronsLeft className="h-4 w-4" />
        </button>
        <button type="button" className={clsx(btn, 'hover:bg-slate-100 dark:hover:bg-slate-800')} disabled={page <= 1} onClick={() => onPage(page - 1)} aria-label="Previous page">
          <ChevronLeft className="h-4 w-4" />
        </button>
        {nums.map((n, i) =>
          n === '…' ? (
            <span key={`e${i}`} className="px-1 text-slate-400">
              …
            </span>
          ) : (
            <button
              key={n}
              type="button"
              onClick={() => onPage(n)}
              aria-current={n === page ? 'page' : undefined}
              className={clsx(btn, n === page ? 'bg-brand-800 text-white shadow-sm dark:bg-brand-600' : 'text-slate-600 hover:bg-slate-100 dark:text-slate-300 dark:hover:bg-slate-800')}
            >
              {n}
            </button>
          ),
        )}
        <button type="button" className={clsx(btn, 'hover:bg-slate-100 dark:hover:bg-slate-800')} disabled={page >= pages} onClick={() => onPage(page + 1)} aria-label="Next page">
          <ChevronRight className="h-4 w-4" />
        </button>
        <button type="button" className={clsx(btn, 'hover:bg-slate-100 dark:hover:bg-slate-800')} disabled={page >= pages} onClick={() => onPage(pages)} aria-label="Last page">
          <ChevronsRight className="h-4 w-4" />
        </button>
      </nav>
    </div>
  );
}
