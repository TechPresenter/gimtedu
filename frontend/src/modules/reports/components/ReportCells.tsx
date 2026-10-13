import type { ReactNode } from 'react';
import clsx from 'clsx';
import { PersonCell, StatusBadge, type Column } from '@/components/ui';
import { formatDate, formatDateTime, formatMoney, formatNumber, labelize, toNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { monthLabel, type ColumnMeta, type ColumnFormat } from '../lib';

const NUMERIC: ColumnFormat[] = ['number', 'decimal', 'money', 'percent', 'progress', 'days', 'lpa', 'hours'];

const empty = <span className="text-slate-400 dark:text-slate-500">—</span>;

function withSub(main: ReactNode, sub: unknown, strong = false): ReactNode {
  const hasSub = sub !== null && sub !== undefined && sub !== '';
  if (!hasSub && !strong) return main;
  return (
    <div className="min-w-0 leading-tight">
      <span className={clsx('block', strong && 'font-semibold text-slate-900 dark:text-white')}>{main}</span>
      {hasSub && <span className="mt-0.5 block max-w-[200px] truncate text-xs text-slate-500 dark:text-slate-400" title={String(sub)}>{String(sub)}</span>}
    </div>
  );
}

/** One table cell, formatted by the column's server-declared format. */
export function ReportCell({ col, row }: { col: ColumnMeta; row: Row }): ReactNode {
  const v = row[col.key];
  const sub = col.sub ? row[col.sub] : null;
  const isEmpty = v === null || v === undefined || v === '';
  switch (col.format) {
    case 'person':
      return isEmpty ? empty : <PersonCell name={String(v)} sub={sub ? String(sub) : undefined} size="sm" />;
    case 'strong':
      return isEmpty ? empty : withSub(<span className="block max-w-[240px] truncate" title={String(v)}>{String(v)}</span>, sub, true);
    case 'code':
      return isEmpty ? empty : withSub(<code className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{String(v)}</code>, sub);
    case 'number':
      return isEmpty ? empty : <span className="whitespace-nowrap tabular-nums">{formatNumber(v)}{col.suffix ? ` ${col.suffix}` : ''}</span>;
    case 'decimal':
      return isEmpty ? empty : <span className="whitespace-nowrap tabular-nums">{formatNumber(v, 2)}{col.suffix ? ` ${col.suffix}` : ''}</span>;
    case 'money':
      return isEmpty ? empty : <span className={clsx('whitespace-nowrap tabular-nums', ['total', 'balance_amount', 'overdue_amount'].includes(col.key) && 'font-semibold text-slate-900 dark:text-white', col.key === 'overdue_amount' && toNumber(v) > 0 && '!text-red-600 dark:!text-red-400')}>{formatMoney(v)}</span>;
    case 'percent':
      return isEmpty ? empty : <span className="whitespace-nowrap tabular-nums">{toNumber(v).toFixed(1)}%</span>;
    case 'progress': {
      if (isEmpty) return empty;
      const n = toNumber(v);
      const warn = col.warn_below !== undefined && n < col.warn_below;
      return (
        <div className="ml-auto flex w-[118px] items-center justify-end gap-2">
          <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" aria-hidden>
            <div className={clsx('h-full rounded-full', warn ? 'bg-red-500' : 'bg-accent-500')} style={{ width: `${Math.min(100, Math.max(0, n))}%` }} />
          </div>
          <span className={clsx('w-12 text-right text-sm font-semibold tabular-nums', warn ? 'text-red-600 dark:text-red-400' : 'text-slate-800 dark:text-slate-100')}>{n.toFixed(1)}%</span>
        </div>
      );
    }
    case 'days': {
      const n = toNumber(v);
      if (isEmpty || n <= 0) return empty;
      return <span className={clsx('badge whitespace-nowrap', n > 60 ? 'badge-red' : n > 30 ? 'badge-amber' : 'badge-slate')}>{formatNumber(n)} d</span>;
    }
    case 'lpa':
      return isEmpty ? empty : <span className="whitespace-nowrap tabular-nums">{formatNumber(v, 2)} LPA</span>;
    case 'hours':
      return isEmpty ? empty : <span className="whitespace-nowrap tabular-nums">{formatNumber(v, 1)} h</span>;
    case 'date':
      return isEmpty ? empty : withSub(<span className="whitespace-nowrap">{formatDate(v)}</span>, sub);
    case 'datetime':
      return isEmpty ? empty : <span className="whitespace-nowrap">{formatDateTime(v)}</span>;
    case 'month':
      return isEmpty ? empty : <span className="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{monthLabel(v)}</span>;
    case 'badge':
      return isEmpty ? empty : <StatusBadge status={String(v).toLowerCase()} label={col.labels?.[String(v)] ?? labelize(String(v))} />;
    case 'label':
      return isEmpty ? empty : withSub(<span className="whitespace-nowrap">{col.labels?.[String(v)] ?? labelize(String(v))}</span>, sub);
    case 'boolean':
      return Number(v) ? <span className="badge badge-green">Yes</span> : <span className="text-xs text-slate-500 dark:text-slate-400">No</span>;
    default:
      return isEmpty ? (sub ? withSub(empty, sub) : empty) : withSub(<span className="block max-w-[200px] truncate" title={String(v)}>{String(v)}{col.suffix ? ` ${col.suffix}` : ''}</span>, sub);
  }
}

/** DataTable columns for a report (server-side sorting where the column allows it). */
export function reportColumns(cols: ColumnMeta[]): Column<Row>[] {
  // Wide reports (8+ columns) use tighter horizontal cell padding so more columns fit before the table scrolls.
  const tight = cols.length >= 8;
  return cols.map((c, i) => {
    const pad = tight ? clsx('!px-2.5', i === 0 && '!pl-4', i === cols.length - 1 && '!pr-4') : undefined;
    return {
      key: c.key,
      header: c.label,
      sortable: !!c.sortable,
      align: NUMERIC.includes(c.format) ? 'right' : undefined,
      headerClassName: clsx('whitespace-nowrap', pad),
      className: pad,
      render: (r: Row) => <ReportCell col={c} row={r} />,
    };
  });
}
