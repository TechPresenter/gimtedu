import { useState } from 'react';
import clsx from 'clsx';
import { Ban, HandCoins, Mail, Printer, Undo2 } from 'lucide-react';
import { Button, PageHeader, PersonCell, StatusBadge, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, toNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { MODE_LABELS, ModeBadge, PaymentDrawer, ReasonDialog, RefundRequestModal, openPrint, useFeesRefresh } from './shared';

export default function PaymentsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useFeesRefresh();
  const [viewId, setViewId] = useState<number | null>(null);
  const [voidRow, setVoidRow] = useState<Row | null>(null);
  const [refundRow, setRefundRow] = useState<Row | null>(null);
  const [refundOpen, setRefundOpen] = useState(false);

  const email = async (r: Row) => {
    try {
      const res = await api.post(`fees/payments/${r.id}/email`);
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Payments"
        breadcrumbs={[{ label: 'Fees & Accounts', to: '/fees' }, { label: 'Payments' }]}
        description="Every receipt issued — reprint, email, refund or void."
        actions={
          <>
            {can('fees', 'create') && <Button variant="secondary" icon={Undo2} onClick={() => setRefundOpen(true)}>Request refund</Button>}
            {can('fees', 'create') && <Button variant="success" icon={HandCoins} to="/fees/collect">Collect fee</Button>}
          </>
        }
      />
      <CrudTable
        module="payments"
        urlState
        emptyTitle="No payments yet"
        emptyText="Receipts appear here as soon as fees are collected at the counter."
        onView={(r) => setViewId(Number(r.id))}
        renderers={{
          student_name: (r) => (
            <PersonCell name={String(r.student_name ?? '—')} src={r.photo} to={r.student_id ? `/students/${r.student_id}` : undefined}
              sub={<><span className="block">{r.student_uid}</span>{r.program_short && <span className="block">{r.program_short}</span>}</>} />
          ),
          receipt_no: (r) => (
            <button type="button" onClick={() => setViewId(Number(r.id))} className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200">
              {r.receipt_no}
            </button>
          ),
          total_amount: (r) => (
            <span className={clsx('whitespace-nowrap font-semibold tabular-nums', r.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-slate-900 dark:text-white')}>
              {formatMoney(r.total_amount)}
              {toNumber(r.fine_amount) > 0 && <span className="block text-[11px] font-medium text-slate-500 no-underline">late fee {formatMoney(r.fine_amount)}</span>}
            </span>
          ),
          mode: (r) => (
            <div className="leading-tight">
              <ModeBadge mode={r.mode} />
              {r.reference_no && <div className="mt-0.5 max-w-[120px] truncate text-[11px] text-slate-500" title={r.reference_no}>{r.reference_no}</div>}
            </div>
          ),
          status: (r) => <StatusBadge status={r.status} label={r.status === 'cancelled' ? 'Voided' : undefined} />,
          collected_by_name: (r) => <span className="whitespace-nowrap">{r.collected_by_name ?? (r.mode === 'online' ? 'Online' : '—')}</span>,
          // Collector shown under the date so the default columns cover receipt, student, amount, mode/reference, collector, date and status.
          payment_date: (r) => (
            <div className="whitespace-nowrap leading-tight">
              <span>{formatDate(r.payment_date)}</span>
              <span className="block text-[11px] text-slate-500 dark:text-slate-400">{r.collected_by_name ? `by ${r.collected_by_name}` : r.mode === 'online' ? 'Online' : ''}</span>
            </div>
          ),
        }}
        rowMenu={(r) => [
          { label: 'Print receipt', icon: Printer, onClick: () => openPrint('fee-receipt.php', { id: r.id }) },
          r.status === 'success' && can('fees', 'create') && { label: 'Email receipt', icon: Mail, onClick: () => email(r) },
          r.status === 'success' && can('fees', 'create') && { label: 'Request refund', icon: Undo2, onClick: () => setRefundRow(r) },
          r.status === 'success' && can('fees', 'approve') && { label: 'Void receipt', icon: Ban, danger: true, onClick: () => setVoidRow(r) },
        ]}
        header={({ summary }) =>
          summary ? (
            <div className="border-b border-slate-100 dark:border-slate-800">
              <div className="grid grid-cols-2 gap-px bg-slate-100 sm:grid-cols-4 dark:bg-slate-800">
                {[
                  ['Receipts', formatNumber(summary.receipts), ''],
                  ['Collected', formatMoneyShort(summary.collected), 'text-emerald-700 dark:text-emerald-400'],
                  ['Students', formatNumber(summary.students), ''],
                  ['Late fees · voided', `${formatMoneyShort(summary.fines)} · ${formatNumber(summary.voided)}`, ''],
                ].map(([l, v, c]) => (
                  <div key={l} className="bg-white px-4 py-3 dark:bg-slate-900">
                    <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{l}</p>
                    <p className={clsx('mt-0.5 text-sm font-semibold text-slate-900 dark:text-white', c)}>{v}</p>
                  </div>
                ))}
              </div>
              {Array.isArray(summary.modes) && summary.modes.length > 0 && (
                <div className="flex flex-wrap gap-2 bg-white px-4 py-2.5 dark:bg-slate-900">
                  {(summary.modes as { mode: string; n: number; amount: number }[]).map((m) => (
                    <span key={m.mode} className="inline-flex items-center gap-1.5 rounded-lg bg-slate-50 px-2 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                      <strong className="font-semibold text-slate-800 dark:text-slate-100">{MODE_LABELS[m.mode] ?? m.mode}</strong>
                      {formatMoneyShort(m.amount)} · {formatNumber(m.n)}
                    </span>
                  ))}
                </div>
              )}
            </div>
          ) : null
        }
      />
      <PaymentDrawer id={viewId} onClose={() => setViewId(null)} />
      <ReasonDialog
        open={!!voidRow}
        onClose={() => setVoidRow(null)}
        danger
        title={`Void receipt ${voidRow?.receipt_no ?? ''}?`}
        description={voidRow ? `${formatMoney(voidRow.total_amount)} from ${voidRow.student_name}. The payment is cancelled and the invoice balances are restored.` : undefined}
        label="Reason for voiding"
        placeholder='e.g. Cheque returned unpaid — "Funds insufficient"'
        confirmText="Void receipt"
        onSubmit={async (reason) => {
          const res = await api.post(`fees/payments/${voidRow?.id}/void`, { reason });
          toast.success(res.message);
          await refresh();
        }}
      />
      <RefundRequestModal
        open={!!refundRow || refundOpen}
        onClose={() => { setRefundRow(null); setRefundOpen(false); }}
        payment={refundRow ? { id: Number(refundRow.id), label: `${refundRow.receipt_no} — ${refundRow.student_name ?? ''}`, refundable: Math.max(0, toNumber(refundRow.total_amount) - toNumber(refundRow.refund_requested)) } : null}
        onDone={refresh}
      />
    </>
  );
}
