import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { Check, Minus } from 'lucide-react';
import { Avatar, PersonCell, StatusBadge } from '@/components/ui';
import { formatDate, formatDateTime, formatMoney, formatNumber, formatTime, labelize } from '@/lib/format';
import { appUrl } from '@/lib/config';
import type { BadgeColor } from '@/lib/status';
import type { CrudColumn } from './types';

const dash = <span className="text-slate-400">—</span>;

/** Fill "{field}" placeholders in a route template from a row. */
export function fillTemplate(tpl: string, row: Record<string, unknown>): string {
  return tpl.replace(/\{(\w+)\}/g, (_, k) => encodeURIComponent(String(row[k] ?? '')));
}

/** Render a list cell according to its column format. */
export function renderCell(col: CrudColumn, row: Record<string, unknown>, onView?: () => void): ReactNode {
  const v = row[col.key];
  const sub = col.sub ? (row[col.sub] as ReactNode) : undefined;
  const empty = v === null || v === undefined || v === '';
  const wrapLink = (node: ReactNode) => {
    if (!col.link) return node;
    if (col.link === 'view' && onView)
      return (
        <button type="button" onClick={onView} className="text-left font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
          {node}
        </button>
      );
    if (col.link.startsWith('/'))
      return (
        <Link to={fillTemplate(col.link, row)} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
          {node}
        </Link>
      );
    return node;
  };
  switch (col.format) {
    case 'person':
      if (empty) return dash;
      return (
        <PersonCell
          name={String(v)}
          sub={sub}
          src={col.image ? (row[col.image] as string | null) : null}
          to={col.link && col.link.startsWith('/') ? fillTemplate(col.link, row) : undefined}
        />
      );
    case 'title':
      return (
        <div className="min-w-0 leading-tight">
          <div className="font-semibold text-slate-900 dark:text-white">{wrapLink(empty ? '—' : String(v))}</div>
          {sub !== undefined && sub !== null && sub !== '' && <div className="text-xs text-slate-500 dark:text-slate-400">{sub}</div>}
        </div>
      );
    case 'badge':
      return <StatusBadge status={empty ? null : String(v)} colors={col.colors as Record<string, BadgeColor> | undefined} />;
    case 'date':
      return empty ? dash : <span className="whitespace-nowrap">{formatDate(v)}</span>;
    case 'datetime':
      return empty ? dash : <span className="whitespace-nowrap">{formatDateTime(v)}</span>;
    case 'time':
      return empty ? dash : <span className="whitespace-nowrap">{formatTime(v)}</span>;
    case 'money':
      return empty ? dash : <span className="whitespace-nowrap font-medium tabular-nums">{formatMoney(v)}</span>;
    case 'number':
      return empty ? dash : <span className="tabular-nums">{col.prefix}{/year/i.test(col.key) ? String(v) : formatNumber(v)}{col.suffix}</span>;
    case 'percent':
      return empty ? dash : <span className="tabular-nums">{Number(v).toFixed(1)}%</span>;
    case 'boolean':
      return Number(v) ? <Check className="mx-auto h-4 w-4 text-emerald-600" aria-label="Yes" /> : <Minus className="mx-auto h-4 w-4 text-slate-300" aria-label="No" />;
    case 'email':
      return empty ? dash : <a href={`mailto:${v}`} className="link !font-normal">{String(v)}</a>;
    case 'phone':
      return empty ? dash : <a href={`tel:${String(v).replace(/\s/g, '')}`} className="whitespace-nowrap hover:text-brand-700">{String(v)}</a>;
    case 'image':
      return empty ? <Avatar name={String(row.name ?? row.title ?? '?')} size="sm" /> : <img src={appUrl(String(v))} alt="" loading="lazy" className="h-10 w-14 rounded-md object-cover ring-1 ring-slate-200 dark:ring-slate-700" />;
    case 'tags':
      return empty ? dash : (
        <div className="flex flex-wrap gap-1">
          {(Array.isArray(v) ? v : String(v).split(',')).filter(Boolean).slice(0, 4).map((t) => (
            <span key={String(t)} className="badge badge-slate">{labelize(String(t).trim())}</span>
          ))}
        </div>
      );
    case 'color':
      return empty ? dash : <span className="inline-flex items-center gap-2"><span className="h-4 w-4 rounded border border-slate-200" style={{ background: String(v) }} />{String(v)}</span>;
    case 'code':
      return empty ? dash : <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{String(v)}</code>;
    case 'truncate': {
      const s = String(v ?? '');
      const n = col.truncate ?? 60;
      return empty ? dash : <span title={s}>{s.length > n ? `${s.slice(0, n - 1)}…` : s}</span>;
    }
    default:
      return empty ? dash : wrapLink(<span>{col.prefix}{String(v)}{col.suffix}</span>);
  }
}
