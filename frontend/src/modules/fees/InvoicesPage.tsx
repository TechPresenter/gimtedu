import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import clsx from 'clsx';
import { AlarmClock, BadgePercent, Ban, BellRing, FilePlus2, HandCoins, Plus, Printer, ScrollText, Trash2 } from 'lucide-react';
import { Alert, Button, Combobox, Field, Input, Modal, PageHeader, PersonCell, Select, StatusBadge, Textarea, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useLookup } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatMoney, formatMoneyShort, formatNumber, isoDate, toNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ConcessionModal, INVOICE_STATUS, InvoiceDrawer, ReasonDialog, openPrint, useFeesRefresh } from './shared';

export default function InvoicesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const navigate = useNavigate();
  const refresh = useFeesRefresh();
  const [viewId, setViewId] = useState<number | null>(null);
  const [raiseOpen, setRaiseOpen] = useState(false);
  const [concession, setConcession] = useState<Row | null>(null);
  const [cancelRow, setCancelRow] = useState<Row | null>(null);
  const [busy, setBusy] = useState(false);

  const post = async (path: string, body: unknown) => {
    try {
      const res = await api.post(path, body);
      toast.success(res.message);
      await refresh();
      return true;
    } catch (e) {
      toast.error((e as ApiError).message);
      return false;
    }
  };
  const lateFeeAll = async () => {
    const ok = await confirm({ title: 'Apply late fee to all overdue invoices?', message: 'The accrued late fee (per-day rate of each fee structure, up to its cap) is added to every overdue invoice of the current session. Invoices already charged today are skipped.', confirmText: 'Apply late fee' });
    if (!ok) return;
    setBusy(true);
    await post('fees/invoices/late-fee', { all_overdue: true });
    setBusy(false);
  };
  const open = (r: Row) => !['cancelled', 'paid', 'waived'].includes(String(r.status));

  return (
    <>
      <PageHeader
        title="Student Fees & Invoices"
        breadcrumbs={[{ label: 'Fees & Accounts', to: '/fees' }, { label: 'Invoices' }]}
        description="Every fee invoice raised to students — balances, installments, concessions, late fees and reminders."
        actions={
          <>
            {can('fees', 'edit') && <Button variant="secondary" icon={AlarmClock} loading={busy} onClick={lateFeeAll}>Apply late fees</Button>}
            {can('fees', 'create') && <Button variant="success" icon={HandCoins} to="/fees/collect">Collect fee</Button>}
          </>
        }
      />
      <CrudTable
        module="student_fees"
        urlState
        addLabel="Raise invoice"
        emptyTitle="No invoices yet"
        emptyText="Assign a fee structure to students or raise an invoice manually."
        onCreate={() => setRaiseOpen(true)}
        onView={(r) => setViewId(Number(r.id))}
        renderers={{
          // Student ID and program on separate lines keep the column narrow so net / paid / balance fit at desktop widths.
          student_name: (r) => (
            <PersonCell name={String(r.student_name)} src={r.photo} to={`/students/${r.student_id}`}
              sub={<><span className="block">{r.student_uid}</span>{r.program_short && <span className="block">{r.program_short}</span>}</>} />
          ),
          title: (r) => (
            <div className="min-w-[160px] leading-tight">
              <span className="font-semibold text-slate-900 dark:text-white">{r.title}</span>
              <span className="block text-xs text-slate-500 dark:text-slate-400">{r.due_label}</span>
            </div>
          ),
          invoice_no: (r) => (
            <button type="button" onClick={() => setViewId(Number(r.id))} className="whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200">
              {r.invoice_no}
            </button>
          ),
          status: (r) => <StatusBadge status={r.status} label={INVOICE_STATUS[r.status]} />,
          balance_amount: (r) => (
            <span className={clsx('whitespace-nowrap font-semibold tabular-nums', toNumber(r.overdue_amount) > 0 ? 'text-red-600 dark:text-red-400' : toNumber(r.balance_amount) > 0 ? 'text-slate-900 dark:text-white' : 'text-slate-400')}>
              {formatMoney(r.balance_amount)}
              <span className="block text-[11px] font-normal text-slate-500 dark:text-slate-400">paid {formatMoney(r.paid_amount)} of {formatMoney(r.net_amount)}</span>
              {toNumber(r.overdue_amount) > 0 && <span className="block text-[11px] font-medium">{formatMoney(r.overdue_amount)} overdue</span>}
            </span>
          ),
        }}
        rowMenu={(r) => [
          open(r) && can('fees', 'create') && { label: 'Collect payment', icon: HandCoins, onClick: () => navigate(`/fees/collect?student=${r.student_id}`) },
          { label: 'Print invoice', icon: Printer, onClick: () => openPrint('fee-invoice.php', { id: r.id }) },
          { label: 'Fee statement', icon: ScrollText, onClick: () => openPrint('fee-statement.php', { student_id: r.student_id }) },
          open(r) && can('fees', 'edit') && { divider: true, label: '' },
          open(r) && can('fees', 'edit') && { label: 'Add concession', icon: BadgePercent, onClick: () => setConcession(r) },
          open(r) && can('fees', 'edit') && { label: 'Apply late fee', icon: AlarmClock, onClick: () => post(`fees/invoices/${r.id}/late-fee`, {}) },
          open(r) && can('fees', 'edit') && { label: 'Send reminder', icon: BellRing, onClick: () => post('fees/reminders', { ids: [r.id] }) },
          r.status !== 'cancelled' && can('fees', 'edit') && { label: 'Cancel invoice', icon: Ban, danger: true, onClick: () => setCancelRow(r) },
        ]}
        bulkActions={
          can('fees', 'edit')
            ? [
                { label: 'Send reminders', icon: BellRing, onClick: async (ids, clear) => { if (await post('fees/reminders', { ids })) clear(); } },
                { label: 'Apply late fee', icon: AlarmClock, onClick: async (ids, clear) => { if (await post('fees/invoices/late-fee', { ids })) clear(); } },
              ]
            : []
        }
        header={({ summary }) =>
          summary ? (
            <div className="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 sm:grid-cols-5 dark:border-slate-800 dark:bg-slate-800">
              {[
                ['Invoices', formatNumber(summary.invoices), ''],
                ['Net billed', formatMoneyShort(summary.net), ''],
                ['Collected', formatMoneyShort(summary.paid), 'text-emerald-700 dark:text-emerald-400'],
                ['Balance', formatMoneyShort(summary.balance), 'text-amber-700 dark:text-amber-400'],
                ['Overdue', `${formatMoneyShort(summary.overdue)} · ${formatNumber(summary.overdue_count)}`, 'text-red-600 dark:text-red-400'],
              ].map(([l, v, c]) => (
                <div key={l} className="bg-white px-4 py-3 dark:bg-slate-900">
                  <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500">{l}</p>
                  <p className={clsx('mt-0.5 text-sm font-semibold text-slate-900 dark:text-white', c)}>{v}</p>
                </div>
              ))}
            </div>
          ) : null
        }
      />
      <InvoiceDrawer id={viewId} onClose={() => setViewId(null)} />
      <RaiseInvoiceModal open={raiseOpen} onClose={() => setRaiseOpen(false)} onDone={(id) => setViewId(id)} />
      <ConcessionModal
        open={!!concession}
        onClose={() => setConcession(null)}
        invoice={concession ? { id: Number(concession.id), invoice_no: String(concession.invoice_no), balance_amount: concession.balance_amount, student_name: String(concession.student_name) } : null}
        onDone={refresh}
      />
      <ReasonDialog
        open={!!cancelRow}
        onClose={() => setCancelRow(null)}
        danger
        title={`Cancel invoice ${cancelRow?.invoice_no ?? ''}?`}
        description="Only invoices without any money received can be cancelled. Void or refund payments first if needed."
        label="Reason for cancelling"
        placeholder="e.g. Raised twice by mistake"
        confirmText="Cancel invoice"
        onSubmit={async (reason) => {
          const res = await api.post(`fees/invoices/${cancelRow?.id}/cancel`, { reason });
          toast.success(res.message);
          await refresh();
        }}
      />
    </>
  );
}

interface LineRow { fee_head_id: string; amount: string }

function RaiseInvoiceModal({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: (id: number) => void }) {
  const toast = useToast();
  const refresh = useFeesRefresh();
  const { data: heads = [] } = useLookup('fee_heads');
  const [studentId, setStudentId] = useState<number | null>(null);
  const [title, setTitle] = useState('');
  const [due, setDue] = useState('');
  const [remarks, setRemarks] = useState('');
  const [lines, setLines] = useState<LineRow[]>([{ fee_head_id: '', amount: '' }]);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (open) {
      setStudentId(null);
      setTitle('');
      setDue(isoDate(new Date(Date.now() + 15 * 86400000)));
      setRemarks('');
      setLines([{ fee_head_id: '', amount: '' }]);
      setErrors({});
      setFormError(null);
    }
  }, [open]);
  const total = lines.reduce((a, l) => a + toNumber(l.amount), 0);
  const submit = async () => {
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.post<{ id: number }>('fees/invoices', { student_id: studentId, title, due_date: due, remarks, items: lines });
      toast.success(res.message);
      await refresh();
      onClose();
      onDone(res.data.id);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      setFormError(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      static={saving}
      size="lg"
      title="Raise invoice"
      description="Bill a student for one-off charges — re-examination, library fine, ID card, add-on certification…"
      footer={
        <>
          <span className="mr-auto text-sm text-slate-600 dark:text-slate-300">Total <strong className="tabular-nums text-slate-900 dark:text-white">{formatMoney(total)}</strong></span>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button onClick={submit} loading={saving} icon={FilePlus2}>Raise invoice</Button>
        </>
      }
    >
      <div className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        <Field htmlFor="inv-student" label="Student" required error={errors.student_id}>
          <Combobox id="inv-student" source="students" value={studentId} onChange={(v) => setStudentId(v ? Number(v) : null)} placeholder="Search by name, student ID, roll no or mobile…" invalid={!!errors.student_id} />
        </Field>
        <div className="grid gap-4 sm:grid-cols-3">
          <Field htmlFor="inv-invoice-title" label="Invoice title" required error={errors.title} className="sm:col-span-2">
            <Input id="inv-invoice-title" value={title} maxLength={190} placeholder="e.g. Re-examination fee — Data Structures" invalid={!!errors.title} onChange={(e) => setTitle(e.target.value)} />
          </Field>
          <Field htmlFor="inv-due-date" label="Due date" required error={errors.due_date}>
            <Input id="inv-due-date" type="date" value={due} invalid={!!errors.due_date} onChange={(e) => setDue(e.target.value)} />
          </Field>
        </div>
        <div>
          <div className="mb-2 flex items-center justify-between">
            <span className="form-label !mb-0">Fee heads <span className="text-red-500">*</span></span>
            <Button size="xs" variant="soft" icon={Plus} onClick={() => setLines((l) => [...l, { fee_head_id: '', amount: '' }])}>Add line</Button>
          </div>
          {errors.items && <p className="form-error mb-2">{errors.items}</p>}
          <div className="space-y-2">
            {lines.map((l, k) => (
              <div key={k} className="grid grid-cols-12 items-start gap-2">
                <Field error={errors[`items.${k}.fee_head_id`]} className="col-span-12 sm:col-span-7">
                  <Select aria-label="Fee head" value={l.fee_head_id} invalid={!!errors[`items.${k}.fee_head_id`]} options={heads} placeholder="Select fee head" onChange={(e) => setLines((x) => x.map((r, i) => (i === k ? { ...r, fee_head_id: e.target.value } : r)))} />
                </Field>
                <Field error={errors[`items.${k}.amount`]} className="col-span-10 sm:col-span-4">
                  <Input aria-label="Amount" type="number" min={1} step="1" prefix="₹" value={l.amount} invalid={!!errors[`items.${k}.amount`]} onChange={(e) => setLines((x) => x.map((r, i) => (i === k ? { ...r, amount: e.target.value } : r)))} />
                </Field>
                <div className="col-span-2 flex h-[42px] items-center justify-end sm:col-span-1">
                  <button type="button" className="btn-icon !h-8 !w-8" aria-label="Remove line" disabled={lines.length === 1} onClick={() => setLines((x) => x.filter((_, i) => i !== k))}><Trash2 className="h-4 w-4" /></button>
                </div>
              </div>
            ))}
          </div>
        </div>
        <Field htmlFor="inv-remarks" label="Remarks" error={errors.remarks}>
          <Textarea id="inv-remarks" rows={2} maxLength={255} value={remarks} onChange={(e) => setRemarks(e.target.value)} />
        </Field>
      </div>
    </Modal>
  );
}
