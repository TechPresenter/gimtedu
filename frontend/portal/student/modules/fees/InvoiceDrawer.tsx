import { Printer } from 'lucide-react';
import { Drawer } from '@/components/ui';
import { formatDate } from '@/lib/format';
import { ErrorState } from '../../../components/Ui';
import { ListSkeleton, MiniStat, PrintButton, StatusBadge, money, useStudent } from '../../shared/ui';
import type { InvoiceDetail } from '../../shared/types';

export function InvoiceDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data, isLoading, error, refetch } = useStudent<InvoiceDetail>(['fees', 'invoice', id], `fees/invoices/${id}`, undefined, { enabled: id !== null });
  const inv = id && data?.id === id ? data : undefined;
  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      title={inv ? inv.title : 'Invoice'}
      description={inv ? `${inv.invoice_no}${inv.session_name ? ` · Session ${inv.session_name}` : ''}` : undefined}
      footer={inv ? <PrintButton path={`invoice/${inv.id}`} variant="primary" icon={Printer}>Print invoice</PrintButton> : undefined}
    >
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !inv ? (
        <ListSkeleton rows={6} h="h-12" />
      ) : (
        <div className="space-y-5">
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status={inv.status} />
            {inv.due_date && <span className="text-xs text-slate-500">Due {formatDate(inv.due_date)}</span>}
            {inv.structure_name && <span className="text-xs text-slate-400">· {inv.structure_name}</span>}
          </div>
          <div className="grid grid-cols-3 gap-2.5">
            <MiniStat label="Net payable" value={money(inv.net_amount)} />
            <MiniStat label="Paid" value={money(inv.paid_amount)} tone="green" />
            <MiniStat label="Balance" value={money(inv.balance_amount)} tone={inv.overdue_amount > 0 ? 'red' : inv.balance_amount > 0 ? 'amber' : 'green'} sub={inv.overdue_amount > 0 ? `${money(inv.overdue_amount)} overdue` : undefined} />
          </div>
          {inv.late_fee_due > 0 && (
            <p className="rounded-xl bg-red-50 px-3 py-2 text-[13px] text-red-700 dark:bg-red-500/10 dark:text-red-300">
              A late fee of about <strong>{money(inv.late_fee_due)}</strong> has accrued ({money(inv.late_fee_rate.per_day)} per day). It is added when you pay at the accounts office.
            </p>
          )}

          <div>
            <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Fee heads</p>
            <ul className="divide-y divide-slate-100 overflow-hidden rounded-2xl border border-slate-200/80 dark:divide-slate-800 dark:border-slate-800">
              {inv.items.map((it) => (
                <li key={it.code + it.head} className="flex items-center justify-between px-3.5 py-2.5 text-sm">
                  <span className="text-slate-700 dark:text-slate-200">{it.head}</span>
                  <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{money(it.amount)}</span>
                </li>
              ))}
              <li className="flex items-center justify-between bg-slate-50 px-3.5 py-2.5 text-sm dark:bg-slate-800/40">
                <span className="font-semibold">Gross</span>
                <span className="font-semibold tabular-nums">{money(inv.gross_amount)}</span>
              </li>
              {inv.scholarship_amount > 0 && (
                <li className="flex items-center justify-between px-3.5 py-2 text-sm text-emerald-700 dark:text-emerald-300">
                  <span>Less: scholarship</span>
                  <span className="tabular-nums">− {money(inv.scholarship_amount)}</span>
                </li>
              )}
              {inv.discount_amount > 0 && (
                <li className="flex items-center justify-between px-3.5 py-2 text-sm text-emerald-700 dark:text-emerald-300">
                  <span>Less: discount / waiver</span>
                  <span className="tabular-nums">− {money(inv.discount_amount)}</span>
                </li>
              )}
              {inv.fine_amount > 0 && (
                <li className="flex items-center justify-between px-3.5 py-2 text-sm text-red-600 dark:text-red-300">
                  <span>Add: late fee charged</span>
                  <span className="tabular-nums">{money(inv.fine_amount)}</span>
                </li>
              )}
              <li className="flex items-center justify-between bg-slate-50 px-3.5 py-2.5 text-sm font-bold dark:bg-slate-800/40">
                <span>Net payable</span>
                <span className="tabular-nums">{money(inv.net_amount)}</span>
              </li>
            </ul>
          </div>

          {inv.installments.length > 0 && (
            <div>
              <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Installments</p>
              <ul className="space-y-2">
                {inv.installments.map((i) => (
                  <li key={i.no} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200/80 px-3.5 py-2.5 text-sm dark:border-slate-800">
                    <div>
                      <p className="font-semibold text-slate-900 dark:text-white">Installment {i.no}</p>
                      <p className="text-xs text-slate-500">Due {formatDate(i.due_date)}</p>
                    </div>
                    <div className="text-right">
                      <p className="font-semibold tabular-nums">{money(i.amount)}</p>
                      <StatusBadge status={i.paid >= i.amount ? 'paid' : i.status} />
                    </div>
                  </li>
                ))}
              </ul>
            </div>
          )}

          {inv.concessions.length > 0 && (
            <div>
              <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Concessions</p>
              <ul className="space-y-1.5 text-sm">
                {inv.concessions.map((c, i) => (
                  <li key={i} className="flex justify-between gap-3">
                    <span className="text-slate-600 dark:text-slate-300">
                      {c.name}
                      {c.status !== 'approved' ? ` (${c.status})` : ''}
                    </span>
                    <span className="font-semibold tabular-nums text-emerald-700 dark:text-emerald-300">{money(c.amount)}</span>
                  </li>
                ))}
              </ul>
            </div>
          )}

          <div>
            <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Payments against this invoice</p>
            {inv.payments.length === 0 ? (
              <p className="text-sm text-slate-500">No payments yet.</p>
            ) : (
              <ul className="space-y-2">
                {inv.payments.map((p) => (
                  <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-slate-50 px-3.5 py-2.5 text-sm dark:bg-slate-800/40">
                    <div>
                      <p className="font-semibold text-slate-900 dark:text-white">{p.receipt_no}</p>
                      <p className="text-xs text-slate-500">
                        {formatDate(p.date)} · {p.mode}
                      </p>
                    </div>
                    <div className="flex items-center gap-2">
                      <span className="font-semibold tabular-nums">{money(p.amount)}</span>
                      {p.status === 'cancelled' ? <StatusBadge status="cancelled" label="Void" /> : <PrintButton path={`receipt/${p.id}`} size="xs">Receipt</PrintButton>}
                    </div>
                  </li>
                ))}
              </ul>
            )}
          </div>
          {inv.cancelled_reason && <p className="text-sm text-red-600">Cancelled: {inv.cancelled_reason}</p>}
        </div>
      )}
    </Drawer>
  );
}
