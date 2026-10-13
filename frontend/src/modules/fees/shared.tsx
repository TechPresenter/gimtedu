import { useEffect, useState, type ReactNode } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlarmClock, BadgePercent, Ban, BellRing, CalendarClock, FileText, HandCoins, Mail, Printer, ReceiptText, ScrollText, Undo2, type LucideIcon,
} from 'lucide-react';
import {
  Alert, Badge, Button, Combobox, DescriptionList, Drawer, Field, Input, Modal, PersonCell, Select, Skeleton, StatusBadge, Textarea, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatMoney, labelize, toNumber } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';
import type { FeesMeta, InvoiceDetail, PaymentDetail } from './types';

/* ------------------------------------------------------------------ constants & small helpers */

export const MODE_LABELS: Record<string, string> = {
  cash: 'Cash', bank_transfer: 'Bank Transfer', cheque: 'Cheque', dd: 'Demand Draft', upi: 'UPI', card: 'Card', online: 'Online Gateway',
};
export const MODE_COLORS: Record<string, BadgeColor> = {
  cash: 'green', upi: 'purple', bank_transfer: 'blue', cheque: 'amber', dd: 'amber', card: 'cyan', online: 'navy',
};
export const MODE_HEX: Record<string, string> = {
  upi: '#8B5CF6', bank_transfer: '#1D4ED8', cash: '#22943F', online: '#64748B', card: '#06B6D4', cheque: '#F59E0B', dd: '#F97316',
};
export const INVOICE_STATUS: Record<string, string> = {
  pending: 'Pending', partial: 'Partially paid', overdue: 'Overdue', paid: 'Paid', waived: 'Waived', cancelled: 'Cancelled',
};
export const FEES_KEYS = ['crud', 'fees-dashboard', 'fees-invoice', 'fees-payment', 'fees-profile', 'fees-report', 'fees-structure', 'combobox'];

export function ModeBadge({ mode }: { mode: string | null | undefined }) {
  if (!mode) return <span className="text-slate-400">—</span>;
  return <Badge color={MODE_COLORS[mode] ?? 'slate'}>{MODE_LABELS[mode] ?? labelize(mode)}</Badge>;
}

export function Money({ value, decimals = 0, className }: { value: unknown; decimals?: number; className?: string }) {
  return <span className={clsx('whitespace-nowrap tabular-nums', className)}>{formatMoney(value, decimals)}</span>;
}

/** Fees meta (payment modes, gateway, late fee rule) — cached for the session. */
export function useFeesMeta() {
  return useApi<FeesMeta>(['fees-meta'], 'fees/meta', undefined, { staleTime: 5 * 60 * 1000 });
}

export function openPrint(page: string, params: Record<string, string | number | undefined>) {
  window.open(printUrl(page, params), '_blank', 'noopener');
}

/** Refreshes every fees query after a write. */
export function useFeesRefresh() {
  const invalidate = useInvalidate();
  return () => invalidate(...FEES_KEYS);
}

/** Small label/value tile used in drawers and the collection counter. */
export function AmountTile({ label, value, tone = 'slate', sub }: { label: string; value: ReactNode; tone?: 'slate' | 'green' | 'red' | 'amber' | 'blue'; sub?: ReactNode }) {
  const tones = {
    slate: 'bg-slate-50 dark:bg-slate-800/60',
    green: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200',
    red: 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-200',
    amber: 'bg-amber-50 text-amber-800 dark:bg-amber-500/10 dark:text-amber-200',
    blue: 'bg-brand-50 text-brand-900 dark:bg-brand-500/10 dark:text-brand-100',
  };
  return (
    <div className={clsx('rounded-xl px-3 py-2.5', tones[tone])}>
      <p className="text-[11px] font-medium uppercase tracking-wide opacity-70">{label}</p>
      <p className="mt-0.5 font-display text-base font-bold tabular-nums">{value}</p>
      {sub && <p className="text-[11px] opacity-70">{sub}</p>}
    </div>
  );
}

function SectionTitle({ icon: Icon, children, aside }: { icon: LucideIcon; children: ReactNode; aside?: ReactNode }) {
  return (
    <div className="mb-2 mt-6 flex items-center justify-between gap-2 first:mt-0">
      <h3 className="flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
        <Icon className="h-4 w-4 text-brand-700 dark:text-brand-300" aria-hidden />
        {children}
      </h3>
      {aside}
    </div>
  );
}

/* ------------------------------------------------------------------ reason dialog (cancel / void / reject) */

interface ReasonDialogProps {
  open: boolean;
  onClose: () => void;
  title: string;
  description?: ReactNode;
  label?: string;
  placeholder?: string;
  confirmText: string;
  danger?: boolean;
  field?: string;
  minLength?: number;
  onSubmit: (reason: string) => Promise<unknown>;
  children?: ReactNode;
}

/** Modal asking for a mandatory reason; maps server field errors inline. */
export function ReasonDialog({ open, onClose, title, description, label = 'Reason', placeholder, confirmText, danger, field = 'reason', minLength = 5, onSubmit, children }: ReasonDialogProps) {
  const [reason, setReason] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (open) {
      setReason('');
      setError(null);
    }
  }, [open]);
  const submit = async () => {
    if (reason.trim().length < minLength) {
      setError(`Enter at least ${minLength} characters.`);
      return;
    }
    setSaving(true);
    try {
      await onSubmit(reason.trim());
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.[field] ?? err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="md"
      title={title}
      description={description}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant={danger ? 'danger' : 'primary'} onClick={submit} loading={saving}>{confirmText}</Button>
        </>
      }
    >
      {children}
      <Field label={label} required error={error} htmlFor="reason-input">
        <Textarea id="reason-input" rows={3} maxLength={255} value={reason} invalid={!!error} placeholder={placeholder} onChange={(e) => { setReason(e.target.value); setError(null); }} />
      </Field>
    </Modal>
  );
}

/* ------------------------------------------------------------------ concession (discount / waiver) */

export function ConcessionModal({ invoice, open, onClose, onDone }: { invoice: Pick<InvoiceDetail, 'id' | 'invoice_no' | 'balance_amount' | 'student_name'> | null; open: boolean; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const [values, setValues] = useState({ type: 'discount', amount: '', reason: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (open) {
      setValues({ type: 'discount', amount: '', reason: '' });
      setErrors({});
    }
  }, [open]);
  if (!invoice) return null;
  const balance = toNumber(invoice.balance_amount);
  const submit = async () => {
    setSaving(true);
    try {
      const res = await api.post(`fees/invoices/${invoice.id}/concession`, values);
      toast.success(res.message);
      onDone?.();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      title="Add concession"
      description={`${invoice.invoice_no} · ${invoice.student_name} · balance ${formatMoney(balance)}`}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving} icon={BadgePercent}>Apply concession</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field htmlFor="fees-type" label="Type" required error={errors.type}>
          <Select id="fees-type" value={values.type} onChange={(e) => setValues((v) => ({ ...v, type: e.target.value }))} options={{ discount: 'Discount', waiver: 'Fee waiver' }} />
        </Field>
        <Field htmlFor="fees-amount" label="Amount (₹)" required error={errors.amount} hint={`Up to ${formatMoney(balance)}`}>
          <Input id="fees-amount" type="number" min={1} max={balance} step="1" value={values.amount} invalid={!!errors.amount} onChange={(e) => setValues((v) => ({ ...v, amount: e.target.value }))} prefix="₹" />
        </Field>
        <Field htmlFor="fees-reason-approval-reference" label="Reason / approval reference" required error={errors.reason} className="sm:col-span-2">
          <Textarea id="fees-reason-approval-reference" rows={2} maxLength={255} value={values.reason} invalid={!!errors.reason} placeholder="e.g. Financial hardship — approved by the Director (FN/ACC/2026/14)" onChange={(e) => setValues((v) => ({ ...v, reason: e.target.value }))} />
        </Field>
      </div>
      <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">Scholarships are awarded from Scholarships &amp; Discounts so they are tracked per scheme.</p>
    </Modal>
  );
}

/* ------------------------------------------------------------------ refund request */

export function RefundRequestModal({ open, onClose, payment, onDone }: { open: boolean; onClose: () => void; payment?: { id: number; label: string; refundable: number } | null; onDone?: () => void }) {
  const toast = useToast();
  const [paymentId, setPaymentId] = useState<number | null>(payment?.id ?? null);
  const [picked, setPicked] = useState<{ label: string; sub?: string } | null>(null);
  const [amount, setAmount] = useState('');
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (open) {
      setPaymentId(payment?.id ?? null);
      setPicked(null);
      setAmount(payment ? String(payment.refundable) : '');
      setReason('');
      setErrors({});
    }
    // Reset only when the dialog opens or the receipt changes (the parent passes a fresh object on every render).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, payment?.id]);
  const submit = async () => {
    setSaving(true);
    try {
      const res = await api.post('fees/refunds', { payment_id: paymentId, amount, reason });
      toast.success(res.message);
      onDone?.();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      title="Request refund"
      description="Refunds need approval (Fees › Approve) before they are paid out."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving} icon={Undo2}>Submit request</Button>
        </>
      }
    >
      <div className="grid gap-4">
        <Field htmlFor="fees-against-receipt" label="Against receipt" required error={errors.payment_id} hint={picked?.sub}>
          {payment ? (
            <Input id="fees-against-receipt" value={payment.label} readOnly />
          ) : (
            <Combobox id="fees-against-receipt" optionsUrl="fees/payments/search" value={paymentId} onChange={(v, o) => { setPaymentId(v ? Number(v) : null); setPicked(o ? { label: o.label, sub: o.sub } : null); }} placeholder="Search receipt no, student name or ID…" invalid={!!errors.payment_id} />
          )}
        </Field>
        <Field htmlFor="fees-refund-amount" label="Refund amount (₹)" required error={errors.amount} hint={payment ? `Refundable balance ${formatMoney(payment.refundable)}` : undefined}>
          <Input id="fees-refund-amount" type="number" min={1} step="1" value={amount} invalid={!!errors.amount} onChange={(e) => setAmount(e.target.value)} prefix="₹" />
        </Field>
        <Field htmlFor="fees-reason" label="Reason" required error={errors.reason}>
          <Textarea id="fees-reason" rows={3} maxLength={255} value={reason} invalid={!!errors.reason} placeholder="e.g. Excess amount paid — installment paid twice" onChange={(e) => setReason(e.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}

/* ------------------------------------------------------------------ invoice drawer */

export function InvoiceDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const navigate = useNavigate();
  const refresh = useFeesRefresh();
  const { data: inv, isLoading, error } = useApi<InvoiceDetail>(['fees-invoice', id], `fees/invoices/${id}`, undefined, { enabled: !!id });
  const [dialog, setDialog] = useState<'concession' | 'cancel' | null>(null);
  const [busy, setBusy] = useState<string | null>(null);

  const run = async (key: string, fn: () => Promise<{ message: string }>) => {
    setBusy(key);
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const open = inv && !['cancelled', 'paid', 'waived'].includes(inv.status);
  const canEdit = can('fees', 'edit');

  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      width="max-w-2xl"
      title={inv ? <span className="flex flex-wrap items-center gap-2">{inv.invoice_no}<StatusBadge status={inv.status} label={INVOICE_STATUS[inv.status]} /></span> : 'Invoice'}
      description={inv ? `${inv.title}${inv.session_name ? ` · ${inv.session_name}` : ''}` : undefined}
      footer={
        inv && (
          <div className="flex w-full flex-wrap items-center justify-end gap-2">
            <Button size="sm" variant="secondary" icon={Printer} onClick={() => openPrint('fee-invoice.php', { id: inv.id })}>Invoice</Button>
            <Button size="sm" variant="secondary" icon={ScrollText} onClick={() => openPrint('fee-statement.php', { student_id: inv.student_id })}>Statement</Button>
            {open && can('fees', 'create') && (
              <Button size="sm" variant="success" icon={HandCoins} onClick={() => { onClose(); navigate(`/fees/collect?student=${inv.student_id}`); }}>Collect</Button>
            )}
          </div>
        )
      }
    >
      {error ? (
        <Alert variant="error" title="Unable to load the invoice">{(error as ApiError).message}</Alert>
      ) : isLoading || !inv ? (
        <div className="space-y-4">
          <Skeleton className="h-12 w-2/3" />
          <div className="grid grid-cols-3 gap-3">{[1, 2, 3].map((i) => <Skeleton key={i} className="h-16" />)}</div>
          <Skeleton className="h-40" />
        </div>
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <PersonCell name={inv.student_name} src={inv.photo} to={`/students/${inv.student_id}`} sub={`${inv.student_uid} · ${inv.program_short ?? ''}${inv.semester_no ? ` · Sem ${inv.semester_no}` : ''}${inv.section_name ? ` · Sec ${inv.section_name}` : ''}`} />
            <div className="text-right text-xs text-slate-500 dark:text-slate-400">
              <p>Raised {formatDate(inv.created_at)}{inv.created_by_name ? ` by ${inv.created_by_name}` : ''}</p>
              <p>Due {formatDate(inv.due_date)}</p>
            </div>
          </div>
          {inv.status === 'cancelled' && (
            <Alert variant="warning" className="mt-4" title={`Cancelled ${formatDateTime(inv.cancelled_at)}${inv.cancelled_by_name ? ` by ${inv.cancelled_by_name}` : ''}`}>{inv.cancelled_reason}</Alert>
          )}
          <div className="mt-4 grid grid-cols-2 gap-2.5 sm:grid-cols-4">
            <AmountTile label="Net payable" value={formatMoney(inv.net_amount)} tone="blue" sub={`Gross ${formatMoney(inv.gross_amount)}`} />
            <AmountTile label="Paid" value={formatMoney(inv.paid_amount)} tone="green" />
            <AmountTile label="Balance" value={formatMoney(inv.balance_amount)} tone={toNumber(inv.balance_amount) > 0 ? 'amber' : 'slate'} />
            <AmountTile label="Overdue" value={formatMoney(inv.overdue_amount)} tone={toNumber(inv.overdue_amount) > 0 ? 'red' : 'slate'} sub={inv.late_fee_due > 0 ? `+${formatMoney(inv.late_fee_due)} late fee accrued` : undefined} />
          </div>

          {canEdit && open && (
            <div className="mt-4 flex flex-wrap gap-2">
              <Button size="xs" variant="soft" icon={BadgePercent} onClick={() => setDialog('concession')}>Concession</Button>
              <Button size="xs" variant="soft" icon={AlarmClock} disabled={inv.late_fee_due <= 0} loading={busy === 'late'} onClick={() => run('late', () => api.post(`fees/invoices/${inv.id}/late-fee`))}>
                {inv.late_fee_due > 0 ? `Apply late fee ${formatMoney(inv.late_fee_due)}` : 'No late fee due'}
              </Button>
              <Button size="xs" variant="soft" icon={BellRing} loading={busy === 'remind'} onClick={() => run('remind', () => api.post('fees/reminders', { ids: [inv.id] }))}>Send reminder</Button>
              <Button size="xs" variant="ghost" icon={Ban} className="!text-red-600" onClick={() => setDialog('cancel')}>Cancel invoice</Button>
            </div>
          )}

          <SectionTitle icon={FileText}>Fee heads</SectionTitle>
          <div className="overflow-hidden rounded-xl border border-slate-200 dark:border-slate-800">
            <table className="w-full text-sm">
              <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
                {inv.items.map((it) => (
                  <tr key={it.id}>
                    <td className="px-3 py-2 text-slate-700 dark:text-slate-200">{it.head_name} <span className="text-xs text-slate-400">{it.head_code}</span></td>
                    <td className="px-3 py-2 text-right tabular-nums">{formatMoney(it.amount)}</td>
                  </tr>
                ))}
                {toNumber(inv.scholarship_amount) > 0 && <SummaryRow label="Less: scholarship" value={-toNumber(inv.scholarship_amount)} />}
                {toNumber(inv.discount_amount) > 0 && <SummaryRow label="Less: discount / waiver" value={-toNumber(inv.discount_amount)} />}
                {toNumber(inv.fine_amount) > 0 && <SummaryRow label="Add: late fee" value={toNumber(inv.fine_amount)} />}
                <tr className="bg-slate-50 font-semibold dark:bg-slate-800/60">
                  <td className="px-3 py-2">Net payable</td>
                  <td className="px-3 py-2 text-right tabular-nums">{formatMoney(inv.net_amount)}</td>
                </tr>
              </tbody>
            </table>
          </div>

          {inv.installments.length > 0 && (
            <>
              <SectionTitle icon={CalendarClock}>Installment plan</SectionTitle>
              <div className="space-y-2">
                {inv.installments.map((i) => {
                  const pct = toNumber(i.amount) > 0 ? (toNumber(i.paid_amount) / toNumber(i.amount)) * 100 : 0;
                  return (
                    <div key={i.id} className="rounded-xl border border-slate-200 px-3 py-2.5 dark:border-slate-800">
                      <div className="flex flex-wrap items-center justify-between gap-2 text-sm">
                        <span className="font-medium text-slate-800 dark:text-slate-100">Installment {i.installment_no} · due {formatDate(i.due_date)}</span>
                        <span className="flex items-center gap-2">
                          <span className="tabular-nums text-slate-600 dark:text-slate-300">{formatMoney(i.paid_amount)} / {formatMoney(i.amount)}</span>
                          <StatusBadge status={i.status} />
                        </span>
                      </div>
                      <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <div className={clsx('h-full rounded-full', i.status === 'overdue' ? 'bg-red-500' : 'bg-emerald-500')} style={{ width: `${Math.min(100, pct)}%` }} />
                      </div>
                    </div>
                  );
                })}
              </div>
            </>
          )}

          <SectionTitle icon={ReceiptText}>Payments</SectionTitle>
          {inv.payments.length === 0 ? (
            <p className="rounded-xl border border-dashed border-slate-200 px-3 py-4 text-center text-sm text-slate-500 dark:border-slate-700">No payments received yet.</p>
          ) : (
            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
              {inv.payments.map((p) => (
                <li key={p.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm">
                  <div className="min-w-0">
                    <p className="font-medium text-slate-800 dark:text-slate-100">
                      <button type="button" className="link" onClick={() => openPrint('fee-receipt.php', { id: p.id })}>{p.receipt_no}</button>
                      <span className="ml-2 text-xs font-normal text-slate-500">{formatDate(p.payment_date)}{p.collected_by_name ? ` · ${p.collected_by_name}` : ''}</span>
                    </p>
                    <p className="text-xs text-slate-500">{MODE_LABELS[p.mode] ?? p.mode}{p.reference_no ? ` · ${p.reference_no}` : ''}{toNumber(p.applied_fine) > 0 ? ` · incl. late fee ${formatMoney(p.applied_fine)}` : ''}</p>
                  </div>
                  <span className="flex items-center gap-2">
                    <span className={clsx('font-semibold tabular-nums', p.status === 'cancelled' && 'text-slate-400 line-through')}>{formatMoney(p.applied_amount)}</span>
                    {p.status !== 'success' && <StatusBadge status={p.status} />}
                  </span>
                </li>
              ))}
            </ul>
          )}

          {inv.concessions.length > 0 && (
            <>
              <SectionTitle icon={BadgePercent}>Concessions</SectionTitle>
              <ul className="space-y-2">
                {inv.concessions.map((c) => (
                  <li key={c.id} className="flex items-start justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
                    <div className="min-w-0">
                      <p className="font-medium text-slate-800 dark:text-slate-100">{c.scholarship_name ?? labelize(c.type)}</p>
                      <p className="text-xs text-slate-500">{c.reason}{c.created_by_name ? ` · ${c.created_by_name}` : ''} · {formatDate(c.created_at)}</p>
                    </div>
                    <span className="flex shrink-0 items-center gap-2">
                      <span className="font-semibold tabular-nums">{formatMoney(c.amount)}</span>
                      {c.status !== 'approved' && <StatusBadge status={c.status} />}
                    </span>
                  </li>
                ))}
              </ul>
            </>
          )}

          <SectionTitle icon={Mail}>Contact &amp; reminders</SectionTitle>
          <DescriptionList
            items={[
              { label: 'Mobile', value: inv.mobile },
              { label: 'Email', value: inv.email },
              { label: 'Reminders sent', value: inv.reminder_count ? `${inv.reminder_count} · last ${formatDateTime(inv.last_reminder_at)}` : 'None' },
              { label: 'Late fee rule', value: inv.late_fee_rate.per_day > 0 ? `${formatMoney(inv.late_fee_rate.per_day)}/day${inv.late_fee_rate.max ? `, max ${formatMoney(inv.late_fee_rate.max)}` : ''}` : 'No late fee' },
              { label: 'Fee structure', value: inv.structure_name, full: true },
              { label: 'Remarks', value: inv.remarks, full: true },
            ]}
          />

          <ConcessionModal invoice={inv} open={dialog === 'concession'} onClose={() => setDialog(null)} onDone={refresh} />
          <ReasonDialog
            open={dialog === 'cancel'}
            onClose={() => setDialog(null)}
            danger
            title={`Cancel invoice ${inv.invoice_no}?`}
            description="Only invoices without any money received can be cancelled. The invoice stays on record as cancelled."
            label="Reason for cancelling"
            placeholder="e.g. Raised against the wrong program — student transferred to BCA"
            confirmText="Cancel invoice"
            onSubmit={async (reason) => {
              const res = await api.post(`fees/invoices/${inv.id}/cancel`, { reason });
              toast.success(res.message);
              await refresh();
            }}
          />
        </>
      )}
    </Drawer>
  );
}

function SummaryRow({ label, value }: { label: string; value: number }) {
  return (
    <tr className="text-slate-600 dark:text-slate-300">
      <td className="px-3 py-2 text-xs">{label}</td>
      <td className={clsx('px-3 py-2 text-right text-xs tabular-nums', value < 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600 dark:text-red-400')}>
        {value < 0 ? `− ${formatMoney(-value)}` : `+ ${formatMoney(value)}`}
      </td>
    </tr>
  );
}

/* ------------------------------------------------------------------ payment drawer */

export function PaymentDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useFeesRefresh();
  const { data: p, isLoading, error } = useApi<PaymentDetail>(['fees-payment', id], `fees/payments/${id}`, undefined, { enabled: !!id });
  const [dialog, setDialog] = useState<'void' | 'refund' | null>(null);
  const [emailing, setEmailing] = useState(false);

  const email = async () => {
    if (!p) return;
    setEmailing(true);
    try {
      const res = await api.post(`fees/payments/${p.id}/email`);
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setEmailing(false);
    }
  };

  return (
    <Drawer
      open={id !== null}
      onClose={onClose}
      width="max-w-xl"
      title={p ? <span className="flex flex-wrap items-center gap-2">{p.receipt_no}<StatusBadge status={p.status} label={p.status === 'cancelled' ? 'Voided' : undefined} /></span> : 'Payment'}
      description={p ? `${formatDate(p.payment_date)} · ${p.mode_label}` : undefined}
      footer={
        p && (
          <div className="flex w-full flex-wrap items-center justify-end gap-2">
            {p.status === 'success' && can('fees', 'approve') && <Button size="sm" variant="ghost" icon={Ban} className="!text-red-600" onClick={() => setDialog('void')}>Void</Button>}
            {p.refundable > 0 && p.status === 'success' && can('fees', 'create') && <Button size="sm" variant="secondary" icon={Undo2} onClick={() => setDialog('refund')}>Refund</Button>}
            {p.status === 'success' && can('fees', 'create') && <Button size="sm" variant="secondary" icon={Mail} loading={emailing} onClick={email}>Email</Button>}
            <Button size="sm" icon={Printer} onClick={() => openPrint('fee-receipt.php', { id: p.id })}>Print receipt</Button>
          </div>
        )
      }
    >
      {error ? (
        <Alert variant="error" title="Unable to load the payment">{(error as ApiError).message}</Alert>
      ) : isLoading || !p ? (
        <div className="space-y-4">
          <Skeleton className="h-12 w-2/3" />
          <Skeleton className="h-24" />
          <Skeleton className="h-32" />
        </div>
      ) : (
        <>
          <div className="flex flex-wrap items-center justify-between gap-3">
            <PersonCell name={p.student_name ?? '—'} src={p.photo} to={p.student_id ? `/students/${p.student_id}` : undefined} sub={`${p.student_uid ?? ''}${p.program_short ? ` · ${p.program_short}` : ''}${p.current_semester ? ` · Sem ${p.current_semester}` : ''}`} />
            <div className="text-right">
              <p className="font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{formatMoney(p.total_amount, 2)}</p>
              {toNumber(p.fine_amount) > 0 && <p className="text-xs text-slate-500">incl. late fee {formatMoney(p.fine_amount)}</p>}
            </div>
          </div>
          {p.status === 'cancelled' && (
            <Alert variant="warning" className="mt-4" title={`Voided ${formatDateTime(p.cancelled_at)}${p.cancelled_by_name ? ` by ${p.cancelled_by_name}` : ''}`}>{p.cancelled_reason}</Alert>
          )}
          <div className="mt-5">
            <DescriptionList
              items={[
                { label: 'Payment mode', value: <ModeBadge mode={p.mode} /> },
                { label: 'Reference', value: p.reference_no },
                { label: 'Bank', value: p.bank_name },
                { label: 'Gateway', value: p.gateway ? labelize(p.gateway) : null },
                { label: 'Collected by', value: p.collected_by_name ?? (p.mode === 'online' ? 'Online (self-service)' : null) },
                { label: 'Recorded', value: formatDateTime(p.created_at) },
                { label: 'Receipt printed', value: p.printed_count ? `${p.printed_count} time(s)` : 'Not yet' },
                { label: 'Emailed', value: p.emailed_at ? formatDateTime(p.emailed_at) : 'No' },
                { label: 'Remarks', value: p.remarks, full: true },
              ]}
            />
          </div>
          <SectionTitle icon={FileText}>Applied to invoices</SectionTitle>
          <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-slate-800 dark:border-slate-800">
            {p.allocations.map((a) => (
              <li key={a.student_fee_id} className="flex items-center justify-between gap-3 px-3 py-2.5 text-sm">
                <div className="min-w-0">
                  <p className="font-medium text-slate-800 dark:text-slate-100">{a.invoice_no}</p>
                  <p className="truncate text-xs text-slate-500">{a.title} · balance now {formatMoney(a.balance_amount)}</p>
                </div>
                <span className="shrink-0 text-right">
                  <span className="block font-semibold tabular-nums">{formatMoney(a.amount)}</span>
                  {toNumber(a.fine_amount) > 0 && <span className="block text-[11px] text-slate-500">late fee {formatMoney(a.fine_amount)}</span>}
                </span>
              </li>
            ))}
          </ul>
          {p.discounts.length > 0 && (
            <>
              <SectionTitle icon={BadgePercent}>Counter discount</SectionTitle>
              {p.discounts.map((d, i) => (
                <p key={i} className="text-sm text-slate-600 dark:text-slate-300">{formatMoney(d.amount)} on {d.invoice_no} — {d.reason}</p>
              ))}
            </>
          )}
          {p.refunds.length > 0 && (
            <>
              <SectionTitle icon={Undo2} aside={<Link to="/fees/refunds" className="link text-xs">All refunds</Link>}>Refunds</SectionTitle>
              <ul className="space-y-2">
                {p.refunds.map((r) => (
                  <li key={r.id} className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm dark:bg-slate-800/60">
                    <div className="min-w-0">
                      <p className="font-medium">{r.refund_no}</p>
                      <p className="truncate text-xs text-slate-500">{r.reason}</p>
                    </div>
                    <span className="flex shrink-0 items-center gap-2">
                      <span className="font-semibold tabular-nums">{formatMoney(r.amount)}</span>
                      <StatusBadge status={r.status} label={r.status === 'processed' ? 'Paid' : undefined} />
                    </span>
                  </li>
                ))}
              </ul>
            </>
          )}
          <ReasonDialog
            open={dialog === 'void'}
            onClose={() => setDialog(null)}
            danger
            title={`Void receipt ${p.receipt_no}?`}
            description="The payment is marked cancelled and the invoice balances are restored. Use this for dishonoured cheques or entries made in error."
            label="Reason for voiding"
            placeholder='e.g. Cheque returned unpaid — "Funds insufficient"'
            confirmText="Void receipt"
            onSubmit={async (reason) => {
              const res = await api.post(`fees/payments/${p.id}/void`, { reason });
              toast.success(res.message);
              await refresh();
            }}
          />
          <RefundRequestModal open={dialog === 'refund'} onClose={() => setDialog(null)} payment={{ id: p.id, label: `${p.receipt_no} — ${p.student_name ?? ''}`, refundable: p.refundable }} onDone={refresh} />
        </>
      )}
    </Drawer>
  );
}
