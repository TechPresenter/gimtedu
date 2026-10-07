import type { ReactNode } from 'react';
import clsx from 'clsx';
import { Avatar } from '@/components/ui';
import type { CalendarDay } from '../types';

export interface RegisterCell {
  label: string;
  tone: 'present' | 'absent' | 'late' | 'leave' | 'half_day' | 'partial' | 'low';
  title?: string;
}

export interface RegisterRow {
  key: string | number;
  name: string;
  sub?: string;
  photo?: string | null;
  cells: Record<number, RegisterCell | undefined>;
  totals: ReactNode[];
}

const toneCls: Record<RegisterCell['tone'], string> = {
  present: 'text-emerald-600 dark:text-emerald-400',
  late: 'bg-amber-100 text-amber-800 dark:bg-amber-500/20 dark:text-amber-200',
  absent: 'bg-red-100 text-red-700 dark:bg-red-500/20 dark:text-red-200',
  leave: 'bg-sky-100 text-sky-800 dark:bg-sky-500/20 dark:text-sky-200',
  half_day: 'bg-violet-100 text-violet-800 dark:bg-violet-500/20 dark:text-violet-200',
  partial: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
  low: 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-300',
};

/** Month register: people x days with sticky name column, holiday/weekly-off shading and totals. */
export function RegisterGrid({ days, rows, totalHeaders, footer, caption }: { days: CalendarDay[]; rows: RegisterRow[]; totalHeaders: string[]; footer?: { label: string; cells: Record<number, ReactNode>; totals?: ReactNode[] }; caption: string }) {
  const closed = (d: CalendarDay) => (d.holiday && ['holiday', 'vacation'].includes(d.holiday.type)) || d.weekly_off;
  return (
    <div className="table-wrap max-h-[70vh] overflow-auto">
      <table className="w-full border-separate border-spacing-0 text-xs">
        <caption className="sr-only">{caption}</caption>
        <thead className="sticky top-0 z-20">
          <tr>
            <th scope="col" className="sticky left-0 z-30 min-w-[13rem] border-b border-r border-slate-200 bg-slate-50 px-3 py-2 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
              Name
            </th>
            {days.map((d) => (
              <th
                key={d.date}
                scope="col"
                title={d.holiday ? d.holiday.title : d.weekly_off ? 'Weekly off' : undefined}
                className={clsx('min-w-[2.1rem] border-b border-slate-200 px-0.5 py-1.5 text-center font-semibold dark:border-slate-700', closed(d) ? 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-slate-50 text-slate-600 dark:bg-slate-800 dark:text-slate-300')}
              >
                <div className="text-[11px] leading-none">{d.day}</div>
                <div className="mt-0.5 text-[9px] font-medium uppercase leading-none opacity-70">{d.dow_label}</div>
              </th>
            ))}
            {totalHeaders.map((h) => (
              <th key={h} scope="col" className="border-b border-l border-slate-200 bg-slate-50 px-2 py-2 text-center text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-400">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.key} className="group">
              <th scope="row" className="sticky left-0 z-10 border-b border-r border-slate-100 bg-white px-3 py-1.5 text-left font-normal group-hover:bg-slate-50 dark:border-slate-800 dark:bg-slate-900 dark:group-hover:bg-slate-800">
                <div className="flex items-center gap-2">
                  <Avatar name={r.name} src={r.photo} size="xs" />
                  <div className="min-w-0 leading-tight">
                    <p className="max-w-[10rem] truncate text-xs font-semibold text-slate-800 dark:text-slate-100">{r.name}</p>
                    {r.sub && <p className="max-w-[10rem] truncate text-[10px] text-slate-400">{r.sub}</p>}
                  </div>
                </div>
              </th>
              {days.map((d) => {
                const c = r.cells[d.day];
                return (
                  <td key={d.date} className={clsx('border-b border-slate-100 p-0.5 text-center dark:border-slate-800', closed(d) && !c && 'bg-rose-50/60 dark:bg-rose-500/5', 'group-hover:bg-slate-50/60 dark:group-hover:bg-slate-800/40')}>
                    {c ? (
                      <span title={c.title} className={clsx('inline-flex h-6 min-w-[1.6rem] items-center justify-center rounded-md px-0.5 text-[10px] font-bold tabular-nums', toneCls[c.tone])}>
                        {c.label}
                      </span>
                    ) : (
                      <span className="text-[10px] text-slate-300 dark:text-slate-600">{closed(d) ? (d.weekly_off ? '·' : 'H') : ''}</span>
                    )}
                  </td>
                );
              })}
              {r.totals.map((t, i) => (
                <td key={i} className="border-b border-l border-slate-100 px-2 py-1.5 text-center font-semibold tabular-nums text-slate-700 dark:border-slate-800 dark:text-slate-200">
                  {t}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
        {footer && (
          <tfoot className="sticky bottom-0 z-20">
            <tr>
              <th scope="row" className="sticky left-0 z-30 border-r border-t border-slate-200 bg-slate-50 px-3 py-2 text-left text-[11px] font-semibold text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                {footer.label}
              </th>
              {days.map((d) => (
                <td key={d.date} className="border-t border-slate-200 bg-slate-50 px-0.5 py-2 text-center text-[10px] font-semibold tabular-nums text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                  {footer.cells[d.day] ?? ''}
                </td>
              ))}
              {totalHeaders.map((h, i) => (
                <td key={h} className="border-l border-t border-slate-200 bg-slate-50 px-2 py-2 text-center text-[11px] font-semibold tabular-nums text-slate-700 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200">
                  {footer.totals?.[i] ?? ''}
                </td>
              ))}
            </tr>
          </tfoot>
        )}
      </table>
    </div>
  );
}

export function RegisterLegend({ items }: { items: [string, RegisterCell['tone'], string][] }) {
  return (
    <div className="flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-500 dark:text-slate-400">
      {items.map(([code, tone, label]) => (
        <span key={code} className="inline-flex items-center gap-1.5">
          <span className={clsx('inline-flex h-5 min-w-[1.5rem] items-center justify-center rounded-md px-1 text-[10px] font-bold', toneCls[tone])}>{code}</span>
          {label}
        </span>
      ))}
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-flex h-5 w-6 items-center justify-center rounded-md bg-rose-50 text-[10px] font-bold text-rose-500 dark:bg-rose-500/10">H</span>
        Holiday / weekly off
      </span>
    </div>
  );
}
