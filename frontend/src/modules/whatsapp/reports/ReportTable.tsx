import { useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Download, FileSpreadsheet, FileText, Table2 } from 'lucide-react';
import { Badge, Card, CardHeader, DataTable, Dropdown, EmptyState, Pagination, Spinner, StatusBadge, useToast, type Column, type SortState } from '@/components/ui';
import { downloadFile, type ApiError, type Query } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatMoney, formatNumber, formatPercent, labelize, toNumber } from '@/lib/format';
import type { ReportColumn, ReportTable as Table } from '../admin/lib';
import { RateBar } from '../admin/ui';

type Row = Record<string, unknown>;

function linkFor(c: ReportColumn, r: Row): string | null {
  if (!c.link) return null;
  if (c.link.includes('{id}') && (r.id === undefined || r.id === null)) return null;
  return c.link.replace('{id}', String(r.id ?? ''));
}

/** Permission module a report link opens (report viewers may not have access to every area). */
const LINK_MODULES: [string, string][] = [
  ['/whatsapp/campaigns', 'whatsapp_campaigns'],
  ['/whatsapp/contacts', 'whatsapp_contacts'],
  ['/whatsapp/automations', 'whatsapp_automation'],
  ['/whatsapp/templates', 'whatsapp_templates'],
  ['/whatsapp/inbox', 'whatsapp'],
];
export function linkModule(to: string): string | null {
  return LINK_MODULES.find(([p]) => to.startsWith(p))?.[1] ?? null;
}

/** Render one report cell by its column format. `canOpen` hides links the viewer may not follow. */
export function renderReportCell(c: ReportColumn, r: Row, canOpen: (to: string) => boolean = () => true) {
  const v = r[c.key];
  if (v === null || v === undefined || v === '') return <span className="text-slate-400">—</span>;
  switch (c.format) {
    case 'number':
      return <span className="tabular-nums">{formatNumber(v)}</span>;
    case 'decimal':
      return <span className="tabular-nums">{formatNumber(v, 1)}</span>;
    case 'percent':
      return c.key.endsWith('_rate') ? <RateBar value={toNumber(v)} tone={c.key.startsWith('failure') ? 'red' : c.key.startsWith('read') ? 'blue' : 'green'} /> : <span className="tabular-nums">{formatPercent(v, 1)}</span>;
    case 'money':
      return <span className="tabular-nums">{formatMoney(v, 2)}</span>;
    case 'date':
      return <span className="whitespace-nowrap">{formatDate(v)}</span>;
    case 'datetime':
      return <span className="whitespace-nowrap">{formatDateTime(v)}</span>;
    case 'badge':
      return <Badge color="slate">{labelize(String(v))}</Badge>;
    case 'status':
      return <StatusBadge status={String(v)} />;
    case 'code':
      return <code className="rounded bg-slate-100 px-1.5 py-0.5 text-[12px] text-slate-700 dark:bg-slate-800 dark:text-slate-200">{String(v)}</code>;
    case 'truncate':
      return (
        <span className="block max-w-[28rem] truncate" title={String(v)}>
          {String(v)}
        </span>
      );
    case 'person':
      return (
        <span className="block min-w-0">
          <span className="block font-medium text-slate-800 dark:text-slate-100">{String(v)}</span>
          {c.sub && r[c.sub] ? <span className="block text-xs text-slate-500 dark:text-slate-400">{String(r[c.sub])}</span> : null}
        </span>
      );
    case 'link': {
      const to = linkFor(c, r);
      const body = (
        <span className="block min-w-0">
          <span className="block font-medium">{String(v)}</span>
          {c.sub && r[c.sub] ? <span className="block text-xs text-slate-500 dark:text-slate-400">{String(r[c.sub])}</span> : null}
        </span>
      );
      return to && canOpen(to) ? (
        <Link to={to} className="text-brand-700 hover:underline dark:text-brand-300">
          {body}
        </Link>
      ) : (
        body
      );
    }
    default:
      return <span>{String(v)}</span>;
  }
}

const NUMERIC = new Set(['number', 'decimal', 'percent', 'money']);

/** A report table: client-side sort + pagination, CSV / Excel export of the same rows from the server. */
export function ReportTableCard({ reportKey, table, query, canExport }: { reportKey: string; table: Table; query: Query; canExport: boolean }) {
  const [sort, setSort] = useState<SortState | null>(null);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(10);
  const [exporting, setExporting] = useState(false);
  const toast = useToast();
  const { can } = useAuth();
  const canOpen = (to: string) => {
    const m = linkModule(to);
    return m === null || can(m, 'view');
  };
  const fmt = useMemo(() => Object.fromEntries(table.columns.map((c) => [c.key, c.format])), [table.columns]);

  const rows = useMemo(() => {
    if (!sort) return table.rows;
    const numeric = NUMERIC.has(fmt[sort.key]);
    const dir = sort.dir === 'asc' ? 1 : -1;
    return [...table.rows].sort((a, b) => {
      const x = a[sort.key];
      const y = b[sort.key];
      if (numeric) return (toNumber(x) - toNumber(y)) * dir;
      return String(x ?? '').localeCompare(String(y ?? ''), 'en', { numeric: true }) * dir;
    });
  }, [table.rows, sort, fmt]);

  const pages = Math.max(1, Math.ceil(rows.length / perPage));
  const current = Math.min(page, pages);
  const shown = rows.slice((current - 1) * perPage, current * perPage);

  const columns: Column<Row>[] = table.columns.map((c, i) => ({
    key: c.key,
    header: c.label,
    sortable: true,
    align: NUMERIC.has(c.format) ? 'right' : 'left',
    className: i === 0 && ['text', 'link', 'person'].includes(c.format) ? 'min-w-[13rem]' : undefined,
    render: (r) => renderReportCell(c, r, canOpen),
  }));

  const exp = async (format: 'csv' | 'xlsx') => {
    setExporting(true);
    try {
      // fetched first, so a refusal (export scope, rate limit) is shown here instead of leaving the admin
      await downloadFile(`whatsapp/reports/${reportKey}/export`, { ...query, table: table.key, format }, { notify: false, filename: `whatsapp-${reportKey}-${table.key}.${format}` });
      toast.success(`${table.title} exported (${format === 'csv' ? 'CSV' : 'Excel'}).`);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setExporting(false);
    }
  };

  return (
    <Card>
      <CardHeader
        title={table.title}
        subtitle={`${formatNumber(table.rows.length)} row${table.rows.length === 1 ? '' : 's'}`}
        icon={Table2}
        actions={
          canExport && table.rows.length > 0 ? (
            <Dropdown
              label={`Export ${table.title}`}
              triggerClassName="btn btn-secondary btn-sm"
              trigger={
                <>
                  {exporting ? <Spinner className="h-4 w-4" /> : <Download className="h-4 w-4" aria-hidden />} Export
                </>
              }
              items={[
                { label: 'Excel (.xlsx)', icon: FileSpreadsheet, onClick: () => exp('xlsx') },
                { label: 'CSV', icon: FileText, onClick: () => exp('csv') },
              ]}
            />
          ) : undefined
        }
      />
      <DataTable<Row>
        columns={columns}
        rows={shown}
        rowKey={(r, i) => String(r.id ?? r.key ?? `${current}-${i}`)}
        sort={sort}
        onSort={(s) => {
          setSort(s);
          setPage(1);
        }}
        dense
        empty={<EmptyState icon={Table2} title="No data for this period" description="Try a longer date range or clear the filters." />}
      />
      {rows.length > 10 && (
        <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <Pagination page={current} pages={pages} total={rows.length} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} perPageOptions={[10, 25, 50, 100]} />
        </div>
      )}
    </Card>
  );
}
