import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlarmClock, Banknote, BadgePercent, Building2, CalendarClock, CheckCircle2, CreditCard, FileText, Globe, HandCoins, Landmark, Mail, Printer, ReceiptText,
  RotateCcw, ScrollText, Search, Smartphone, UserRound, WalletCards, type LucideIcon,
} from 'lucide-react';
import {
  Alert, Avatar, Badge, Button, Card, CardHeader, Checkbox, Combobox, EmptyState, Field, Input, PageHeader, Reveal, Skeleton, StatusBadge, Textarea, Toggle,
  useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, isoDate, labelize, toNumber } from '@/lib/format';
import { AmountTile, INVOICE_STATUS, MODE_LABELS, ModeBadge, openPrint, useFeesRefresh } from './shared';
import type { CollectResult, StudentProfile } from './types';

const MODE_ICONS: Record<string, LucideIcon> = { cash: Banknote, upi: Smartphone, bank_transfer: Landmark, cheque: ScrollText, dd: Building2, card: CreditCard, online: Globe };
const REF_LABEL: Record<string, string> = {
  upi: 'UPI transaction ID (UTR)', bank_transfer: 'UTR / transaction reference', cheque: 'Cheque number', dd: 'Demand draft number', card: 'Card approval / transaction ID', online: 'Gateway transaction / payment ID',
};

type Invoice = StudentProfile['invoices'][number];

export default function FeeCollectionPage() {
  const [params, setParams] = useSearchParams();
  const studentId = params.get('student') ? Number(params.get('student')) : null;
  const [result, setResult] = useState<CollectResult | null>(null);
  const { data: picked } = useApi<StudentProfile>(['fees-profile', studentId], `fees/students/${studentId}/profile`, undefined, { enabled: !!studentId });
  const initialOptions = useMemo(() => (picked ? [{ value: picked.student.id, label: `${picked.student.name} (${picked.student.student_uid})` }] : []), [picked]);
  const setStudent = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('student', String(id));
    else next.delete('student');
    setParams(next, { replace: true });
    setResult(null);
  };

  return (
    <>
      <PageHeader
        title="Fee Collection"
        breadcrumbs={[{ label: 'Fees & Accounts', to: '/fees' }, { label: 'Fee Collection' }]}
        description="Counter collection: find the student, choose invoices or installments, record the payment and print the receipt."
        actions={
          <>
            <Button variant="secondary" icon={ReceiptText} to="/fees/payments">Payments</Button>
            <Button variant="secondary" icon={FileText} to="/fees/invoices">Invoices</Button>
          </>
        }
      />
      {result ? (
        <SuccessPanel result={result} onAnother={() => setStudent(null)} onSameStudent={() => setResult(null)} />
      ) : (
        <>
          <Card className="mb-6">
            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
              <span className="hidden h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 sm:inline-flex dark:bg-brand-500/15 dark:text-brand-200"><Search className="h-5 w-5" /></span>
              <div className="min-w-0 flex-1">
                <label htmlFor="collect-student" className="form-label">Student</label>
                <Combobox id="collect-student" optionsUrl="fees/students/search" initialOptions={initialOptions} value={studentId} onChange={(v) => setStudent(v ? Number(v) : null)} placeholder="Search by name, student ID, admission no, roll no or mobile…" />
              </div>
            </div>
          </Card>
          {studentId ? <CollectionDesk key={studentId} studentId={studentId} onDone={setResult} /> : <StartState />}
        </>
      )}
    </>
  );
}

function StartState() {
  return (
    <Card>
      <EmptyState
        icon={HandCoins}
        title="Find a student to start collecting"
        description="Search above to open the student's fee profile with every open invoice, installment and scholarship. Students with the highest balances are listed first when you open the search."
        action={<Button variant="secondary" icon={AlarmClock} to="/fees/reports?tab=defaulters">View defaulters</Button>}
      />
    </Card>
  );
}

function CollectionDesk({ studentId, onDone }: { studentId: number; onDone: (r: CollectResult) => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useFeesRefresh();
  const { data: profile, isLoading, error, refetch } = useApi<StudentProfile>(['fees-profile', studentId], `fees/students/${studentId}/profile`);
  const [alloc, setAlloc] = useState<Record<number, string>>({});
  const [form, setForm] = useState({ payment_date: isoDate(), mode: 'cash', reference_no: '', bank_name: '', cheque_date: '', remarks: '', discount_amount: '', discount_reason: '' });
  const [applyLate, setApplyLate] = useState(true);
  const [emailReceipt, setEmailReceipt] = useState(true);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);

  // Pre-select everything that is due now (installments past or on their due date).
  useEffect(() => {
    if (!profile) return;
    const init: Record<number, string> = {};
    profile.invoices.forEach((inv) => {
      if (toNumber(inv.due_now) > 0) init[inv.id] = String(Math.round(toNumber(inv.due_now) + (applyLate ? toNumber(inv.late_fee_due) : 0)));
    });
    setAlloc(init);
    const enabled = profile.enabled_modes;
    if (enabled.length && !enabled.includes(form.mode)) setForm((f) => ({ ...f, mode: enabled[0] }));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [profile]);

  const invoices = profile?.invoices ?? [];
  const selected = invoices.filter((i) => alloc[i.id] !== undefined);
  const lateTotal = applyLate ? selected.reduce((a, i) => a + toNumber(i.late_fee_due), 0) : 0;
  const total = selected.reduce((a, i) => a + toNumber(alloc[i.id]), 0);
  const discount = toNumber(form.discount_amount);
  const payableMax = (inv: Invoice) => toNumber(inv.balance_amount) + (applyLate ? toNumber(inv.late_fee_due) : 0);
  const anyLate = invoices.some((i) => toNumber(i.late_fee_due) > 0);
  const set = (k: keyof typeof form, v: string) => {
    setForm((f) => ({ ...f, [k]: v }));
    setErrors((e) => ({ ...e, [k]: '' }));
  };
  const toggleInvoice = (inv: Invoice, on: boolean) => {
    setAlloc((a) => {
      const n = { ...a };
      if (on) n[inv.id] = String(Math.round(toNumber(inv.due_now) > 0 ? toNumber(inv.due_now) + (applyLate ? toNumber(inv.late_fee_due) : 0) : payableMax(inv)));
      else delete n[inv.id];
      return n;
    });
    setErrors((e) => ({ ...e, allocations: '' }));
  };
  // A counter discount reduces what is payable on the selected invoices (first invoice first, like the server does):
  // trim any amount that would now exceed the discounted balance so the collection is not rejected.
  const setDiscount = (v: string) => {
    set('discount_amount', v);
    let remaining = Math.max(0, toNumber(v));
    if (remaining <= 0) return;
    const next = { ...alloc };
    let changed = false;
    selected.forEach((inv) => {
      const bal = payableMax(inv);
      const d = Math.min(remaining, bal);
      remaining -= d;
      const payable = Math.max(0, Math.floor((bal - d) * 100) / 100);
      if (toNumber(next[inv.id]) > payable) {
        next[inv.id] = String(payable);
        changed = true;
      }
    });
    if (changed) setAlloc(next);
  };
  const setAmount = (id: number, v: string) => {
    setAlloc((a) => ({ ...a, [id]: v }));
    setErrors((e) => ({ ...e, allocations: '' }));
  };

  const submit = async () => {
    if (!profile) return;
    const ok = await confirm({
      title: `Collect ${formatMoney(total, 2)}?`,
      message: (
        <>
          From <strong>{profile.student.name}</strong> ({profile.student.student_uid}) by <strong>{MODE_LABELS[form.mode]}</strong>
          {form.reference_no ? <> · ref {form.reference_no}</> : null} against {selected.length} invoice{selected.length === 1 ? '' : 's'}. A receipt will be generated.
        </>
      ),
      confirmText: 'Collect & generate receipt',
    });
    if (!ok) return;
    setSaving(true);
    try {
      const res = await api.post<CollectResult>('fees/collect', {
        student_id: studentId, ...form, apply_late_fee: applyLate && anyLate ? 1 : 0, email_receipt: emailReceipt && profile.student.email ? 1 : 0,
        allocations: selected.map((i) => ({ student_fee_id: i.id, amount: alloc[i.id] })),
      });
      toast.success(res.message);
      await refresh();
      onDone(res.data);
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  if (error) {
    return <Alert variant="error" title="Unable to load the fee profile" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>;
  }
  if (isLoading || !profile) {
    return (
      <div className="grid gap-6 lg:grid-cols-12">
        <div className="space-y-4 lg:col-span-8"><Skeleton className="h-40 rounded-2xl" /><Skeleton className="h-56 rounded-2xl" /></div>
        <Skeleton className="h-96 rounded-2xl lg:col-span-4" />
      </div>
    );
  }
  const s = profile.student;
  const t = profile.totals;
  const gateway = profile.gateway;
  const modes = profile.enabled_modes.length ? profile.enabled_modes : Object.keys(MODE_LABELS);

  return (
    <div className="grid gap-6 lg:grid-cols-12">
      <div className="space-y-6 lg:col-span-8">
        <Reveal>
          <Card>
            <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
              <Avatar name={s.name} src={s.photo} size="xl" />
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <Link to={`/students/${s.id}`} className="font-display text-lg font-bold text-slate-900 hover:text-brand-700 dark:text-white">{s.name}</Link>
                  <StatusBadge status={s.status} />
                  {!!Number(s.is_hosteller) && <Badge color="cyan">Hosteller</Badge>}
                  {!!Number(s.uses_transport) && <Badge color="blue">Transport</Badge>}
                </div>
                <p className="mt-0.5 text-sm text-slate-600 dark:text-slate-300">{s.student_uid}{s.roll_no ? ` · Roll ${s.roll_no}` : ''} · {s.program_short} · Semester {s.current_semester}{s.section_name ? ` · Section ${s.section_name}` : ''}</p>
                <p className="mt-0.5 text-xs text-slate-500">{s.father_name ? `Father: ${s.father_name} · ` : ''}{s.mobile ?? s.guardian_phone ?? 'No mobile'}{s.email ? ` · ${s.email}` : ''}</p>
                {profile.concessions.length > 0 && (
                  <div className="mt-2 flex flex-wrap gap-1.5">
                    {profile.concessions.map((c, i) => <Badge key={i} color={c.type === 'scholarship' ? 'navy' : 'amber'}><BadgePercent className="h-3 w-3" />{c.name ?? labelize(c.type)} {formatMoney(c.amount)}</Badge>)}
                  </div>
                )}
              </div>
              <Button size="sm" variant="secondary" icon={ScrollText} onClick={() => openPrint('fee-statement.php', { student_id: s.id })}>Statement</Button>
            </div>
            <div className="grid grid-cols-2 gap-2.5 border-t border-slate-100 p-4 sm:grid-cols-4 dark:border-slate-800">
              <AmountTile label="Total billed" value={formatMoney(t.net)} tone="blue" sub={`${t.invoices} invoice${t.invoices === 1 ? '' : 's'}`} />
              <AmountTile label="Paid" value={formatMoney(t.paid)} tone="green" />
              <AmountTile label="Balance" value={formatMoney(t.balance)} tone={t.balance > 0 ? 'amber' : 'slate'} />
              <AmountTile label="Overdue" value={formatMoney(t.overdue)} tone={t.overdue > 0 ? 'red' : 'slate'} />
            </div>
          </Card>
        </Reveal>

        <Reveal delay={60}>
          <Card>
            <CardHeader title="Open invoices" subtitle="Tick the invoices being paid and adjust the amount for partial payments" icon={FileText} />
            {errors.allocations && <Alert variant="error" className="mx-5 mt-4">{errors.allocations}</Alert>}
            {invoices.length === 0 ? (
              <EmptyState icon={CheckCircle2} title="No dues — all invoices are settled" description="This student has no open invoice. Print the fee statement for their records." action={<Button size="sm" variant="secondary" icon={ScrollText} onClick={() => openPrint('fee-statement.php', { student_id: s.id })}>Print statement</Button>} />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {invoices.map((inv) => {
                  const on = alloc[inv.id] !== undefined;
                  const max = payableMax(inv);
                  return (
                    <li key={inv.id} className={clsx('p-4 transition-colors sm:px-5', on && 'bg-brand-50/40 dark:bg-brand-500/5')}>
                      <div className="flex flex-col gap-3 sm:flex-row sm:items-start">
                        <div className="flex min-w-0 flex-1 gap-3">
                          <input type="checkbox" className="form-checkbox mt-1" checked={on} onChange={(e) => toggleInvoice(inv, e.target.checked)} aria-label={`Pay ${inv.invoice_no}`} />
                          <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                              <span className="font-semibold text-slate-900 dark:text-white">{inv.title}</span>
                              <StatusBadge status={inv.status} label={INVOICE_STATUS[inv.status]} />
                            </div>
                            <p className="text-xs text-slate-500">{inv.invoice_no} · net {formatMoney(inv.net_amount)} · paid {formatMoney(inv.paid_amount)}{inv.next_due ? ` · next due ${formatDate(inv.next_due.due_date)}` : inv.due_date ? ` · due ${formatDate(inv.due_date)}` : ''}</p>
                            {inv.installments.length > 0 && (
                              <div className="mt-2 flex flex-wrap gap-1.5">
                                {inv.installments.map((i) => (
                                  <span key={i.id} className={clsx('inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-medium',
                                    i.status === 'paid' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : i.status === 'overdue' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300')}>
                                    <CalendarClock className="h-3 w-3" />#{i.installment_no} {formatDate(i.due_date)} · {i.status === 'paid' ? 'paid' : `${formatMoney(toNumber(i.amount) - toNumber(i.paid_amount))} left`}
                                  </span>
                                ))}
                              </div>
                            )}
                            {toNumber(inv.late_fee_due) > 0 && <p className="mt-1.5 flex items-center gap-1 text-xs font-medium text-red-600 dark:text-red-400"><AlarmClock className="h-3.5 w-3.5" />Late fee accrued {formatMoney(inv.late_fee_due)}</p>}
                          </div>
                        </div>
                        <div className="flex shrink-0 flex-col gap-1.5 pl-7 sm:w-56 sm:pl-0">
                          <div className="flex items-baseline justify-between text-xs text-slate-500"><span>Balance</span><span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatMoney(inv.balance_amount)}</span></div>
                          <Input inputSize="sm" type="number" min={1} max={max} step="1" prefix="₹" value={on ? alloc[inv.id] : ''} placeholder="Amount" disabled={!on} aria-label={`Amount for ${inv.invoice_no}`} onChange={(e) => setAmount(inv.id, e.target.value)} />
                          {on && (
                            <div className="flex gap-1.5">
                              {toNumber(inv.due_now) > 0 && toNumber(inv.due_now) < toNumber(inv.balance_amount) && (
                                <button type="button" className="rounded-md bg-white px-2 py-0.5 text-[11px] font-medium text-brand-700 ring-1 ring-slate-200 hover:bg-brand-50 dark:bg-slate-800 dark:text-brand-300 dark:ring-slate-700" onClick={() => setAmount(inv.id, String(Math.round(toNumber(inv.due_now) + (applyLate ? toNumber(inv.late_fee_due) : 0))))}>Due now</button>
                              )}
                              <button type="button" className="rounded-md bg-white px-2 py-0.5 text-[11px] font-medium text-brand-700 ring-1 ring-slate-200 hover:bg-brand-50 dark:bg-slate-800 dark:text-brand-300 dark:ring-slate-700" onClick={() => setAmount(inv.id, String(Math.round(max)))}>Full {formatMoney(max)}</button>
                            </div>
                          )}
                        </div>
                      </div>
                    </li>
                  );
                })}
              </ul>
            )}
          </Card>
        </Reveal>

        {profile.recent_payments.length > 0 && (
          <Reveal delay={100}>
            <Card>
              <CardHeader title="Recent receipts" subtitle="Last five payments of this student" icon={ReceiptText} />
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {profile.recent_payments.map((p) => (
                  <li key={p.id} className="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                    <span className="min-w-0">
                      <button type="button" className="link" onClick={() => openPrint('fee-receipt.php', { id: p.id })}>{p.receipt_no}</button>
                      <span className="ml-2 text-xs text-slate-500">{formatDate(p.payment_date)}</span>
                    </span>
                    <span className="flex items-center gap-2">
                      <ModeBadge mode={p.mode} />
                      <span className={clsx('font-semibold tabular-nums', p.status === 'cancelled' && 'text-slate-400 line-through')}>{formatMoney(p.total)}</span>
                    </span>
                  </li>
                ))}
              </ul>
            </Card>
          </Reveal>
        )}
      </div>

      <div className="lg:col-span-4">
        <Card className="lg:sticky lg:top-20">
          <CardHeader title="Payment" subtitle="Record how the money was received" icon={WalletCards} />
          <div className="card-body space-y-4">
            <Field label="Payment mode" required error={errors.mode}>
              <div className="grid grid-cols-3 gap-1.5" role="radiogroup" aria-label="Payment mode">
                {modes.map((m) => {
                  const Icon = MODE_ICONS[m] ?? WalletCards;
                  const active = form.mode === m;
                  return (
                    <button key={m} type="button" role="radio" aria-checked={active} onClick={() => set('mode', m)}
                      className={clsx('flex flex-col items-center gap-1 rounded-xl border px-1 py-2 text-[11px] font-semibold transition',
                        active ? 'border-brand-700 bg-brand-50 text-brand-900 ring-2 ring-brand-500/20 dark:border-brand-400 dark:bg-brand-500/15 dark:text-white' : 'border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50 dark:border-slate-700 dark:text-slate-300 dark:hover:bg-slate-800')}>
                      <Icon className="h-4 w-4" />{MODE_LABELS[m] ?? m}
                    </button>
                  );
                })}
              </div>
            </Field>
            {form.mode === 'online' && (
              <Alert variant={gateway.configured ? 'info' : 'warning'} title={`Online gateway: ${gateway.label ?? 'not selected'}`}>
                {gateway.configured
                  ? `${gateway.label} is configured in ${gateway.mode} mode. Record the payment ID of a transaction the student completed online.`
                  : 'Gateway keys are not configured in Settings › Payments. Record only payments already confirmed by the gateway.'}
              </Alert>
            )}
            <div className="grid grid-cols-2 gap-3">
              <Field htmlFor="collect-payment-date" label="Payment date" required error={errors.payment_date} className="col-span-2 sm:col-span-1 lg:col-span-2 xl:col-span-1">
                <Input id="collect-payment-date" type="date" value={form.payment_date} max={isoDate()} invalid={!!errors.payment_date} onChange={(e) => set('payment_date', e.target.value)} />
              </Field>
              {form.mode !== 'cash' && (
                <Field htmlFor="collect-field" label={REF_LABEL[form.mode] ?? 'Reference'} required error={errors.reference_no} className="col-span-2 sm:col-span-1 lg:col-span-2 xl:col-span-1">
                  <Input id="collect-field" value={form.reference_no} maxLength={100} invalid={!!errors.reference_no} onChange={(e) => set('reference_no', e.target.value)} />
                </Field>
              )}
              {['cheque', 'dd'].includes(form.mode) && (
                <>
                  <Field htmlFor="collect-issuing-bank" label="Issuing bank" required error={errors.bank_name} className="col-span-2 sm:col-span-1 lg:col-span-2 xl:col-span-1">
                    <Input id="collect-issuing-bank" value={form.bank_name} maxLength={100} placeholder="e.g. State Bank of India" invalid={!!errors.bank_name} onChange={(e) => set('bank_name', e.target.value)} />
                  </Field>
                  <Field htmlFor="collect-field-2" label={form.mode === 'dd' ? 'DD date' : 'Cheque date'} error={errors.cheque_date} className="col-span-2 sm:col-span-1 lg:col-span-2 xl:col-span-1">
                    <Input id="collect-field-2" type="date" value={form.cheque_date} invalid={!!errors.cheque_date} onChange={(e) => set('cheque_date', e.target.value)} />
                  </Field>
                </>
              )}
            </div>
            {anyLate && (
              <Toggle checked={applyLate} onChange={(v) => {
                setApplyLate(v);
                setAlloc((a) => {
                  const n = { ...a };
                  invoices.forEach((i) => {
                    if (n[i.id] !== undefined && toNumber(i.late_fee_due) > 0) n[i.id] = String(Math.max(0, Math.round(toNumber(n[i.id]) + (v ? 1 : -1) * toNumber(i.late_fee_due))));
                  });
                  return n;
                });
              }} label="Charge accrued late fee" description={`${formatMoney(invoices.reduce((a, i) => a + toNumber(i.late_fee_due), 0))} as per the late fee rules`} />
            )}
            {can('fees', 'edit') && (
              <div className="grid grid-cols-5 gap-2">
                <Field htmlFor="collect-discount" label="Discount (₹)" error={errors.discount_amount} className="col-span-2">
                  <Input id="collect-discount" type="number" min={0} step="1" value={form.discount_amount} placeholder="0" invalid={!!errors.discount_amount} onChange={(e) => setDiscount(e.target.value)} />
                </Field>
                <Field htmlFor="collect-discount-reason" label="Discount reason" error={errors.discount_reason} className="col-span-3">
                  <Input id="collect-discount-reason" value={form.discount_reason} maxLength={200} placeholder={discount > 0 ? 'Required' : 'Approval reference'} disabled={!discount} invalid={!!errors.discount_reason} onChange={(e) => set('discount_reason', e.target.value)} />
                </Field>
              </div>
            )}
            <Field htmlFor="collect-remarks" label="Remarks" error={errors.remarks}>
              <Textarea id="collect-remarks" rows={2} maxLength={255} value={form.remarks} onChange={(e) => set('remarks', e.target.value)} />
            </Field>
            {s.email && <Checkbox label="Email the receipt" description={s.email} checked={emailReceipt} onChange={(e) => setEmailReceipt(e.target.checked)} />}

            <dl className="space-y-1.5 rounded-xl bg-slate-50 p-3 text-sm dark:bg-slate-800/60">
              <div className="flex justify-between"><dt className="text-slate-500">Invoices selected</dt><dd className="font-medium">{selected.length}</dd></div>
              {lateTotal > 0 && <div className="flex justify-between"><dt className="text-slate-500">Includes late fee</dt><dd className="tabular-nums">{formatMoney(lateTotal)}</dd></div>}
              {discount > 0 && <div className="flex justify-between"><dt className="text-slate-500">Discount on balance</dt><dd className="tabular-nums text-emerald-700 dark:text-emerald-400">− {formatMoney(discount)}</dd></div>}
              <div className="flex items-baseline justify-between border-t border-slate-200 pt-2 dark:border-slate-700">
                <dt className="font-semibold text-slate-900 dark:text-white">Amount to collect</dt>
                <dd className="font-display text-xl font-bold tabular-nums text-slate-900 dark:text-white">{formatMoney(total, 2)}</dd>
              </div>
            </dl>
            {(errors.allocations || errors.student_id) && <Alert variant="error">{errors.allocations || errors.student_id}</Alert>}
            <Button variant="success" size="lg" className="w-full" icon={HandCoins} loading={saving} disabled={!selected.length || total <= 0 || !can('fees', 'create')} onClick={submit}>
              Collect {total > 0 ? formatMoney(total) : ''}
            </Button>
            <p className="text-center text-xs text-slate-500 dark:text-slate-400">Receipt number is generated automatically ({MODE_LABELS[form.mode]}).</p>
          </div>
        </Card>
      </div>
    </div>
  );
}

function SuccessPanel({ result, onAnother, onSameStudent }: { result: CollectResult; onAnother: () => void; onSameStudent: () => void }) {
  const toast = useToast();
  const [emailing, setEmailing] = useState(false);
  const [emailed, setEmailed] = useState(result.emailed);
  useEffect(() => {
    document.getElementById('collect-success')?.focus();
  }, []);
  const email = async () => {
    setEmailing(true);
    try {
      const res = await api.post(`fees/payments/${result.id}/email`);
      toast.success(res.message);
      setEmailed(true);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setEmailing(false);
    }
  };
  const rows = useMemo(() => [
    ['Receipt no', result.receipt_no],
    ['Student', `${result.student.name} (${result.student.student_uid})`],
    ['Payment mode', result.mode_label],
    ['Payment date', formatDate(result.payment_date)],
    ...(result.fine_amount > 0 ? [['Late fee included', formatMoney(result.fine_amount, 2)]] : []),
    ...(result.discount_amount > 0 ? [['Discount given', formatMoney(result.discount_amount, 2)]] : []),
    ['Balance after payment', formatMoney(result.balance_after)],
  ], [result]);
  return (
    <Reveal>
      <Card className="mx-auto max-w-2xl overflow-hidden">
        <div id="collect-success" tabIndex={-1} className="bg-gradient-to-b from-emerald-50 to-white px-6 pb-6 pt-8 text-center outline-none dark:from-emerald-500/10 dark:to-slate-900">
          <span className="mx-auto inline-flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600 ring-8 ring-emerald-50 dark:bg-emerald-500/20 dark:text-emerald-300 dark:ring-emerald-500/5">
            <CheckCircle2 className="h-9 w-9" />
          </span>
          <p className="mt-4 text-sm font-medium text-emerald-700 dark:text-emerald-300">Payment received</p>
          <p className="font-display text-3xl font-bold tabular-nums text-slate-900 dark:text-white">{formatMoney(result.total, 2)}</p>
          <p className="mt-1 text-sm text-slate-500">Receipt <strong className="text-slate-800 dark:text-slate-100">{result.receipt_no}</strong>{emailed ? ' · emailed to the student' : ''}</p>
        </div>
        <dl className="divide-y divide-slate-100 border-t border-slate-100 dark:divide-slate-800 dark:border-slate-800">
          {rows.map(([l, v]) => (
            <div key={l} className="flex justify-between gap-4 px-6 py-2.5 text-sm"><dt className="text-slate-500">{l}</dt><dd className="text-right font-medium text-slate-900 dark:text-white">{v}</dd></div>
          ))}
        </dl>
        <div className="flex flex-wrap justify-center gap-2 border-t border-slate-100 p-5 dark:border-slate-800">
          <Button icon={Printer} onClick={() => openPrint('fee-receipt.php', { id: result.id })}>Print receipt</Button>
          {result.student.email && !emailed && <Button variant="secondary" icon={Mail} loading={emailing} onClick={email}>Email receipt</Button>}
          <Button variant="secondary" icon={UserRound} onClick={onSameStudent}>Same student</Button>
          <Button variant="success" icon={RotateCcw} onClick={onAnother}>Collect another</Button>
        </div>
      </Card>
    </Reveal>
  );
}
