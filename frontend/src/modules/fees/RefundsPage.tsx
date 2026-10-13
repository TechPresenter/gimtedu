import { useEffect, useState } from 'react';
import clsx from 'clsx';
import { BadgeCheck, Banknote, Ban, CircleCheckBig, Clock3, ReceiptText, XCircle } from 'lucide-react';
import { Button, Field, Input, Modal, PageHeader, Select, StatusBadge, Textarea, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatNumber, isoDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { MODE_LABELS, PaymentDrawer, ReasonDialog, RefundRequestModal, useFeesRefresh } from './shared';

const REFUND_LABEL: Record<string, string> = { pending: 'Pending', approved: 'Approved', processed: 'Paid', rejected: 'Rejected' };

export default function RefundsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useFeesRefresh();
  const [requestOpen, setRequestOpen] = useState(false);
  const [rejectRow, setRejectRow] = useState<Row | null>(null);
  const [payRow, setPayRow] = useState<Row | null>(null);
  const [paymentId, setPaymentId] = useState<number | null>(null);
  const approver = can('fees', 'approve');

  const approve = async (r: Row) => {
    const ok = await confirm({ title: `Approve refund ${r.refund_no}?`, message: <>Approve <strong>{formatMoney(r.amount)}</strong> to {r.student_name} against receipt {r.receipt_no}. It can then be paid out and marked as paid.</>, confirmText: 'Approve refund' });
    if (!ok) return;
    try {
      const res = await api.post(`fees/refunds/${r.id}/approve`, {});
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Refunds"
        breadcrumbs={[{ label: 'Fees & Accounts', to: '/fees' }, { label: 'Refunds' }]}
        description="Refund requests against receipts: approval by an authorised approver, then payout with mode and reference."
        actions={<Button variant="secondary" icon={ReceiptText} to="/fees/payments">Payments</Button>}
      />
      <CrudTable
        module="refunds"
        urlState
        emptyTitle="No refund requests"
        emptyText="Refund requests raised from a payment appear here for approval."
        addLabel="New refund request"
        onCreate={() => setRequestOpen(true)}
        renderers={{
          status: (r) => <StatusBadge status={r.status} label={REFUND_LABEL[r.status]} colors={{ pending: 'amber', approved: 'blue', processed: 'green', rejected: 'red' }} />,
          refund_no: (r) => (
            <div className="leading-tight">
              <span className="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{r.refund_no}</span>
              <button type="button" onClick={() => setPaymentId(Number(r.payment_id))} className="block whitespace-nowrap text-left text-xs text-slate-500 hover:text-brand-700 dark:hover:text-brand-300">
                <span title={`Requested ${formatDate(r.created_at)}`}>{r.receipt_no}</span>
              </button>
            </div>
          ),
          reason: (r) => <p className="min-w-[170px] text-sm text-slate-600 dark:text-slate-300" title={r.reason}>{String(r.reason ?? '').length > 90 ? `${String(r.reason).slice(0, 89)}…` : r.reason}</p>,
          receipt_no: (r) => (
            <button type="button" onClick={() => setPaymentId(Number(r.payment_id))} className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200">
              {r.receipt_no}
            </button>
          ),
          amount: (r) => (
            <span className="whitespace-nowrap font-semibold tabular-nums">
              {formatMoney(r.amount)}
              <span className="block text-[11px] font-normal text-slate-500">of {formatMoney(r.payment_total)}</span>
            </span>
          ),
        }}
        rowMenu={(r) => [
          approver && r.status === 'pending' && { label: 'Approve', icon: BadgeCheck, onClick: () => approve(r) },
          approver && r.status === 'approved' && { label: 'Mark as paid', icon: Banknote, onClick: () => setPayRow(r) },
          approver && ['pending', 'approved'].includes(String(r.status)) && { label: 'Reject', icon: Ban, danger: true, onClick: () => setRejectRow(r) },
          { label: 'View payment', icon: ReceiptText, onClick: () => setPaymentId(Number(r.payment_id)) },
        ]}
        header={({ summary }) =>
          summary ? (
            <div className="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-800">
              {[
                { l: 'Pending approval', v: `${formatNumber(summary.pending)} · ${formatMoney(summary.pending_amount)}`, icon: Clock3, c: 'text-amber-600' },
                { l: 'Approved, to pay', v: `${formatNumber(summary.approved)} · ${formatMoney(summary.approved_amount)}`, icon: BadgeCheck, c: 'text-blue-600' },
                { l: 'Paid out', v: `${formatNumber(summary.processed)} · ${formatMoney(summary.processed_amount)}`, icon: CircleCheckBig, c: 'text-emerald-600' },
                { l: 'Rejected', v: formatNumber(summary.rejected), icon: XCircle, c: 'text-red-500' },
              ].map((x) => (
                <div key={x.l} className="flex items-center gap-3 bg-white px-4 py-3 dark:bg-slate-900">
                  <x.icon className={clsx('h-5 w-5 shrink-0', x.c)} aria-hidden />
                  <div className="min-w-0">
                    <p className="truncate text-[11px] font-medium uppercase tracking-wide text-slate-500">{x.l}</p>
                    <p className="mt-0.5 truncate text-sm font-semibold text-slate-900 dark:text-white">{x.v}</p>
                  </div>
                </div>
              ))}
            </div>
          ) : null
        }
      />
      <RefundRequestModal open={requestOpen} onClose={() => setRequestOpen(false)} onDone={refresh} />
      <ReasonDialog
        open={!!rejectRow}
        onClose={() => setRejectRow(null)}
        danger
        field="remarks"
        title={`Reject refund ${rejectRow?.refund_no ?? ''}?`}
        description={rejectRow ? `${formatMoney(rejectRow.amount)} requested by ${rejectRow.requested_by_name ?? 'staff'} for ${rejectRow.student_name}.` : undefined}
        label="Reason for rejecting"
        placeholder="e.g. Not eligible as per the refund policy"
        confirmText="Reject refund"
        onSubmit={async (remarks) => {
          const res = await api.post(`fees/refunds/${rejectRow?.id}/reject`, { remarks });
          toast.success(res.message);
          await refresh();
        }}
      />
      <MarkPaidModal row={payRow} onClose={() => setPayRow(null)} onDone={refresh} />
      <PaymentDrawer id={paymentId} onClose={() => setPaymentId(null)} />
    </>
  );
}

function MarkPaidModal({ row, onClose, onDone }: { row: Row | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [values, setValues] = useState({ mode: 'bank_transfer', reference_no: '', refund_date: isoDate(), remarks: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (row) {
      setValues({ mode: 'bank_transfer', reference_no: '', refund_date: isoDate(), remarks: '' });
      setErrors({});
    }
  }, [row]);
  const set = (k: keyof typeof values, v: string) => {
    setValues((s) => ({ ...s, [k]: v }));
    setErrors((e) => ({ ...e, [k]: '' }));
  };
  const submit = async () => {
    setSaving(true);
    try {
      const res = await api.post(`fees/refunds/${row?.id}/process`, values);
      toast.success(res.message);
      onDone();
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  const modes = Object.entries(MODE_LABELS).filter(([k]) => k !== 'online').map(([value, label]) => ({ value, label }));
  return (
    <Modal
      open={!!row}
      onClose={onClose}
      static={saving}
      title={`Mark refund ${row?.refund_no ?? ''} as paid`}
      description={row ? `${formatMoney(row.amount)} to ${row.student_name} (${row.student_uid})` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="success" icon={Banknote} onClick={submit} loading={saving}>Mark as paid</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field htmlFor="refund-paid-via" label="Paid via" required error={errors.mode}>
          <Select id="refund-paid-via" value={values.mode} onChange={(e) => set('mode', e.target.value)} options={modes} />
        </Field>
        <Field htmlFor="refund-refund-date" label="Refund date" required error={errors.refund_date}>
          <Input id="refund-refund-date" type="date" value={values.refund_date} max={isoDate()} invalid={!!errors.refund_date} onChange={(e) => set('refund_date', e.target.value)} />
        </Field>
        {values.mode !== 'cash' && (
          <Field htmlFor="refund-utr-cheque-number" label="UTR / cheque number" required error={errors.reference_no} className="sm:col-span-2">
            <Input id="refund-utr-cheque-number" value={values.reference_no} maxLength={100} invalid={!!errors.reference_no} onChange={(e) => set('reference_no', e.target.value)} />
          </Field>
        )}
        <Field htmlFor="refund-remarks" label="Remarks" className="sm:col-span-2">
          <Textarea id="refund-remarks" rows={2} maxLength={255} value={values.remarks} onChange={(e) => set('remarks', e.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}
