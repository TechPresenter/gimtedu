import { useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlarmClock, BadgePercent, Banknote, Building2, CalendarClock, CircleCheck, Clock, Copy, CreditCard, FileText, IndianRupee, Mail, Phone, Printer, Receipt, ShieldCheck, Smartphone, Wallet,
} from 'lucide-react';
import { Alert, Stagger, Tabs, useToast } from '@/components/ui';
import { formatDate } from '@/lib/format';
import { EmptyBlock, ErrorState, KpiCard, KpiSkeleton, PageHead, Section } from '../../../components/Ui';
import { DateTile, ListSkeleton, PrintButton, StatusBadge, dueLabel, money, useStudent } from '../../shared/ui';
import type { Invoice, PayOptions, StudentFees } from '../../shared/types';
import { InvoiceDrawer } from './InvoiceDrawer';

function CopyRow({ label, value }: { label: string; value: string }) {
  const toast = useToast();
  const copy = () => {
    void navigator.clipboard?.writeText(value).then(
      () => toast.success(`${label} copied`),
      () => toast.error('Could not copy — select the text instead.'),
    );
  };
  return (
    <div className="flex items-center justify-between gap-3 py-2">
      <div className="min-w-0">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{label}</p>
        <p className="break-all font-mono text-sm font-semibold text-slate-900 dark:text-white">{value}</p>
      </div>
      <button type="button" onClick={copy} className="btn-icon h-8 w-8 shrink-0" aria-label={`Copy ${label}`}>
        <Copy className="h-4 w-4" aria-hidden />
      </button>
    </div>
  );
}

function PayCard({ pay, balance }: { pay: PayOptions; balance: number }) {
  const bankLabels: Record<string, string> = { account_name: 'Account name', bank_name: 'Bank', account_no: 'Account number', ifsc: 'IFSC', branch: 'Branch' };
  return (
    <Section title="How to pay" subtitle={balance > 0 ? `${money(balance)} outstanding` : 'No dues right now'} icon={CreditCard}>
      <div className="space-y-4">
        <div className="flex items-start gap-3 rounded-2xl border border-slate-200/80 p-3.5 dark:border-slate-800">
          <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-slate-100 text-slate-500 dark:bg-slate-800">
            <ShieldCheck className="h-[18px] w-[18px]" aria-hidden />
          </span>
          <div className="min-w-0 flex-1">
            <div className="flex flex-wrap items-center gap-2">
              <p className="text-sm font-semibold text-slate-900 dark:text-white">Pay online</p>
              <span className="badge badge-slate">Not available yet</span>
            </div>
            <p className="mt-1 text-[13px] text-slate-500 dark:text-slate-400">
              {pay.gateway.configured
                ? `The institute has set up ${pay.gateway.provider ?? 'a payment gateway'}, but paying from the student portal has not been switched on yet.`
                : `Online payment through the portal is not available yet${pay.gateway.provider ? ` (${pay.gateway.provider} is not configured)` : ''}.`}{' '}
              Please use one of the options below. Your receipt appears here as soon as the accounts office records the payment.
            </p>
          </div>
        </div>

        {(pay.bank || pay.upi_id) && (
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            {pay.bank && (
              <div className="rounded-2xl bg-slate-50 px-3.5 py-2 dark:bg-slate-800/40">
                <p className="flex items-center gap-2 pt-1.5 text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                  <Building2 className="h-3.5 w-3.5" aria-hidden /> Bank transfer (NEFT / IMPS)
                </p>
                <div className="divide-y divide-slate-200/70 dark:divide-slate-700/60">
                  {Object.entries(pay.bank).map(([k, v]) => (
                    <CopyRow key={k} label={bankLabels[k] ?? k} value={String(v)} />
                  ))}
                </div>
              </div>
            )}
            {pay.upi_id && (
              <div className="rounded-2xl bg-slate-50 px-3.5 py-2 dark:bg-slate-800/40">
                <p className="flex items-center gap-2 pt-1.5 text-xs font-bold uppercase tracking-[0.14em] text-slate-500">
                  <Smartphone className="h-3.5 w-3.5" aria-hidden /> UPI
                </p>
                <CopyRow label="UPI ID" value={pay.upi_id} />
                <p className="pb-2 text-[11px] text-slate-500">Write your Student ID and invoice number in the payment note.</p>
              </div>
            )}
          </div>
        )}

        <div className="rounded-2xl bg-brand-50/70 p-3.5 text-[13px] text-slate-700 dark:bg-brand-500/10 dark:text-slate-200">
          <p className="flex items-center gap-2 font-semibold text-brand-900 dark:text-white">
            <Banknote className="h-4 w-4" aria-hidden /> At the accounts office
          </p>
          <p className="mt-1 text-slate-600 dark:text-slate-300">
            {pay.modes
              .filter((m) => m.key !== 'online')
              .map((m) => m.label)
              .join(' · ')}
          </p>
          <p className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
            <span className="inline-flex items-center gap-1">
              <Clock className="h-3.5 w-3.5" aria-hidden /> {pay.office.hours}
            </span>
            {pay.office.phone && (
              <a href={`tel:${pay.office.phone.replace(/[^\d+]/g, '')}`} className="inline-flex items-center gap-1 hover:text-flame-700">
                <Phone className="h-3.5 w-3.5" aria-hidden /> {pay.office.phone}
              </a>
            )}
            {pay.office.email && (
              <a href={`mailto:${pay.office.email}`} className="inline-flex items-center gap-1 hover:text-flame-700">
                <Mail className="h-3.5 w-3.5" aria-hidden /> {pay.office.email}
              </a>
            )}
          </p>
          {!pay.bank && !pay.upi_id && <p className="mt-2 text-xs text-slate-500">Bank / UPI details for direct transfer are shared by the accounts office on request.</p>}
        </div>
        {pay.late_fee_per_day > 0 && <p className="text-xs text-slate-500">A late fee of {money(pay.late_fee_per_day)} per day applies after a due date (as per the fee structure).</p>}
      </div>
    </Section>
  );
}

function InvoiceCard({ inv, onOpen }: { inv: Invoice; onOpen: () => void }) {
  const paidPct = inv.net > 0 ? Math.min(100, Math.round((inv.paid / inv.net) * 100)) : 100;
  return (
    <article className={clsx('card flex h-full flex-col p-4 sm:p-5', inv.status === 'cancelled' && 'opacity-70')}>
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <p className="text-[11px] font-bold uppercase tracking-[0.12em] text-slate-400">
            {inv.invoice_no} · {inv.session ?? ''}
          </p>
          <h3 className="mt-1 font-display text-[15px] font-bold leading-snug text-slate-900 dark:text-white">{inv.title}</h3>
        </div>
        <StatusBadge status={inv.overdue > 0 ? 'overdue' : inv.status} />
      </div>
      <div className="mt-4 flex items-end justify-between gap-3">
        <div>
          <p className="text-[11px] font-semibold uppercase tracking-wider text-slate-400">{inv.open ? 'Balance due' : 'Total'}</p>
          <p className="font-display text-2xl font-extrabold text-slate-900 dark:text-white">{money(inv.open ? inv.balance : inv.net)}</p>
        </div>
        <p className="text-right text-xs text-slate-500">
          {money(inv.paid)} paid of {money(inv.net)}
          {inv.open && inv.due_date && (
            <span className={clsx('block font-semibold', inv.overdue > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-600 dark:text-slate-300')}>
              {inv.overdue > 0 ? `${money(inv.overdue)} overdue` : `Due ${formatDate(inv.due_date)}`}
            </span>
          )}
        </p>
      </div>
      <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
        <div className={clsx('h-full rounded-full', paidPct >= 100 ? 'bg-emerald-500' : inv.overdue > 0 ? 'bg-red-500' : 'bg-flame-500')} style={{ width: `${paidPct}%` }} />
      </div>
      {inv.installments.length > 0 && (
        <ol className="mt-3 flex flex-wrap gap-1.5" aria-label="Installments">
          {inv.installments.map((i) => (
            <li key={i.no} className={clsx('rounded-lg px-2 py-1 text-[11px] font-semibold', i.status === 'paid' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' : i.status === 'overdue' ? 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300' : 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300')}>
              #{i.no} · {money(i.amount)} · {formatDate(i.due_date)}
            </li>
          ))}
        </ol>
      )}
      {inv.late_fee_due > 0 && <p className="mt-2 text-xs font-medium text-red-600 dark:text-red-400">Late fee accrued so far: {money(inv.late_fee_due)}</p>}
      <div className="mt-auto flex flex-wrap gap-2 pt-4">
        <button type="button" onClick={onOpen} className="btn btn-navy btn-sm">
          <FileText className="h-4 w-4" aria-hidden /> Details
        </button>
        <PrintButton path={`invoice/${inv.id}`}>Invoice</PrintButton>
      </div>
    </article>
  );
}

export default function StudentFeesPage() {
  const { data, isLoading, error, refetch } = useStudent<StudentFees>(['fees'], 'fees');
  const [params, setParams] = useSearchParams();
  const openId = params.get('invoice') ? Number(params.get('invoice')) : null;
  const [tab, setTab] = useState<'invoices' | 'payments'>('invoices');
  const open = (id: number | null) => {
    const p = new URLSearchParams(params);
    if (id) p.set('invoice', String(id));
    else p.delete('invoice');
    setParams(p, { replace: true });
  };
  const t = data?.totals;
  const overdueParts = data?.schedule.filter((p) => p.overdue) ?? [];

  return (
    <div>
      <PageHead area="student" title="Fees & Payments" subtitle="Invoices, installments, receipts and how to pay." crumbs={[{ label: 'Dashboard', to: '/student' }, { label: 'Fees & Payments' }]} />
      {error ? (
        <ErrorState error={error} onRetry={() => void refetch()} />
      ) : isLoading || !data || !t ? (
        <div className="space-y-5">
          <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
            {[0, 1, 2, 3].map((i) => (
              <KpiSkeleton key={i} />
            ))}
          </div>
          <ListSkeleton rows={3} h="h-40" />
        </div>
      ) : (
        <div className="space-y-5">
          <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4" itemClassName="h-full">
            {[
              <KpiCard key="bal" label="Balance due" icon={IndianRupee} tone={t.overdue > 0 ? 'red' : t.balance > 0 ? 'amber' : 'green'} value={money(t.balance)} badge={t.balance > 0 ? undefined : <span className="badge badge-green">All dues cleared</span>} foot={`${t.invoices} invoice${t.invoices === 1 ? '' : 's'}`} />,
              <KpiCard key="paid" label="Paid so far" icon={CircleCheck} tone="green" value={money(t.paid)} foot={`of ${money(t.net)} billed`} />,
              <KpiCard key="over" label="Overdue" icon={AlarmClock} tone={t.overdue > 0 ? 'red' : 'navy'} value={money(t.overdue)} foot={t.overdue > 0 ? 'Late fee applies until paid' : 'Nothing overdue'} />,
              <KpiCard key="conc" label="Scholarships & concessions" icon={BadgePercent} tone="violet" value={money(t.concessions)} foot={data.concessions.length ? `${data.concessions.length} applied` : 'None applied'} />,
            ]}
          </Stagger>

          {overdueParts.length > 0 ? (
            <Alert variant="error" title={`${money(overdueParts.reduce((a, p) => a + p.amount, 0))} is overdue`}>
              {overdueParts.map((p) => `${p.invoice_no}${p.installment_no ? ` (installment ${p.installment_no})` : ''} was due on ${formatDate(p.due_date)}`).join('; ')}. Please pay at the earliest to avoid further late fee.
            </Alert>
          ) : data.next_due ? (
            <Alert variant="warning" title={`Next due: ${money(data.next_due.amount)} on ${formatDate(data.next_due.due_date)}`}>
              {data.next_due.title} ({data.next_due.invoice_no}
              {data.next_due.installment_no ? `, installment ${data.next_due.installment_no}` : ''}) · {dueLabel(data.next_due.days_left)}
            </Alert>
          ) : null}

          <div className="grid grid-cols-1 gap-5 xl:grid-cols-5">
            <div className="space-y-4 xl:col-span-3">
              <Tabs
                variant="pills"
                value={tab}
                onChange={(k) => setTab(k as 'invoices' | 'payments')}
                tabs={[
                  { key: 'invoices', label: 'Invoices', icon: FileText, count: data.invoices.length },
                  { key: 'payments', label: 'Payments & receipts', icon: Receipt, count: data.payments.length },
                ]}
              />
              {tab === 'invoices' ? (
                data.invoices.length === 0 ? (
                  <div className="card">
                    <EmptyBlock icon={Wallet} title="No fee invoices yet" text="Invoices raised by the accounts office will appear here." />
                  </div>
                ) : (
                  <Stagger className="grid grid-cols-1 gap-4 md:grid-cols-2" itemClassName="h-full">
                    {data.invoices.map((inv) => (
                      <InvoiceCard key={inv.id} inv={inv} onOpen={() => open(inv.id)} />
                    ))}
                  </Stagger>
                )
              ) : data.payments.length === 0 ? (
                <div className="card">
                  <EmptyBlock icon={Receipt} title="No payments recorded yet" text="Receipts for fees you pay will be listed here." />
                </div>
              ) : (
                <section className="card overflow-hidden">
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800 md:hidden">
                    {data.payments.map((p) => (
                      <li key={p.id} className="flex items-center gap-3 p-3.5">
                        <DateTile date={p.date} tone={p.status === 'cancelled' ? 'muted' : 'navy'} />
                        <div className="min-w-0 flex-1">
                          <p className={clsx('font-semibold tabular-nums text-slate-900 dark:text-white', p.status === 'cancelled' && 'line-through opacity-60')}>{money(p.total)}</p>
                          <p className="truncate text-xs text-slate-500">
                            {p.receipt_no} · {p.mode_label}
                          </p>
                        </div>
                        {p.status === 'cancelled' ? <StatusBadge status="cancelled" label="Void" /> : <PrintButton path={`receipt/${p.id}`} size="xs">Receipt</PrintButton>}
                      </li>
                    ))}
                  </ul>
                  <div className="table-wrap hidden md:block">
                    <table className="data-table">
                      <thead>
                        <tr>
                          <th>Receipt</th>
                          <th>Date</th>
                          <th>Mode</th>
                          <th className="text-right">Amount</th>
                          <th>Status</th>
                          <th className="text-right">Print</th>
                        </tr>
                      </thead>
                      <tbody>
                        {data.payments.map((p) => (
                          <tr key={p.id}>
                            <td>
                              <span className="font-semibold text-slate-900 dark:text-white">{p.receipt_no}</span>
                              {p.invoices && <span className="block text-[11px] text-slate-400">{p.invoices}</span>}
                            </td>
                            <td className="whitespace-nowrap">{formatDate(p.date)}</td>
                            <td>
                              {p.mode_label}
                              {p.reference_no && <span className="block text-[11px] text-slate-400">{p.reference_no}</span>}
                            </td>
                            <td className="text-right font-semibold tabular-nums text-slate-900 dark:text-white">{money(p.total)}</td>
                            <td>
                              <StatusBadge status={p.status === 'cancelled' ? 'cancelled' : p.status} label={p.status === 'cancelled' ? 'Void' : undefined} />
                            </td>
                            <td className="text-right">{p.printable && p.status !== 'cancelled' ? <PrintButton path={`receipt/${p.id}`} size="xs" icon={Printer}>Receipt</PrintButton> : <span className="text-xs text-slate-400">—</span>}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </section>
              )}
            </div>

            <div className="space-y-5 xl:col-span-2">
              <PayCard pay={data.pay} balance={t.balance} />
              <Section title="Pending dues" icon={CalendarClock} bodyClassName="p-2 sm:p-3">
                {data.schedule.length === 0 ? (
                  <EmptyBlock compact icon={CircleCheck} title="Nothing due" text="You have no pending installments." />
                ) : (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.schedule.map((p, i) => (
                      <li key={i} className="flex items-center gap-3 px-2 py-2.5">
                        <DateTile date={p.due_date} tone={p.overdue ? 'flame' : 'navy'} />
                        <div className="min-w-0 flex-1">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{p.title}</p>
                          <p className="text-xs text-slate-500">
                            {p.invoice_no}
                            {p.installment_no ? ` · installment ${p.installment_no}` : ''}
                          </p>
                        </div>
                        <div className="text-right">
                          <p className="font-semibold tabular-nums text-slate-900 dark:text-white">{money(p.amount)}</p>
                          <p className={clsx('text-[11px] font-semibold', p.overdue ? 'text-red-600 dark:text-red-400' : 'text-slate-400')}>{dueLabel(p.days_left)}</p>
                        </div>
                      </li>
                    ))}
                  </ul>
                )}
              </Section>
              {data.concessions.length > 0 && (
                <Section title="Scholarships & concessions" icon={BadgePercent} bodyClassName="p-2 sm:p-3">
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.concessions.map((c, i) => (
                      <li key={i} className="flex items-center justify-between gap-3 px-2 py-2.5">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{c.name}</p>
                          <p className="truncate text-xs text-slate-500">
                            {c.invoice_no ?? ''}
                            {c.reason ? ` · ${c.reason}` : ''}
                          </p>
                        </div>
                        <span className="font-semibold tabular-nums text-emerald-700 dark:text-emerald-300">{money(c.amount)}</span>
                      </li>
                    ))}
                  </ul>
                </Section>
              )}
            </div>
          </div>
        </div>
      )}
      <InvoiceDrawer id={openId} onClose={() => open(null)} />
    </div>
  );
}
