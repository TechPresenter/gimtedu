import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { BadgeCheck, Ban, Banknote, CalendarRange, ChartPie, Clock3, FolderTree, Paperclip, Receipt, Store, TrendingUp, Wallet } from 'lucide-react';
import {
  Alert, Button, Card, CardHeader, CardSkeleton, CountUp, EmptyState, Field, Input, Modal, PageHeader, ProgressBar, Reveal, Select, Skeleton, Stagger, StatCard,
  StatusBadge, Tabs, useConfirm, useToast,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { BarChart, DoughnutChart, chartColor } from '@/components/charts';
import { api, apiUrl, ApiError } from '@/lib/api';
import { useApi, useInvalidate } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, formatPercent, isoDate, toNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { MODE_LABELS, ModeBadge, ReasonDialog } from './shared';
import type { Num } from './types';

interface Summary {
  session: { id: number | null; name: string };
  kpis: { spent: number; this_month: number; last_month: number; last_month_to_date: number; pending_amount: number; pending_count: number; approved_unpaid: number; approved_unpaid_amount: number; tax: number; count: number; budget: number; utilization: number };
  monthly: { month: string; label: string; spent: number; income: number }[];
  categories: { id: number; name: string; budget_amount: Num | null; spent: Num; n: Num; utilization: number | null }[];
  vendors: { vendor: string; n: Num; amount: Num }[];
}

const EXPENSE_LABEL: Record<string, string> = { pending: 'Pending approval', approved: 'Approved', paid: 'Paid', rejected: 'Rejected' };

export default function ExpensesPage() {
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'categories' ? 'categories' : 'expenses';
  return (
    <>
      <PageHeader
        title="Expenses"
        breadcrumbs={[{ label: 'Finance' }, { label: 'Expenses' }]}
        description="Institute expenditure with bills, budgets per category and an approval workflow (pending → approved → paid)."
      />
      <Tabs
        className="mb-5"
        value={tab}
        onChange={(t) => setParams(t === 'categories' ? new URLSearchParams({ tab: 'categories' }) : new URLSearchParams(), { replace: true })}
        tabs={[{ key: 'expenses', label: 'Expenses', icon: Receipt }, { key: 'categories', label: 'Categories & budgets', icon: FolderTree }]}
      />
      {tab === 'expenses' ? <ExpensesTab /> : <CategoriesTab />}
    </>
  );
}

function ExpensesTab() {
  const { id: sessionId } = useAcademicSession();
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const invalidate = useInvalidate();
  const { data, isLoading, error, refetch } = useApi<Summary>(['expenses-summary', sessionId], 'expenses/summary', { session_id: sessionId ?? undefined });
  const [rejectRow, setRejectRow] = useState<Row | null>(null);
  const [payRow, setPayRow] = useState<Row | null>(null);
  const k = data?.kpis;
  const refresh = () => invalidate('crud', 'expenses-summary', 'fees-dashboard', 'fees-report');
  const approver = can('expenses', 'approve');
  // Month-to-date against the same days of last month (a full month would always look like a drop early in the month).
  const monthChange = k && k.last_month_to_date > 0 ? ((k.this_month - k.last_month_to_date) / k.last_month_to_date) * 100 : null;

  const approve = async (r: Row) => {
    const ok = await confirm({ title: `Approve ${r.expense_no}?`, message: <>Approve <strong>{formatMoney(r.total_amount)}</strong> — {r.title}{r.vendor ? ` (${r.vendor})` : ''}.</>, confirmText: 'Approve expense' });
    if (!ok) return;
    try {
      const res = await api.post(`expenses/${r.id}/approve`, {});
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      {error ? (
        <Alert variant="error" className="mb-5" title="Unable to load the expense summary" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
      ) : (
        <>
          <Stagger className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
            {[
              <StatCard key="spent" label="Spent this session" icon={Wallet} tone="navy" loading={isLoading} value={k ? <CountUp value={k.spent} format={formatMoneyShort} /> : '—'} hint={k ? `${formatPercent(k.utilization, 1)} of ${formatMoneyShort(k.budget)} budget` : undefined} />,
              <StatCard key="month" label="This month" icon={CalendarRange} tone="blue" loading={isLoading} value={k ? <CountUp value={k.this_month} format={formatMoneyShort} /> : '—'}
                trend={k && monthChange !== null ? { value: formatPercent(Math.abs(monthChange), 1), dir: monthChange > 0 ? 'up' : monthChange < 0 ? 'down' : 'flat', positive: monthChange <= 0, label: 'vs last month' } : undefined} hint={k && monthChange === null ? `Last month ${formatMoneyShort(k.last_month)}` : undefined} />,
              <StatCard key="pending" label="Pending approval" icon={Clock3} tone="amber" loading={isLoading} value={k ? <CountUp value={k.pending_amount} format={formatMoneyShort} /> : '—'} hint={k ? `${formatNumber(k.pending_count)} bill${k.pending_count === 1 ? '' : 's'} waiting` : undefined} />,
              <StatCard key="unpaid" label="Approved, unpaid" icon={BadgeCheck} tone="purple" loading={isLoading} value={k ? <CountUp value={k.approved_unpaid_amount} format={formatMoneyShort} /> : '—'} hint={k ? `${formatNumber(k.approved_unpaid)} to pay vendors` : undefined} />,
              <StatCard key="tax" label="GST / tax paid" icon={Receipt} tone="cyan" loading={isLoading} value={k ? <CountUp value={k.tax} format={formatMoneyShort} /> : '—'} hint={k ? `${formatNumber(k.count)} entries this session` : undefined} />,
            ]}
          </Stagger>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-8">
              <Card className="h-full">
                <CardHeader title="Monthly spend vs fee income" subtitle="Approved & paid expenses against fee collection" icon={TrendingUp} />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="h-64 w-full" /> : data.monthly.length ? (
                    <BarChart money height={260} labels={data.monthly.map((m) => m.label)} series={[{ label: 'Fee income', data: data.monthly.map((m) => m.income), color: '#22943F' }, { label: 'Expenses', data: data.monthly.map((m) => m.spent), color: '#F97316' }]} />
                  ) : <EmptyState icon={TrendingUp} title="No data yet" />}
                </div>
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-4" delay={80}>
              <Card className="h-full">
                <CardHeader title="Category split" subtitle="Share of this session's spend" icon={ChartPie} />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="mx-auto h-40 w-40 rounded-full" /> : data.categories.some((c) => toNumber(c.spent) > 0) ? (
                    <DoughnutChart
                      height={160}
                      labels={data.categories.filter((c) => toNumber(c.spent) > 0).slice(0, 6).map((c) => c.name.split(' (')[0].split(' & ')[0])}
                      data={data.categories.filter((c) => toNumber(c.spent) > 0).slice(0, 6).map((c) => toNumber(c.spent))}
                      centerValue={formatMoneyShort(data.kpis.spent)}
                      centerLabel="Spent"
                    />
                  ) : <EmptyState icon={ChartPie} title="No approved spend yet" />}
                </div>
              </Card>
            </Reveal>
          </div>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-8">
              <Card className="h-full">
                <CardHeader title="Budget utilisation" subtitle="Session spend against each category's annual budget" icon={FolderTree} />
                <div className="card-body">
                  {isLoading || !data ? <CardSkeleton lines={6} /> : (
                    <ul className="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                      {data.categories.map((c, i) => {
                        const u = c.utilization ?? 0;
                        return (
                          <li key={c.id}>
                            <div className="flex items-baseline justify-between gap-2 text-sm">
                              <span className="flex min-w-0 items-center gap-2 font-medium text-slate-800 dark:text-slate-100"><span className="h-2 w-2 shrink-0 rounded-full" style={{ background: chartColor(i) }} /><span className="truncate">{c.name}</span></span>
                              <span className="shrink-0 text-xs text-slate-500"><strong className="tabular-nums text-slate-900 dark:text-white">{formatMoneyShort(c.spent)}</strong>{c.budget_amount ? ` / ${formatMoneyShort(c.budget_amount)}` : ''}</span>
                            </div>
                            <ProgressBar value={u} tone={u > 90 ? 'red' : u > 70 ? 'amber' : 'green'} className="mt-1.5 !h-1.5" label={`${c.name} budget used`} />
                          </li>
                        );
                      })}
                    </ul>
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-4" delay={80}>
              <Card className="h-full">
                <CardHeader title="Top vendors" subtitle="By amount this session" icon={Store} />
                {isLoading || !data ? <div className="p-5"><CardSkeleton lines={4} /></div> : data.vendors.length ? (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.vendors.map((v, i) => (
                      <li key={v.vendor} className="flex items-center gap-3 px-5 py-3">
                        <span className="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xs font-bold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{i + 1}</span>
                        <span className="min-w-0 flex-1"><span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{v.vendor}</span><span className="text-xs text-slate-500">{formatNumber(v.n)} bill{Number(v.n) === 1 ? '' : 's'}</span></span>
                        <span className="text-sm font-semibold tabular-nums">{formatMoneyShort(v.amount)}</span>
                      </li>
                    ))}
                  </ul>
                ) : <EmptyState icon={Store} title="No vendors yet" />}
              </Card>
            </Reveal>
          </div>
        </>
      )}

      <div className="mt-6">
        <CrudTable
          module="expenses"
          urlState
          addLabel="Add expense"
          emptyTitle="No expenses recorded"
          emptyText="Record bills and payments with their attachments to track spend against budget."
          onSaved={() => refresh()}
          renderers={{
            title: (r) => (
              <div className="min-w-[200px] leading-tight">
                <span className="font-semibold text-slate-900 dark:text-white">{r.title}</span>
                <span className="flex items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                  {r.attachment && (
                    <a href={apiUrl('files/download', { path: String(r.attachment), name: `${String(r.expense_no).replace(/\//g, '-')}-bill` })} target="_blank" rel="noopener noreferrer"
                      className="inline-flex shrink-0 items-center text-brand-700 hover:text-brand-900 dark:text-brand-300" title="Open the attached bill" aria-label={`Open the bill attached to ${r.expense_no}`}>
                      <Paperclip className="h-3.5 w-3.5" />
                    </a>
                  )}
                  <span>{r.title_sub}</span>
                </span>
              </div>
            ),
            status: (r) => <StatusBadge status={r.status} label={EXPENSE_LABEL[r.status]} colors={{ pending: 'amber', approved: 'blue', paid: 'green', rejected: 'red' }} />,
            payment_mode: (r) => <ModeBadge mode={r.payment_mode} />,
            total_amount: (r) => (
              <span className="whitespace-nowrap font-semibold tabular-nums text-slate-900 dark:text-white">
                {formatMoney(r.total_amount)}
                {toNumber(r.tax_amount) > 0 && <span className="block text-[11px] font-normal text-slate-500">incl. tax {formatMoney(r.tax_amount)}</span>}
              </span>
            ),
          }}
          rowMenu={(r) => [
            approver && r.status === 'pending' && { label: 'Approve', icon: BadgeCheck, onClick: () => approve(r) },
            approver && r.status === 'approved' && { label: 'Mark as paid', icon: Banknote, onClick: () => setPayRow(r) },
            approver && ['pending', 'approved'].includes(String(r.status)) && { label: 'Reject', icon: Ban, danger: true, onClick: () => setRejectRow(r) },
            r.attachment && { label: 'Download bill', icon: Paperclip, href: apiUrl('files/download', { path: String(r.attachment), name: `${String(r.expense_no).replace(/\//g, '-')}-bill` }), target: '_blank' },
          ]}
          bulkActions={
            approver
              ? [{
                  label: 'Approve selected',
                  icon: BadgeCheck,
                  onClick: async (ids, clear) => {
                    const ok = await confirm({ title: `Approve ${ids.length} expense${ids.length === 1 ? '' : 's'}?`, message: 'Only pending expenses are approved; others are skipped.', confirmText: 'Approve' });
                    if (!ok) return;
                    let done = 0;
                    for (const id of ids) {
                      try {
                        await api.post(`expenses/${id}/approve`, {});
                        done++;
                      } catch {
                        /* not pending - skipped */
                      }
                    }
                    toast.success(`${done} expense${done === 1 ? '' : 's'} approved${done < ids.length ? `, ${ids.length - done} skipped` : ''}.`);
                    clear();
                    await refresh();
                  },
                }]
              : []
          }
          header={({ summary }) =>
            summary ? (
              <div className="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-800">
                {[
                  ['Total (filtered)', formatMoney(summary.total), ''],
                  ['Pending', `${formatMoney(summary.pending)} · ${formatNumber(summary.pending_count)}`, 'text-amber-700 dark:text-amber-400'],
                  ['Approved', formatMoney(summary.approved), 'text-blue-700 dark:text-blue-300'],
                  ['Paid', formatMoney(summary.paid), 'text-emerald-700 dark:text-emerald-400'],
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
      </div>
      <ReasonDialog
        open={!!rejectRow}
        onClose={() => setRejectRow(null)}
        danger
        field="remarks"
        title={`Reject ${rejectRow?.expense_no ?? ''}?`}
        description={rejectRow ? `${rejectRow.title} · ${formatMoney(rejectRow.total_amount)}. The submitter is notified and can correct and resubmit it.` : undefined}
        label="Reason for rejecting"
        placeholder="e.g. Quotation comparison missing; resubmit with three quotes"
        confirmText="Reject expense"
        onSubmit={async (remarks) => {
          const res = await api.post(`expenses/${rejectRow?.id}/reject`, { remarks });
          toast.success(res.message);
          await refresh();
        }}
      />
      <PayExpenseModal row={payRow} onClose={() => setPayRow(null)} onDone={refresh} />
    </>
  );
}

function PayExpenseModal({ row, onClose, onDone }: { row: Row | null; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [values, setValues] = useState({ payment_mode: 'bank_transfer', payment_reference: '', paid_on: isoDate() });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  useEffect(() => {
    if (row) {
      setValues({ payment_mode: String(row.payment_mode || 'bank_transfer'), payment_reference: '', paid_on: isoDate() });
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
      const res = await api.post(`expenses/${row?.id}/pay`, values);
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
      title={`Pay ${row?.expense_no ?? ''}`}
      description={row ? `${row.title} · ${row.vendor ?? 'No vendor'} · ${formatMoney(row.total_amount)} (bill ${row.reference_no ?? '—'}, dated ${formatDate(row.expense_date)})` : undefined}
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button variant="success" icon={Banknote} onClick={submit} loading={saving}>Mark as paid</Button>
        </>
      }
    >
      <div className="grid gap-4 sm:grid-cols-2">
        <Field htmlFor="exp-paid-via" label="Paid via" required error={errors.payment_mode}>
          <Select id="exp-paid-via" value={values.payment_mode} onChange={(e) => set('payment_mode', e.target.value)} options={modes} />
        </Field>
        <Field htmlFor="exp-paid-on" label="Paid on" required error={errors.paid_on}>
          <Input id="exp-paid-on" type="date" value={values.paid_on} max={isoDate()} invalid={!!errors.paid_on} onChange={(e) => set('paid_on', e.target.value)} />
        </Field>
        {values.payment_mode !== 'cash' && (
          <Field htmlFor="exp-utr-cheque-number" label="UTR / cheque number" required error={errors.payment_reference} className="sm:col-span-2">
            <Input id="exp-utr-cheque-number" value={values.payment_reference} maxLength={100} invalid={!!errors.payment_reference} onChange={(e) => set('payment_reference', e.target.value)} />
          </Field>
        )}
      </div>
    </Modal>
  );
}

function CategoriesTab() {
  const invalidate = useInvalidate();
  return (
    <CrudTable
      module="expense_categories"
      urlState
      addLabel="Add category"
      emptyTitle="No expense categories"
      emptyText="Create heads of expenditure with annual budgets."
      onSaved={() => invalidate('expenses-summary')}
      renderers={{
        utilization: (r) => {
          const u = r.utilization === null || r.utilization === undefined ? null : toNumber(r.utilization);
          return u === null ? <span className="text-slate-400">No budget</span> : (
            <div className="flex min-w-[120px] items-center gap-2">
              <ProgressBar value={u} tone={u > 90 ? 'red' : u > 70 ? 'amber' : 'green'} className="!h-1.5" label="Budget used" />
              <span className="w-12 text-right text-xs tabular-nums">{u.toFixed(1)}%</span>
            </div>
          );
        },
      }}
    />
  );
}
