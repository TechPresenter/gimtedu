import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { AlarmClock, BellRing, BookOpenCheck, ChartColumn, Download, FileSpreadsheet, FileText, HandCoins, Layers, Printer, ReceiptText, RotateCcw, Wallet } from 'lucide-react';
import {
  Alert, Button, Card, CardHeader, DataTable, Dropdown, EmptyState, IconButton, Input, PageHeader, Pagination, Reveal, SearchInput, Select, Skeleton, StatusBadge, Tabs, useToast,
  type Column,
} from '@/components/ui';
import { BarChart, LineChart } from '@/components/charts';
import { api, apiUrl, ApiError, downloadFile, type Query } from '@/lib/api';
import { useApi, useLookup } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatDate, formatDateTime, formatMoney, formatMoneyShort, formatNumber, labelize, timeAgo, toNumber } from '@/lib/format';
import type { Option, Row } from '@/lib/types';
import { INVOICE_STATUS, InvoiceDrawer, MODE_LABELS, PaymentDrawer, useFeesRefresh } from './shared';
import type { ReportPayload } from './types';

type TabKey = 'collection' | 'outstanding' | 'defaulters' | 'headwise' | 'daybook';
const TABS: { key: TabKey; label: string; icon: typeof ReceiptText; hint: string }[] = [
  { key: 'collection', label: 'Collection', icon: ReceiptText, hint: 'Receipts by date range, mode, program and collector' },
  { key: 'outstanding', label: 'Outstanding', icon: Wallet, hint: 'Invoices with a balance this session' },
  { key: 'defaulters', label: 'Defaulters', icon: AlarmClock, hint: 'Students with overdue installments, oldest and largest first' },
  { key: 'headwise', label: 'Head-wise', icon: Layers, hint: 'Billed, concession and collection per fee head' },
  { key: 'daybook', label: 'Daily cash book', icon: BookOpenCheck, hint: 'Money in (receipts) and out (refunds, expenses) per day' },
];
const SEMESTERS: Option[] = Array.from({ length: 8 }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` }));

export default function FeeReportsPage() {
  const [params, setParams] = useSearchParams();
  const tab = (TABS.find((t) => t.key === params.get('tab'))?.key ?? 'collection') as TabKey;
  const setTab = (t: string) => setParams(new URLSearchParams({ tab: t }), { replace: true });
  const meta = TABS.find((t) => t.key === tab)!;
  return (
    <>
      <PageHeader
        title="Payment Reports"
        breadcrumbs={[{ label: 'Fees & Accounts', to: '/fees' }, { label: 'Reports' }]}
        description="Collection, outstanding, defaulter, head-wise and cash book reports — filter, export to CSV / Excel or print."
      />
      <Tabs className="mb-5" value={tab} onChange={setTab} tabs={TABS.map(({ key, label, icon }) => ({ key, label, icon }))} />
      <Report key={tab} tab={tab} hint={meta.hint} />
    </>
  );
}

function Report({ tab, hint }: { tab: TabKey; hint: string }) {
  const { id: sessionId } = useAcademicSession();
  const { can } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const refresh = useFeesRefresh();
  const { data: programs = [] } = useLookup('programs');
  const { data: collectors = [] } = useApi<Option[]>(['fees-collectors'], 'fees/collectors', undefined, { enabled: tab === 'collection' });
  const [f, setF] = useState({ from: '', to: '', mode: '', program_id: '', semester: '', collector: '', min_days: '' });
  const [q, setQ] = useState('');
  const debouncedQ = useDebounce(q, 350);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [invoiceId, setInvoiceId] = useState<number | null>(null);
  const [paymentId, setPaymentId] = useState<number | null>(null);
  const [reminding, setReminding] = useState<string | null>(null);
  useEffect(() => setPage(1), [f, debouncedQ, perPage]);

  const query: Query = { session_id: sessionId ?? undefined, ...f, q: debouncedQ || undefined };
  Object.keys(query).forEach((k) => (query[k] === '' ? delete query[k] : null));
  const { data, isLoading, isFetching, error, refetch } = useApi<ReportPayload>(['fees-report', tab, query, page, perPage], `fees/reports/${tab}`, { ...query, page, per_page: perPage }, { placeholderData: (prev) => prev });
  const set = (k: keyof typeof f, v: string) => setF((s) => ({ ...s, [k]: v }));
  const show = (k: string) => ({
    dates: ['collection', 'daybook'].includes(tab), mode: tab === 'collection', program: tab !== 'daybook', semester: ['outstanding', 'defaulters', 'headwise'].includes(tab),
    collector: tab === 'collection', days: tab === 'defaulters', search: ['collection', 'outstanding', 'defaulters'].includes(tab),
  } as Record<string, boolean>)[k];
  const active = Object.values(f).some(Boolean) || !!q;

  const exportAs = (format: 'csv' | 'xlsx' | 'print') => {
    const qs = { ...query, format };
    if (format === 'print') window.open(apiUrl(`fees/reports/${tab}`, qs), '_blank', 'noopener');
    else downloadFile(`fees/reports/${tab}`, qs);
  };
  const remind = async (ids: string, key: string) => {
    setReminding(key);
    try {
      const res = await api.post('fees/reminders', { ids: ids.split(',').map(Number) });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setReminding(null);
    }
  };

  const columns: Column<Row>[] = useMemo(() => {
    // Student ID / program / semester are folded into the student cell on screen (exports keep separate columns).
    const folded = new Set(['student_uid', 'program', 'semester', 'semester_no', 'reference_no', ...(tab === 'defaulters' ? ['invoices'] : [])]);
    const hasStudent = (data?.columns ?? []).some(([key]) => key === 'student_name');
    const cols: Column<Row>[] = (data?.columns ?? []).filter(([key]) => !(hasStudent && folded.has(key)) && !(tab === 'daybook' && key === 'receipts') && !(tab === 'headwise' && ['code', 'type'].includes(key))).map(([key, label, fmt]) => ({
      key,
      header: label,
      align: ['money', 'number', 'percent'].includes(fmt) ? 'right' : undefined,
      render: (r: Row) => cell(r, key, fmt, tab, { openInvoice: setInvoiceId, openPayment: setPaymentId }),
    }));
    if (tab === 'defaulters') {
      cols.push({
        key: '_actions',
        header: '',
        align: 'right',
        render: (r: Row) => (
          <div className="flex justify-end gap-1">
            {can('fees', 'edit') && <IconButton size="sm" icon={BellRing} label="Send reminder" tone="primary" disabled={reminding === String(r.student_id)} onClick={() => remind(String(r.invoice_ids), String(r.student_id))} />}
            {can('fees', 'create') && <IconButton size="sm" icon={HandCoins} label="Collect fee" tone="primary" onClick={() => navigate(`/fees/collect?student=${r.student_id}`)} />}
          </div>
        ),
      });
    }
    return cols;
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [data?.columns, tab, reminding]);

  return (
    <>
      <Card className="mb-5 p-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:flex-wrap lg:items-center">
          {show('search') && <SearchInput size="sm" value={q} onChange={setQ} placeholder={tab === 'collection' ? 'Receipt, student, reference…' : 'Student name, ID or invoice…'} className="w-full lg:w-64" />}
          {show('dates') && (
            <div className="flex items-center gap-1.5">
              <Input inputSize="sm" type="date" aria-label="From date" value={f.from || data?.filters.from || ''} onChange={(e) => set('from', e.target.value)} className="!w-36" />
              <span className="text-xs text-slate-400">to</span>
              <Input inputSize="sm" type="date" aria-label="To date" value={f.to || data?.filters.to || ''} onChange={(e) => set('to', e.target.value)} className="!w-36" />
            </div>
          )}
          <div className="flex flex-wrap gap-2">
            {show('mode') && <Select inputSize="sm" aria-label="Payment mode" className="w-full sm:w-40" value={f.mode} onChange={(e) => set('mode', e.target.value)} options={Object.entries(MODE_LABELS).map(([value, label]) => ({ value, label }))} placeholder="Mode: All" />}
            {show('program') && <Select inputSize="sm" aria-label="Program" className="w-full sm:w-56" value={f.program_id} onChange={(e) => set('program_id', e.target.value)} options={programs} placeholder="Program: All" />}
            {show('semester') && <Select inputSize="sm" aria-label="Semester" className="w-full sm:w-40" value={f.semester} onChange={(e) => set('semester', e.target.value)} options={SEMESTERS} placeholder="Semester: All" />}
            {show('collector') && <Select inputSize="sm" aria-label="Collected by" className="w-full sm:w-44" value={f.collector} onChange={(e) => set('collector', e.target.value)} options={collectors} placeholder="Collector: All" />}
            {show('days') && (
              <Select inputSize="sm" aria-label="Overdue for at least" className="w-full sm:w-44" value={f.min_days} onChange={(e) => set('min_days', e.target.value)}
                options={[{ value: '15', label: '15+ days overdue' }, { value: '30', label: '30+ days overdue' }, { value: '60', label: '60+ days overdue' }]} placeholder="Any overdue" />
            )}
            {active && <Button size="sm" variant="ghost" icon={RotateCcw} onClick={() => { setF({ from: '', to: '', mode: '', program_id: '', semester: '', collector: '', min_days: '' }); setQ(''); }}>Reset</Button>}
          </div>
          <div className="flex gap-2 lg:ml-auto">
            {can('fees', 'export') && (
              <Dropdown
                label="Export report"
                trigger={<><Download className="h-4 w-4" />Export</>}
                items={[
                  { label: 'Export as CSV', icon: FileText, onClick: () => exportAs('csv') },
                  { label: 'Export as Excel', icon: FileSpreadsheet, onClick: () => exportAs('xlsx') },
                  { label: 'Print / Save as PDF', icon: Printer, onClick: () => exportAs('print') },
                ]}
              />
            )}
          </div>
        </div>
        <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
          {hint}
          {data ? ` · ${['collection', 'daybook'].includes(tab) ? `${formatDate(data.filters.from)} – ${formatDate(data.filters.to)}` : `Session ${data.filters.session.name}`}` : ''}
        </p>
      </Card>

      {error ? (
        <Alert variant="error" title="Unable to load the report" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
      ) : (
        <>
          <SummaryTiles tab={tab} data={data} loading={isLoading} />
          <Reveal className="mb-5">
            <Card>
              <CardHeader title={chartTitle(tab)} icon={ChartColumn} />
              <div className="card-body">{isLoading || !data ? <Skeleton className="h-64 w-full" /> : <ReportChart tab={tab} data={data} />}</div>
            </Card>
          </Reveal>
          <Card className="overflow-hidden">
            <DataTable<Row>
              columns={columns}
              rows={data?.rows ?? []}
              rowKey={(r, i) => String(r.id ?? r.student_id ?? r.date ?? i)}
              loading={isLoading || isFetching}
              caption={`${labelize(tab)} report`}
              empty={<EmptyState icon={ChartColumn} title="No records for these filters" description="Widen the date range or clear the filters." action={active ? <Button variant="secondary" size="sm" onClick={() => { setF({ from: '', to: '', mode: '', program_id: '', semester: '', collector: '', min_days: '' }); setQ(''); }}>Clear filters</Button> : undefined} />}
            />
            {data && data.pagination.total > 0 && tab !== 'headwise' && (
              <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={data.pagination.per_page} onPage={setPage} onPerPage={setPerPage} />
            )}
          </Card>
        </>
      )}
      <InvoiceDrawer id={invoiceId} onClose={() => setInvoiceId(null)} />
      <PaymentDrawer id={paymentId} onClose={() => setPaymentId(null)} />
    </>
  );
}

function chartTitle(tab: TabKey) {
  return { collection: 'Daily collection', outstanding: 'Outstanding by program', defaulters: 'Overdue ageing', headwise: 'Collected vs outstanding by fee head', daybook: 'Money in vs money out (last 31 days of the range)' }[tab];
}

function cell(r: Row, key: string, fmt: string, tab: TabKey, actions: { openInvoice: (id: number) => void; openPayment: (id: number) => void }): ReactNode {
  const v = r[key];
  if (key === 'receipt_no' && tab === 'collection') {
    return <button type="button" className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200" onClick={() => actions.openPayment(Number(r.id))}>{String(v)}</button>;
  }
  if (key === 'invoice_no' && tab === 'outstanding') {
    return <button type="button" className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200" onClick={() => actions.openInvoice(Number(r.id))}>{String(v)}</button>;
  }
  if (v === null || v === undefined || v === '') return <span className="text-slate-400">—</span>;
  switch (fmt) {
    case 'money': {
      const n = toNumber(v);
      const red = (key === 'overdue' || key === 'overdue_amount') && n > 0;
      return <span className={clsx('whitespace-nowrap tabular-nums', red ? 'font-semibold text-red-600 dark:text-red-400' : key === 'net' || key === 'total' || key === 'balance' || key === 'balance_amount' ? 'font-semibold text-slate-900 dark:text-white' : '', n < 0 && 'text-red-600 dark:text-red-400')}>{n < 0 ? `−${formatMoney(-n)}` : formatMoney(n)}</span>;
    }
    case 'number':
      return <span className="tabular-nums">{key === 'days_overdue' ? <span className={clsx('badge', toNumber(v) > 60 ? 'badge-red' : toNumber(v) > 30 ? 'badge-amber' : 'badge-slate')}>{formatNumber(v)} d</span> : formatNumber(v)}</span>;
    case 'percent':
      return <span className="tabular-nums">{toNumber(v).toFixed(1)}%</span>;
    case 'date':
      return <span className="whitespace-nowrap">{formatDate(v)}</span>;
    case 'datetime':
      return <span className="whitespace-nowrap" title={formatDateTime(v)}>{key === 'last_reminder_at' ? timeAgo(v) : formatDateTime(v)}</span>;
    case 'badge':
      return <StatusBadge status={String(v)} label={INVOICE_STATUS[String(v)]} />;
    case 'code':
      return <code className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{String(v)}</code>;
    default:
      if (key === 'mode' && tab === 'collection') {
        return (
          <div className="leading-tight">
            <span className="whitespace-nowrap">{String(v)}</span>
            {r.reference_no ? <span className="block max-w-[140px] truncate text-xs text-slate-500" title={String(r.reference_no)}>{String(r.reference_no)}</span> : null}
          </div>
        );
      }
      if (key === 'head' && tab === 'headwise') {
        return (
          <div className="leading-tight">
            <span className="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{String(v)}</span>
            <span className="block whitespace-nowrap text-xs text-slate-500">{[r.code, r.type].filter(Boolean).join(' · ')}</span>
          </div>
        );
      }
      if (key === 'student_name') {
        const sem = r.semester ?? r.semester_no;
        const sub = [r.student_uid, r.program, sem ? `Sem ${sem}` : null].filter(Boolean).join(' · ');
        return (
          <div className="leading-tight">
            <span className="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{String(v)}</span>
            {sub && <span className="block whitespace-nowrap text-xs text-slate-500">{sub}</span>}
          </div>
        );
      }
      return <span className={clsx(key !== 'title' && 'whitespace-nowrap', key === 'head' && 'font-semibold text-slate-900 dark:text-white')}>{String(v)}</span>;
  }
}

function SummaryTiles({ tab, data, loading }: { tab: TabKey; data?: ReportPayload; loading: boolean }) {
  const s = (data?.summary ?? {}) as Record<string, number>;
  const tiles: [string, string, string?][] = {
    collection: [['Total collected', formatMoney(s.total), 'green'], ['Receipts', formatNumber(s.receipts)], ['Students', formatNumber(s.students)], ['Late fee collected', formatMoney(s.fine)], ['Average receipt', formatMoney(s.average)]],
    outstanding: [['Outstanding', formatMoney(s.balance), 'amber'], ['Overdue', formatMoney(s.overdue), 'red'], ['Invoices', formatNumber(s.invoices)], ['Students', formatNumber(s.students)], ['Collected so far', formatMoney(s.paid), 'green']],
    defaulters: [['Overdue amount', formatMoney(s.overdue), 'red'], ['Defaulters', formatNumber(s.students)], ['Their total balance', formatMoney(s.balance), 'amber'], ['Average days overdue', `${formatNumber(s.avg_days)} days`]],
    headwise: [['Billed', formatMoney(s.billed)], ['Concessions', formatMoney(s.concession)], ['Collected', formatMoney(s.collected), 'green'], ['Outstanding', formatMoney(s.outstanding), 'amber'], ['Collection rate', `${toNumber(s.rate).toFixed(1)}%`]],
    daybook: [['Money in', formatMoney(s.total_in), 'green'], ['Cash in', formatMoney(s.cash_in)], ['Bank / digital in', formatMoney(s.bank_in)], ['Money out', formatMoney(s.total_out), 'red'], ['Net', toNumber(s.net) < 0 ? `−${formatMoney(-toNumber(s.net))}` : formatMoney(s.net), toNumber(s.net) < 0 ? 'red' : 'navy']],
  }[tab] as [string, string, string?][];
  const tone: Record<string, string> = { green: 'text-emerald-700 dark:text-emerald-400', amber: 'text-amber-700 dark:text-amber-400', red: 'text-red-600 dark:text-red-400', navy: 'text-brand-800 dark:text-brand-200' };
  return (
    <div className={clsx('mb-5 grid grid-cols-2 gap-3', tiles.length === 5 ? 'md:grid-cols-5' : 'md:grid-cols-4')}>
      {tiles.map(([label, value, t], i) => (
        <Reveal key={label} delay={i * 40} className={clsx(tiles.length === 5 && i === 4 && 'col-span-2 md:col-span-1')}>
          <div className="card h-full px-4 py-3">
            <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{label}</p>
            {loading || !data ? <Skeleton className="mt-1.5 h-6 w-24" /> : <p className={clsx('mt-0.5 font-display text-lg font-bold tabular-nums text-slate-900 dark:text-white', t && tone[t])}>{value}</p>}
          </div>
        </Reveal>
      ))}
    </div>
  );
}

function ReportChart({ tab, data }: { tab: TabKey; data: ReportPayload }) {
  const c = data.chart;
  if (!c.labels.length) return <EmptyState className="!py-10" icon={ChartColumn} title="Nothing to chart" description="No data for the selected filters." />;
  switch (tab) {
    case 'collection': {
      const modes = ((data.summary.by_mode as { label: string; amount: number; n: number }[]) ?? []);
      return (
        <>
          <LineChart money height={240} labels={c.labels} series={[{ label: 'Collected', data: c.values, color: '#22943F', fill: true }]} />
          {modes.length > 0 && (
            <div className="mt-4 flex flex-wrap gap-2">
              {modes.map((m) => <span key={m.label} className="rounded-lg bg-slate-50 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300"><strong className="text-slate-900 dark:text-white">{m.label}</strong> {formatMoneyShort(m.amount)} · {formatNumber(m.n)}</span>)}
            </div>
          )}
        </>
      );
    }
    case 'outstanding':
      return <BarChart money height={260} labels={c.labels} series={[{ label: 'Outstanding', data: c.values, color: '#F59E0B' }, { label: 'Overdue', data: (c.overdue as number[]) ?? [], color: '#EF4444' }]} />;
    case 'defaulters':
      return <BarChart money height={240} labels={c.labels} series={[{ label: 'Overdue amount', data: c.values, color: '#EF4444' }]} />;
    case 'headwise':
      return <BarChart money stacked height={260} labels={c.labels} series={[{ label: 'Collected', data: c.values, color: '#22943F' }, { label: 'Outstanding', data: (c.outstanding as number[]) ?? [], color: '#F59E0B' }]} />;
    default:
      return <BarChart money height={260} labels={c.labels} series={[{ label: 'Money in', data: c.values, color: '#22943F' }, { label: 'Money out', data: (c.out as number[]) ?? [], color: '#EF4444' }]} />;
  }
}
