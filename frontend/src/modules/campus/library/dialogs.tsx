import { useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import clsx from 'clsx';
import { CheckCircle2, IndianRupee, Printer, Receipt } from 'lucide-react';
import { Alert, Button, Field, Input, Modal, PageLoader, RadioGroup, Select, Skeleton, Textarea, Toggle, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { formatDate, formatMoney, isoDate } from '@/lib/format';
import { useAuth } from '@/lib/auth';
import { BookCover, LoanStateBadge, openPrint, useLibraryRefresh } from './shared';
import type { FinesSummary, Loan, LoanPreview, RulesPayload } from './types';

type FineAction = 'collect' | 'pending' | 'waive';

/* ---------------------------------------------------------------- Settle-fine block (shared by return / lost) */

function SettleFine({
  total, action, setAction, mode, setMode, reason, setReason, modes, canWaive, errors,
}: {
  total: number; action: FineAction; setAction: (a: FineAction) => void; mode: string; setMode: (m: string) => void; reason: string; setReason: (r: string) => void;
  modes: { value: string; label: string }[]; canWaive: boolean; errors: Record<string, string>;
}) {
  if (total <= 0) {
    return (
      <p className="flex items-center gap-2 rounded-xl bg-emerald-50 px-3 py-2.5 text-sm font-medium text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">
        <CheckCircle2 className="h-4 w-4" /> No fine is due.
      </p>
    );
  }
  const options = [
    { value: 'collect', label: 'Collect now' },
    { value: 'pending', label: 'Add to dues' },
    ...(canWaive ? [{ value: 'waive', label: 'Waive' }] : []),
  ];
  return (
    <div className="space-y-3 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
      <Field label="Settle the fine" error={errors.fine_action}>
        <RadioGroup name="fine_action" value={action} onChange={(v) => setAction(v as FineAction)} options={options} />
      </Field>
      {action === 'collect' && (
        <Field label="Payment mode" required htmlFor="lib-pay-mode" error={errors.payment_mode}>
          <Select id="lib-pay-mode" value={mode} onChange={(e) => setMode(e.target.value)} options={modes} invalid={!!errors.payment_mode} />
        </Field>
      )}
      {action === 'waive' && (
        <Field label="Reason for waiver" required htmlFor="lib-waive-reason" error={errors.waive_reason}>
          <Input id="lib-waive-reason" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Medical certificate submitted" invalid={!!errors.waive_reason} maxLength={255} />
        </Field>
      )}
      {action === 'pending' && <p className="text-xs text-slate-500 dark:text-slate-400">The fine is added to the member&apos;s unpaid dues and can be collected later from the Fines screen.</p>}
    </div>
  );
}

function LoanHeader({ loan }: { loan: Loan }) {
  return (
    <div className="flex items-start gap-3 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
      <BookCover title={loan.title} src={loan.cover_image} size="md" />
      <div className="min-w-0 flex-1 text-sm">
        <p className="line-clamp-2 font-semibold text-slate-900 dark:text-white">{loan.title}</p>
        <p className="truncate text-xs text-slate-500 dark:text-slate-400">
          <span className="font-mono">{loan.accession_no}</span> · {loan.member.name} ({loan.member.membership_no})
        </p>
        <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-600 dark:text-slate-300">
          <span>Issued {formatDate(loan.issue_date)}</span>
          <span>Due {formatDate(loan.due_date)}</span>
          <LoanStateBadge state={loan.state} />
        </div>
      </div>
    </div>
  );
}

function Line({ label, value, strong, tone }: { label: ReactNode; value: ReactNode; strong?: boolean; tone?: string }) {
  return (
    <div className={clsx('flex items-center justify-between gap-3 text-sm', strong && 'border-t border-slate-200 pt-2 font-semibold dark:border-slate-700')}>
      <span className="text-slate-600 dark:text-slate-300">{label}</span>
      <span className={clsx('tabular-nums text-slate-900 dark:text-white', tone)}>{value}</span>
    </div>
  );
}

function DoneState({ title, message, receipt, onClose }: { title: string; message: ReactNode; receipt?: string | null; onClose: () => void }) {
  return (
    <div className="flex flex-col items-center py-4 text-center">
      <span className="inline-flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 ring-8 ring-emerald-50 dark:bg-emerald-500/15 dark:text-emerald-300 dark:ring-emerald-500/5">
        <CheckCircle2 className="h-7 w-7" />
      </span>
      <h3 className="mt-4 font-display text-base font-semibold text-slate-900 dark:text-white">{title}</h3>
      <p className="mt-1 max-w-sm text-sm text-slate-500 dark:text-slate-400">{message}</p>
      <div className="mt-5 flex flex-wrap justify-center gap-2">
        {receipt && (
          <Button variant="secondary" icon={Printer} onClick={() => openPrint('receipt', { no: receipt })}>
            Print receipt
          </Button>
        )}
        <Button onClick={onClose}>Done</Button>
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- Return */

export function ReturnDialog({ loanId, onClose, onDone }: { loanId: number | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const today = isoDate();
  const [date, setDate] = useState(today);
  const [condition, setCondition] = useState<'good' | 'damaged'>('good');
  const [damage, setDamage] = useState('');
  const [action, setAction] = useState<FineAction>('collect');
  const [mode, setMode] = useState('cash');
  const [reason, setReason] = useState('');
  const [remarks, setRemarks] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState<{ message: string; receipt: string | null } | null>(null);
  const dDamage = useDebounce(damage, 300);

  useEffect(() => {
    if (loanId) {
      setDate(today);
      setCondition('good');
      setDamage('');
      setAction('collect');
      setMode('cash');
      setReason('');
      setRemarks('');
      setErrors({});
      setFormError(null);
      setDone(null);
    }
  }, [loanId, today]);

  const q = useApi<LoanPreview>(['library', 'loan-preview', loanId, date, condition, dDamage], `library/loans/${loanId}/preview`, { return_date: date, condition, damage_charge: dDamage || undefined }, {
    enabled: !!loanId,
    placeholderData: (prev) => prev,
  });
  const p = q.data;
  const total = p?.return.total ?? 0;

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!loanId) return;
    setSaving(true);
    setErrors({});
    setFormError(null);
    try {
      const res = await api.post<{ fine: { total: number; receipt_no: string | null; action: string } }>(`library/loans/${loanId}/return`, {
        return_date: date, condition, damage_charge: condition === 'damaged' ? damage || 0 : 0, fine_action: action, payment_mode: mode, waive_reason: reason, remarks,
      });
      toast.success(res.message);
      await refresh();
      onDone?.();
      setDone({ message: res.message, receipt: res.data.fine.receipt_no });
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!loanId}
      onClose={onClose}
      static={saving}
      size="md"
      title={done ? 'Book returned' : 'Return book'}
      description={done ? undefined : 'Check the copy, confirm the return date and settle any fine.'}
      footer={
        done ? undefined : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" form="lib-return-form" loading={saving} disabled={!p}>
              {total > 0 && action === 'collect' ? `Return & collect ${formatMoney(total, 2)}` : 'Confirm return'}
            </Button>
          </>
        )
      }
    >
      {done ? (
        <DoneState title="Return recorded" message={done.message} receipt={done.receipt} onClose={onClose} />
      ) : !p ? (
        q.error ? <Alert variant="error">{(q.error as ApiError).message}</Alert> : <PageLoader label="Loading loan…" />
      ) : (
        <form id="lib-return-form" onSubmit={submit} noValidate className="space-y-4">
          {formError && <Alert variant="error">{formError}</Alert>}
          <LoanHeader loan={p.loan} />
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Field label="Return date" required htmlFor="lib-return-date" error={errors.return_date}>
              <Input id="lib-return-date" type="date" value={date} max={today} min={p.loan.issue_date} onChange={(e) => setDate(e.target.value || today)} invalid={!!errors.return_date} />
            </Field>
            <Field label="Condition of the copy" error={errors.condition}>
              <RadioGroup name="condition" value={condition} onChange={(v) => setCondition(v as 'good' | 'damaged')} options={[{ value: 'good', label: 'Good' }, { value: 'damaged', label: 'Damaged' }]} />
            </Field>
            {condition === 'damaged' && (
              <Field label="Damage charge (₹)" htmlFor="lib-damage" error={errors.damage_charge} hint={p.loan.price ? `Copy price ${formatMoney(p.loan.price)}` : undefined} className="sm:col-span-2">
                <Input id="lib-damage" type="number" min={0} step="1" value={damage} onChange={(e) => setDamage(e.target.value)} placeholder="0" invalid={!!errors.damage_charge} />
              </Field>
            )}
          </div>
          <div className={clsx('space-y-1.5 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60', q.isFetching && 'opacity-70')}>
            <Line
              label={p.return.late.days_late > 0 ? `Overdue fine · ${p.return.late.days} day${p.return.late.days === 1 ? '' : 's'} × ${formatMoney(p.rules.fine_per_day)}` : 'Overdue fine'}
              value={formatMoney(p.return.late.amount, 2)}
            />
            {p.return.late.days_late > p.return.late.days && <p className="text-[11px] text-slate-500">{p.return.late.days_late - p.return.late.days} grace day(s) not charged.</p>}
            {condition === 'damaged' && <Line label="Damage charge" value={formatMoney(p.return.damage, 2)} />}
            <Line label="Total fine" value={formatMoney(total, 2)} strong tone={total > 0 ? 'text-red-600 dark:text-red-400' : undefined} />
          </div>
          <SettleFine total={total} action={action} setAction={setAction} mode={mode} setMode={setMode} reason={reason} setReason={setReason} modes={p.payment_modes} canWaive={p.can_waive} errors={errors} />
          <Field label="Remarks" htmlFor="lib-return-remarks">
            <Input id="lib-return-remarks" value={remarks} onChange={(e) => setRemarks(e.target.value)} maxLength={255} placeholder="Optional note" />
          </Field>
        </form>
      )}
    </Modal>
  );
}

/* ---------------------------------------------------------------- Lost */

export function LostDialog({ loanId, onClose, onDone }: { loanId: number | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const [amount, setAmount] = useState('');
  const [action, setAction] = useState<FineAction>('collect');
  const [mode, setMode] = useState('cash');
  const [reason, setReason] = useState('');
  const [remarks, setRemarks] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [done, setDone] = useState<{ message: string; receipt: string | null } | null>(null);
  const q = useApi<LoanPreview>(['library', 'loan-preview', loanId, 'lost'], `library/loans/${loanId}/preview`, {}, { enabled: !!loanId });
  const p = q.data;

  useEffect(() => {
    if (loanId) {
      setAction('collect');
      setMode('cash');
      setReason('');
      setRemarks('');
      setErrors({});
      setFormError(null);
      setDone(null);
    }
  }, [loanId]);
  useEffect(() => {
    if (p && loanId) setAmount(String(p.lost.default_amount));
  }, [p, loanId]);

  const lostAmount = Number(amount) || 0;
  const total = lostAmount + (p?.lost.late.amount ?? 0);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!loanId) return;
    setSaving(true);
    setErrors({});
    setFormError(null);
    try {
      const res = await api.post<{ fine: { receipt_no: string | null } }>(`library/loans/${loanId}/lost`, { amount, fine_action: action, payment_mode: mode, waive_reason: reason, remarks });
      toast.success(res.message);
      await refresh();
      onDone?.();
      setDone({ message: res.message, receipt: res.data.fine.receipt_no });
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!loanId}
      onClose={onClose}
      static={saving}
      size="md"
      title={done ? 'Book marked lost' : 'Report lost book'}
      description={done ? undefined : 'The copy is removed from circulation and the member is charged its replacement cost.'}
      footer={
        done ? undefined : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" variant="danger" form="lib-lost-form" loading={saving} disabled={!p}>
              Mark as lost
            </Button>
          </>
        )
      }
    >
      {done ? (
        <DoneState title="Lost book recorded" message={done.message} receipt={done.receipt} onClose={onClose} />
      ) : !p ? (
        q.error ? <Alert variant="error">{(q.error as ApiError).message}</Alert> : <PageLoader label="Loading loan…" />
      ) : (
        <form id="lib-lost-form" onSubmit={submit} noValidate className="space-y-4">
          {formError && <Alert variant="error">{formError}</Alert>}
          <LoanHeader loan={p.loan} />
          <Field
            label="Lost-book fine (₹)"
            required
            htmlFor="lib-lost-amount"
            error={errors.amount}
            hint={
              p.lost.processing_fee > 0
                ? `Copy price ${formatMoney(p.lost.price)} + processing fee ${formatMoney(p.lost.processing_fee)}`
                : `Defaults to the copy price (${formatMoney(p.lost.price)}). Adjust if a replacement copy is supplied.`
            }
          >
            <Input id="lib-lost-amount" type="number" min={0} step="1" value={amount} onChange={(e) => setAmount(e.target.value)} invalid={!!errors.amount} />
          </Field>
          <div className="space-y-1.5 rounded-xl bg-slate-50 p-3 dark:bg-slate-800/60">
            <Line label="Replacement cost" value={formatMoney(lostAmount, 2)} />
            <Line label={p.lost.late.days_late > 0 ? `Overdue fine · ${p.lost.late.days} day(s)` : 'Overdue fine'} value={formatMoney(p.lost.late.amount, 2)} />
            <Line label="Total fine" value={formatMoney(total, 2)} strong tone="text-red-600 dark:text-red-400" />
          </div>
          <SettleFine total={total} action={action} setAction={setAction} mode={mode} setMode={setMode} reason={reason} setReason={setReason} modes={p.payment_modes} canWaive={p.can_waive} errors={errors} />
          <Field label="Remarks" htmlFor="lib-lost-remarks">
            <Input id="lib-lost-remarks" value={remarks} onChange={(e) => setRemarks(e.target.value)} maxLength={255} placeholder="e.g. Lost while travelling" />
          </Field>
        </form>
      )}
    </Modal>
  );
}

/* ---------------------------------------------------------------- Loan actions hook */

/** Return / renew / lost actions with their dialogs: const { actions, dialogs } = useLoanActions(); */
export function useLoanActions(onChanged?: () => void) {
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useLibraryRefresh();
  const { can } = useAuth();
  const [returnId, setReturnId] = useState<number | null>(null);
  const [lostId, setLostId] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);

  const renew = async (loan: Pick<Loan, 'id' | 'title' | 'due_date'>) => {
    const ok = await confirm({ title: 'Renew this loan?', message: <>Extend the due date of <strong>{loan.title}</strong> (currently {formatDate(loan.due_date)}) by one loan period?</>, confirmText: 'Renew' });
    if (!ok) return;
    setBusy(true);
    try {
      const res = await api.post(`library/loans/${loan.id}/renew`);
      toast.success(res.message);
      await refresh();
      onChanged?.();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };
  const remind = async (ids: number[]) => {
    setBusy(true);
    try {
      const res = await api.post('library/remind', { ids });
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(false);
    }
  };
  const canEdit = can('library', 'edit');
  return {
    busy,
    canEdit,
    openReturn: (l: Pick<Loan, 'id'>) => setReturnId(l.id),
    openLost: (l: Pick<Loan, 'id'>) => setLostId(l.id),
    renew,
    remind,
    dialogs: (
      <>
        <ReturnDialog loanId={returnId} onClose={() => setReturnId(null)} onDone={onChanged} />
        <LostDialog loanId={lostId} onClose={() => setLostId(null)} onDone={onChanged} />
      </>
    ),
  };
}

/* ---------------------------------------------------------------- Fines: collect / waive */

interface FineSelection {
  ids: number[];
  total: number;
  members: number;
}

export function CollectFinesDialog({ selection, onClose, onDone }: { selection: FineSelection | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const summary = useApi<FinesSummary>(['library', 'fines-summary'], 'library/fines/summary', undefined, { enabled: !!selection });
  const modes = summary.data?.payment_modes;
  const [mode, setMode] = useState('cash');
  const [remarks, setRemarks] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [receipts, setReceipts] = useState<{ receipt_no: string; member: string; amount: number; count: number }[] | null>(null);

  useEffect(() => {
    if (selection) {
      setMode('cash');
      setRemarks('');
      setErrors({});
      setFormError(null);
      setReceipts(null);
    }
  }, [selection]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!selection) return;
    setSaving(true);
    setErrors({});
    setFormError(null);
    try {
      const res = await api.post<{ receipts: { receipt_no: string; member: string; amount: number; count: number }[] }>('library/fines/collect', { ids: selection.ids, payment_mode: mode, remarks });
      toast.success(res.message);
      await refresh();
      onDone?.();
      setReceipts(res.data.receipts);
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!selection}
      onClose={onClose}
      static={saving}
      size="md"
      icon={<span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300"><IndianRupee className="h-5 w-5" /></span>}
      title={receipts ? 'Fine collected' : 'Collect fine'}
      description={receipts ? undefined : selection ? `${selection.ids.length} fine${selection.ids.length === 1 ? '' : 's'} · ${selection.members} member${selection.members === 1 ? '' : 's'} · one receipt per member` : undefined}
      footer={
        receipts ? (
          <Button onClick={onClose}>Done</Button>
        ) : (
          <>
            <Button variant="secondary" onClick={onClose} disabled={saving}>
              Cancel
            </Button>
            <Button type="submit" form="lib-collect-form" variant="success" loading={saving}>
              Collect {selection ? formatMoney(selection.total, 2) : ''}
            </Button>
          </>
        )
      }
    >
      {receipts ? (
        <ul className="space-y-2">
          {receipts.map((r) => (
            <li key={r.receipt_no} className="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-800">
              <div className="min-w-0">
                <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{r.member}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  <span className="font-mono">{r.receipt_no}</span> · {r.count} fine{r.count === 1 ? '' : 's'} · {formatMoney(r.amount, 2)}
                </p>
              </div>
              <Button size="sm" variant="secondary" icon={Receipt} onClick={() => openPrint('receipt', { no: r.receipt_no })}>
                Receipt
              </Button>
            </li>
          ))}
        </ul>
      ) : (
        <form id="lib-collect-form" onSubmit={submit} noValidate className="space-y-4">
          {formError && <Alert variant="error">{formError}</Alert>}
          <div className="rounded-xl bg-emerald-50 p-4 text-center dark:bg-emerald-500/10">
            <p className="text-xs font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Amount to collect</p>
            <p className="font-display text-3xl font-bold text-emerald-700 dark:text-emerald-300">{formatMoney(selection?.total ?? 0, 2)}</p>
          </div>
          <Field label="Payment mode" required htmlFor="lib-collect-mode" error={errors.payment_mode}>
            {modes ? (
              <Select id="lib-collect-mode" value={mode} onChange={(e) => setMode(e.target.value)} options={modes} invalid={!!errors.payment_mode} />
            ) : (
              <Skeleton className="h-10 w-full" />
            )}
          </Field>
          <Field label="Remarks" htmlFor="lib-collect-remarks">
            <Input id="lib-collect-remarks" value={remarks} onChange={(e) => setRemarks(e.target.value)} maxLength={255} placeholder="e.g. UPI ref 4321…" />
          </Field>
        </form>
      )}
    </Modal>
  );
}

export function WaiveFinesDialog({ selection, onClose, onDone }: { selection: FineSelection | null; onClose: () => void; onDone?: () => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const [reason, setReason] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (selection) {
      setReason('');
      setErrors({});
      setFormError(null);
    }
  }, [selection]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    if (!selection) return;
    if (reason.trim().length < 3) {
      setErrors({ reason: 'Enter the reason for waiving the fine.' });
      return;
    }
    setSaving(true);
    setFormError(null);
    try {
      const res = await api.post('library/fines/waive', { ids: selection.ids, reason });
      toast.success(res.message);
      await refresh();
      onDone?.();
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Modal
      open={!!selection}
      onClose={onClose}
      static={saving}
      size="md"
      title="Waive fine"
      description={selection ? `${selection.ids.length} fine${selection.ids.length === 1 ? '' : 's'} totalling ${formatMoney(selection.total, 2)} will be written off. This is recorded in the audit log.` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            Cancel
          </Button>
          <Button type="submit" form="lib-waive-form" variant="danger" loading={saving}>
            Waive fine
          </Button>
        </>
      }
    >
      <form id="lib-waive-form" onSubmit={submit} noValidate className="space-y-4">
        {formError && <Alert variant="error">{formError}</Alert>}
        <Field label="Reason" required htmlFor="lib-waive-reason2" error={errors.reason}>
          <Textarea id="lib-waive-reason2" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} invalid={!!errors.reason} maxLength={255} placeholder="e.g. Medical certificate submitted for the overdue period" />
        </Field>
      </form>
    </Modal>
  );
}

/* ---------------------------------------------------------------- Circulation rules */

export function RulesDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const refresh = useLibraryRefresh();
  const q = useApi<RulesPayload>(['library', 'rules'], 'library/rules', undefined, { enabled: open });
  const [values, setValues] = useState<Record<string, string | boolean>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const canManage = !!q.data?.can_manage;

  useEffect(() => {
    if (open && q.data) {
      const v: Record<string, string | boolean> = {};
      q.data.definitions.forEach((d) => (v[d.key] = d.type === 'bool' ? !!d.value : String(d.value)));
      setValues(v);
      setErrors({});
      setFormError(null);
    }
  }, [open, q.data]);

  const groups = useMemo(() => {
    const defs = q.data?.definitions ?? [];
    return [
      { title: 'Fines', keys: ['library_fine_per_day', 'library_grace_days', 'library_lost_processing_fee'] },
      { title: 'Borrowing', keys: ['library_max_renewals', 'library_max_unpaid_fine', 'library_block_on_overdue'] },
      { title: 'Defaults for new members', keys: ['library_max_books_student', 'library_loan_days_student', 'library_max_books_faculty', 'library_loan_days_faculty', 'library_max_books_staff', 'library_loan_days_staff'] },
    ].map((g) => ({ ...g, defs: g.keys.map((k) => defs.find((d) => d.key === k)).filter(Boolean) as RulesPayload['definitions'] }));
  }, [q.data]);

  const submit = async (e: FormEvent) => {
    e.preventDefault();
    setSaving(true);
    setErrors({});
    setFormError(null);
    try {
      const res = await api.put('library/rules', values);
      toast.success(res.message);
      await refresh();
      onClose();
    } catch (err) {
      const ex = err as ApiError;
      setErrors(ex.errors ?? {});
      setFormError(ex.message);
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
      title="Circulation rules"
      description={canManage ? 'Fines, renewals and default borrowing limits. Changes apply to new transactions immediately.' : 'Rules set by the library administrator (read-only).'}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>
            {canManage ? 'Cancel' : 'Close'}
          </Button>
          {canManage && (
            <Button type="submit" form="lib-rules-form" loading={saving}>
              Save rules
            </Button>
          )}
        </>
      }
    >
      {!q.data ? (
        q.error ? <Alert variant="error">{(q.error as ApiError).message}</Alert> : <PageLoader label="Loading rules…" />
      ) : (
        <form id="lib-rules-form" onSubmit={submit} noValidate className="space-y-5">
          {formError && <Alert variant="error">{formError}</Alert>}
          {groups.map((g) => (
            <fieldset key={g.title}>
              <legend className="text-xs font-semibold uppercase tracking-wider text-brand-700 dark:text-brand-300">{g.title}</legend>
              <div className="mt-2 grid grid-cols-1 gap-4 border-t border-slate-100 pt-3 sm:grid-cols-2 dark:border-slate-800">
                {g.defs.map((d) =>
                  d.type === 'bool' ? (
                    <div key={d.key} className="sm:col-span-2">
                      <Toggle checked={!!values[d.key]} onChange={(v) => setValues((s) => ({ ...s, [d.key]: v }))} label={d.label} disabled={!canManage} id={`rule-${d.key}`} />
                    </div>
                  ) : (
                    <Field key={d.key} label={d.label} htmlFor={`rule-${d.key}`} error={errors[d.key]} hint={`Allowed ${d.min}–${d.max}`}>
                      <Input
                        id={`rule-${d.key}`}
                        type="number"
                        min={d.min}
                        max={d.max}
                        step={d.type === 'money' ? '0.5' : '1'}
                        value={String(values[d.key] ?? '')}
                        onChange={(e) => setValues((s) => ({ ...s, [d.key]: e.target.value }))}
                        disabled={!canManage}
                        invalid={!!errors[d.key]}
                      />
                    </Field>
                  ),
                )}
              </div>
            </fieldset>
          ))}
          <p className="text-xs text-slate-500 dark:text-slate-400">Existing members keep their own limits; edit a member to change them individually.</p>
        </form>
      )}
    </Modal>
  );
}

