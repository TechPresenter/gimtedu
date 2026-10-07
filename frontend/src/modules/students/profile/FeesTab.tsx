import { AlertTriangle, BadgePercent, Banknote, FileText, HandCoins, Printer, Receipt, Wallet } from 'lucide-react';
import { Button, ProgressBar, Reveal, StatusBadge, Stagger, type Column } from '@/components/ui';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatDate, formatMoney, labelize } from '@/lib/format';
import type { FeeStats } from '../types';
import { MiniStat, TabEmpty, TabError, TabLoading, TableCard, type TabProps } from './shared';

interface Invoice extends Record<string, unknown> {
  id: number; invoice_no: string; title: string; semester_no: number | null; session_name: string | null; gross_amount: string; discount_amount: string; scholarship_amount: string;
  fine_amount: string; net_amount: string; paid_amount: string; balance_amount: string; due_date: string | null; status: string;
}
interface Payment extends Record<string, unknown> {
  id: number; receipt_no: string; amount: string; fine_amount: string; payment_date: string; mode: string; reference_no: string | null; purpose: string; status: string; invoice_no: string | null; collected_by_name: string | null;
}
interface FeesData { totals: FeeStats; invoices: Invoice[]; payments: Payment[]; print_views: Record<string, boolean> }

export default function FeesTab({ studentId }: TabProps) {
  const { can } = useAuth();
  const q = useApi<FeesData>(['students', studentId, 'fees'], `students/${studentId}/fees`);
  if (q.isLoading) return <TabLoading />;
  if (q.error) return <TabError error={q.error} onRetry={() => q.refetch()} />;
  const { totals: t, invoices, payments, print_views: pv } = q.data!;
  const collect = can('fees', 'create') ? <Button size="sm" icon={HandCoins} to={`/fees/collect?student_id=${studentId}`}>Collect fee</Button> : undefined;
  if (!invoices.length && !payments.length) {
    return <TabEmpty icon={Wallet} title="No fee records yet" description="Invoices appear once a fee structure is assigned to this student, and payments as they are collected." action={collect} />;
  }
  const paidPct = t.net ? (t.paid * 100) / t.net : 0;
  const invoiceCols: Column<Invoice>[] = [
    { key: 'invoice_no', header: 'Invoice', render: (r) => <div className="leading-tight"><p className="font-mono text-xs font-semibold text-slate-800 dark:text-slate-100">{r.invoice_no}</p><p className="max-w-[16rem] truncate text-xs text-slate-500">{r.title}</p></div> },
    { key: 'session_name', header: 'Session', render: (r) => <span className="whitespace-nowrap">{r.session_name ?? '—'}{r.semester_no ? ` · Sem ${r.semester_no}` : ''}</span> },
    { key: 'net_amount', header: 'Net', align: 'right', render: (r) => <span className="whitespace-nowrap tabular-nums">{formatMoney(r.net_amount)}</span> },
    { key: 'paid_amount', header: 'Paid', align: 'right', render: (r) => <span className="whitespace-nowrap tabular-nums text-emerald-700 dark:text-emerald-300">{formatMoney(r.paid_amount)}</span> },
    { key: 'balance_amount', header: 'Balance', align: 'right', render: (r) => <span className="whitespace-nowrap font-semibold tabular-nums">{formatMoney(r.balance_amount)}</span> },
    { key: 'due_date', header: 'Due', render: (r) => <span className="whitespace-nowrap">{r.due_date ? formatDate(r.due_date) : '—'}</span> },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
  ];
  const paymentCols: Column<Payment>[] = [
    { key: 'receipt_no', header: 'Receipt', render: (r) => <span className="font-mono text-xs font-semibold">{r.receipt_no}</span> },
    { key: 'payment_date', header: 'Date', render: (r) => <span className="whitespace-nowrap">{formatDate(r.payment_date)}</span> },
    { key: 'amount', header: 'Amount', align: 'right', render: (r) => <span className="whitespace-nowrap font-semibold tabular-nums">{formatMoney(Number(r.amount) + Number(r.fine_amount))}</span> },
    { key: 'mode', header: 'Mode', render: (r) => <span className="whitespace-nowrap">{labelize(r.mode)}{r.reference_no ? <span className="block text-xs text-slate-500">{r.reference_no}</span> : null}</span> },
    { key: 'invoice_no', header: 'Against', render: (r) => <span className="text-xs">{r.invoice_no ?? labelize(r.purpose)}</span> },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} /> },
    ...(pv.receipt
      ? [{ key: 'print', header: '', align: 'right' as const, render: (r: Payment) => <a href={printUrl('receipt.php', { id: r.id })} target="_blank" rel="noopener noreferrer" className="btn-icon !h-8 !w-8" aria-label={`Print receipt ${r.receipt_no}`}><Printer className="h-4 w-4" /></a> }]
      : []),
  ];
  return (
    <div className="space-y-5">
      <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-4" itemClassName="h-full" step={50}>
        {[
          <MiniStat key="n" label="Total payable" value={formatMoney(t.net)} icon={FileText} tone="navy" sub={`${t.invoices} invoice${t.invoices === 1 ? '' : 's'}${t.fine ? ` · ${formatMoney(t.fine)} fines` : ''}`} />,
          <MiniStat key="p" label="Paid" value={formatMoney(t.paid)} icon={Banknote} tone="green" sub={<ProgressBar value={paidPct} tone="green" className="!h-1.5" label="Paid share" />} />,
          <MiniStat key="b" label="Balance" value={formatMoney(t.balance)} icon={Wallet} tone={t.balance > 0 ? 'amber' : 'green'} sub={t.next_due ? `Next due ${formatDate(t.next_due)}` : 'Nothing due'} />,
          <MiniStat key="o" label="Overdue" value={formatMoney(t.overdue)} icon={t.overdue > 0 ? AlertTriangle : BadgePercent} tone={t.overdue > 0 ? 'red' : 'slate'} sub={`Concessions ${formatMoney(t.discount + t.scholarship)}`} />,
        ]}
      </Stagger>
      <TableCard<Invoice> title="Invoices" subtitle="Fee assigned per semester / service" icon={FileText} columns={invoiceCols} rows={invoices} empty="No invoices raised" emptyIcon={FileText}
        actions={<>{can('fees', 'view') && <Button size="sm" variant="secondary" to="/fees/invoices">All invoices</Button>}{collect}</>} />
      <TableCard<Payment> title="Payment history" subtitle={`${payments.length} payment${payments.length === 1 ? '' : 's'}`} icon={Receipt} columns={paymentCols} rows={payments} empty="No payments received yet" emptyIcon={Receipt} delay={60} />
      {!pv.receipt && payments.length > 0 && (
        <Reveal><p className="text-xs text-slate-500">Receipts can be reprinted from Fees › Payments.</p></Reveal>
      )}
    </div>
  );
}
