import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlarmClock, ArrowRight, BadgePercent, BellRing, CalendarCheck, ChartColumn, CreditCard, FileText, HandCoins, IndianRupee, Landmark, Percent, ReceiptText,
  Undo2, Wallet,
} from 'lucide-react';
import {
  Alert, Avatar, Button, Card, CardHeader, CardSkeleton, CountUp, EmptyState, PageHeader, ProgressBar, Reveal, Skeleton, Stagger, StatCard, useToast,
} from '@/components/ui';
import { BarChart, DoughnutChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatMoney, formatMoneyShort, formatNumber, formatPercent, timeAgo, toNumber } from '@/lib/format';
import { InvoiceDrawer, MODE_HEX, ModeBadge, PaymentDrawer, useFeesRefresh } from './shared';
import type { Gateway, Num } from './types';

const SHORT_MODE: Record<string, string> = { bank_transfer: 'Bank', online: 'Online', dd: 'DD' };

interface Dashboard {
  session: { id: number | null; name: string; start_date: string; end_date: string };
  kpis: {
    session_collection: number; billed: number; collected: number; pending: number; overdue: number; overdue_count: number; students_due: number; invoices: number;
    today: number; today_count: number; this_month: number; last_month: number; month_change: number | null; concessions: number; fines: number; collection_rate: number;
    refunds_pending: number; refunds_pending_amount: number; expenses_month: number; scholarship_students: number;
  };
  monthly: { month: string; label: string; collected: number; target: number }[];
  modes: { mode: string; label: string; n: number; amount: number }[];
  programs: { id: number; program: string; billed: Num; collected: Num; outstanding: Num; overdue: Num }[];
  recent_payments: { id: number; receipt_no: string; total: Num; mode: string; payment_date: string; created_at: string; status: string; student_name: string | null; student_uid: string | null; photo: string | null; program_short: string | null; collected_by_name: string | null }[];
  overdue: { id: number; invoice_no: string; title: string; balance_amount: Num; overdue_amount: Num; overdue_since: string; days_overdue: number; last_reminder_at: string | null; reminder_count: number; student_id: number; student_name: string; student_uid: string; photo: string | null; program_short: string | null; current_semester: number }[];
  gateway: Gateway;
}

export default function FeesDashboardPage() {
  const { id: sessionId } = useAcademicSession();
  const { can } = useAuth();
  const { data, isLoading, error, refetch } = useApi<Dashboard>(['fees-dashboard', sessionId], 'fees/dashboard', { session_id: sessionId ?? undefined });
  const [invoiceId, setInvoiceId] = useState<number | null>(null);
  const [paymentId, setPaymentId] = useState<number | null>(null);
  const k = data?.kpis;
  const monthDir = k?.month_change === null || k?.month_change === undefined ? 'flat' : k.month_change > 0 ? 'up' : k.month_change < 0 ? 'down' : 'flat';

  return (
    <>
      <PageHeader
        title="Fees & Accounts"
        breadcrumbs={[{ label: 'Fees & Accounts' }]}
        description={
          <span className="inline-flex flex-wrap items-center gap-2">
            {data?.session && <span className="badge badge-navy">Session {data.session.name}</span>}
            Collections, dues and receipts across every program.
          </span>
        }
        actions={
          <>
            <Button variant="secondary" icon={ChartColumn} to="/fees/reports">Reports</Button>
            <Button variant="secondary" icon={FileText} to="/fees/invoices">Invoices</Button>
            {can('fees', 'create') && <Button variant="success" icon={HandCoins} to="/fees/collect">Collect fee</Button>}
          </>
        }
      />
      {error ? (
        <Alert variant="error" title="Unable to load the fees dashboard" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <>
          <Stagger className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
            {[
              <StatCard key="col" label="Collected this session" icon={IndianRupee} tone="green" loading={isLoading} to="/fees/payments"
                value={k ? <CountUp value={k.session_collection} format={formatMoneyShort} /> : '—'} hint={k ? `${formatMoneyShort(k.billed)} billed` : undefined} />,
              <StatCard key="pending" label="Pending dues" icon={Wallet} tone="amber" loading={isLoading} to="/fees/invoices?f.balance=due"
                value={k ? <CountUp value={k.pending} format={formatMoneyShort} /> : '—'} hint={k ? `${formatNumber(k.students_due)} students with dues` : undefined} />,
              <StatCard key="overdue" label="Overdue" icon={AlarmClock} tone="red" loading={isLoading} to="/fees/invoices?f.overdue=yes"
                value={k ? <CountUp value={k.overdue} format={formatMoneyShort} /> : '—'} hint={k ? `${formatNumber(k.overdue_count)} invoices past due` : undefined} />,
              <StatCard key="today" label="Today's collection" icon={CalendarCheck} tone="blue" loading={isLoading} to="/fees/reports?tab=daybook"
                value={k ? <CountUp value={k.today} format={formatMoneyShort} /> : '—'} hint={k ? `${formatNumber(k.today_count)} receipt${k.today_count === 1 ? '' : 's'} today` : undefined} />,
              <StatCard key="month" label="This month" icon={ReceiptText} tone="purple" loading={isLoading} to="/fees/reports?tab=collection"
                value={k ? <CountUp value={k.this_month} format={formatMoneyShort} /> : '—'}
                trend={k ? { value: k.month_change === null ? '—' : formatPercent(Math.abs(k.month_change), 1), dir: monthDir, label: 'vs last month' } : undefined} />,
              <StatCard key="rate" label="Collection rate" icon={Percent} tone="cyan" loading={isLoading}
                value={k ? <CountUp value={k.collection_rate} format={(n) => `${n.toFixed(1)}%`} /> : '—'} hint={k ? `${formatNumber(k.invoices)} invoices` : undefined} />,
            ]}
          </Stagger>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-8">
              <Card className="h-full">
                <CardHeader title="Monthly collection vs target" subtitle="Receipts against installments falling due each month" icon={ChartColumn} />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="h-[280px] w-full" /> : (
                    <BarChart
                      money
                      height={280}
                      labels={data.monthly.map((m) => m.label)}
                      series={[
                        { label: 'Collected', data: data.monthly.map((m) => m.collected), color: '#22943F' },
                        { label: 'Target (due)', data: data.monthly.map((m) => m.target), color: 'rgba(59, 130, 246, 0.35)' },
                      ]}
                    />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-4" delay={80}>
              <Card className="h-full">
                <CardHeader title="Payment modes" subtitle="Share of this session's collection" icon={CreditCard} />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="mx-auto h-44 w-44 rounded-full" /> : data.modes.length ? (
                    <DoughnutChart
                      height={176}
                      labels={data.modes.map((m) => SHORT_MODE[m.mode] ?? m.label)}
                      data={data.modes.map((m) => toNumber(m.amount))}
                      colors={data.modes.map((m) => MODE_HEX[m.mode] ?? '#64748B')}
                      centerValue={formatMoneyShort(data.modes.reduce((a, m) => a + toNumber(m.amount), 0))}
                      centerLabel="Collected"
                    />
                  ) : (
                    <EmptyState icon={CreditCard} title="No payments yet" description="Collections will appear here by payment mode." />
                  )}
                </div>
              </Card>
            </Reveal>
          </div>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-7">
              <Card className="h-full">
                <CardHeader title="Program-wise dues" subtitle="Outstanding and overdue balance by program" icon={Landmark}
                  actions={<Button size="sm" variant="ghost" to="/fees/reports?tab=outstanding" iconRight={ArrowRight}>Outstanding report</Button>} />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="h-72 w-full" /> : data.programs.length ? (
                    <ProgramDues programs={data.programs} />
                  ) : (
                    <EmptyState icon={Landmark} title="No invoices this session" description="Assign fee structures to students to start tracking dues." action={<Button to="/fees/structures" size="sm">Fee structures</Button>} />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-5" delay={80}>
              <CollectionHealth data={data} loading={isLoading} />
            </Reveal>
          </div>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-5">
              <Card className="h-full">
                <CardHeader title="Recent payments" subtitle="Latest receipts issued" icon={ReceiptText} actions={<Button size="sm" variant="ghost" to="/fees/payments" iconRight={ArrowRight}>All payments</Button>} />
                {isLoading || !data ? <div className="p-5"><CardSkeleton lines={5} /></div> : data.recent_payments.length === 0 ? (
                  <EmptyState icon={ReceiptText} title="No payments yet" description="Receipts appear here as soon as fees are collected." action={can('fees', 'create') ? <Button size="sm" variant="success" icon={HandCoins} to="/fees/collect">Collect fee</Button> : undefined} />
                ) : (
                  <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                    {data.recent_payments.map((p) => (
                      <li key={p.id}>
                        <button type="button" onClick={() => setPaymentId(p.id)} className="flex w-full items-center gap-3 px-5 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                          <Avatar name={p.student_name} src={p.photo} size="sm" />
                          <div className="min-w-0 flex-1">
                            <p className="truncate text-sm font-semibold text-slate-900 dark:text-white">{p.student_name ?? '—'}</p>
                            <p className="truncate text-xs text-slate-500">{p.receipt_no} · {p.program_short ?? ''} · {timeAgo(p.created_at)}</p>
                          </div>
                          <div className="shrink-0 text-right">
                            <p className={clsx('text-sm font-semibold tabular-nums', p.status === 'cancelled' ? 'text-slate-400 line-through' : 'text-slate-900 dark:text-white')}>{formatMoney(p.total)}</p>
                            <ModeBadge mode={p.mode} />
                          </div>
                        </button>
                      </li>
                    ))}
                  </ul>
                )}
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-7" delay={80}>
              <OverdueList data={data} loading={isLoading} onOpen={setInvoiceId} canRemind={can('fees', 'edit')} />
            </Reveal>
          </div>
        </>
      )}
      <InvoiceDrawer id={invoiceId} onClose={() => setInvoiceId(null)} />
      <PaymentDrawer id={paymentId} onClose={() => setPaymentId(null)} />
    </>
  );
}

function ProgramDues({ programs }: { programs: Dashboard['programs'] }) {
  const max = Math.max(1, ...programs.map((p) => toNumber(p.outstanding)));
  return (
    <>
      <div className="mb-3 flex items-center gap-4 text-xs text-slate-500">
        <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-red-500" />Overdue</span>
        <span className="inline-flex items-center gap-1.5"><span className="h-2.5 w-2.5 rounded-full bg-amber-400" />Due later</span>
      </div>
      <ul className="space-y-3">
        {programs.map((p) => {
          const out = toNumber(p.outstanding);
          const over = toNumber(p.overdue);
          const rate = toNumber(p.billed) > 0 ? (toNumber(p.collected) / toNumber(p.billed)) * 100 : 0;
          return (
            <li key={p.id}>
              <div className="flex items-baseline justify-between gap-3 text-sm">
                <Link to={`/fees/invoices?f.program_id=${p.id}&f.balance=due`} className="truncate font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100">{p.program}</Link>
                <span className="shrink-0 text-xs text-slate-500"><strong className="tabular-nums text-slate-900 dark:text-white">{formatMoneyShort(out)}</strong> due · {rate.toFixed(0)}% collected</span>
              </div>
              <div className="mt-1.5 flex h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" aria-label={`${p.program}: ${formatMoney(out)} outstanding, ${formatMoney(over)} overdue`}>
                <div className="h-full bg-red-500" style={{ width: `${(over / max) * 100}%` }} title={`Overdue ${formatMoney(over)}`} />
                <div className="h-full bg-amber-400" style={{ width: `${(Math.max(0, out - over) / max) * 100}%` }} title={`Due later ${formatMoney(out - over)}`} />
              </div>
            </li>
          );
        })}
      </ul>
    </>
  );
}

function CollectionHealth({ data, loading }: { data?: Dashboard; loading: boolean }) {
  const k = data?.kpis;
  const rows = k
    ? [
        { icon: BadgePercent, label: 'Scholarships & discounts', value: formatMoney(k.concessions), sub: `${formatNumber(k.scholarship_students)} scholarship holders`, to: '/fees/scholarships' },
        { icon: AlarmClock, label: 'Late fees charged', value: formatMoney(k.fines), sub: 'This session', to: '/fees/reports?tab=headwise' },
        { icon: Undo2, label: 'Refunds awaiting action', value: formatMoney(k.refunds_pending_amount), sub: `${formatNumber(k.refunds_pending)} request(s)`, to: '/fees/refunds' },
        { icon: Wallet, label: 'Expenses this month', value: formatMoney(k.expenses_month), sub: 'Approved & paid', to: '/expenses' },
      ]
    : [];
  return (
    <Card className="h-full">
      <CardHeader title="Collection health" subtitle={data ? `Session ${data.session.name}` : undefined} icon={IndianRupee} />
      <div className="card-body">
        {loading || !k ? <CardSkeleton lines={5} /> : (
          <>
            <div className="flex items-end justify-between gap-3">
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500">Collected of billed</p>
                <p className="font-display text-2xl font-bold text-slate-900 dark:text-white">{formatMoneyShort(k.collected)} <span className="text-sm font-medium text-slate-400">/ {formatMoneyShort(k.billed)}</span></p>
              </div>
              <span className="badge badge-green">{formatPercent(k.collection_rate, 1)}</span>
            </div>
            <ProgressBar value={k.collection_rate} tone="green" className="mt-3" label="Collection rate" />
            <ul className="mt-5 space-y-1">
              {rows.map((r) => (
                <li key={r.label}>
                  <Link to={r.to} className="flex items-center gap-3 rounded-xl px-2 py-2 transition hover:bg-slate-50 dark:hover:bg-slate-800/50">
                    <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200"><r.icon className="h-4 w-4" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{r.label}</span>
                      <span className="block truncate text-xs text-slate-500">{r.sub}</span>
                    </span>
                    <span className="text-sm font-semibold tabular-nums text-slate-900 dark:text-white">{r.value}</span>
                  </Link>
                </li>
              ))}
            </ul>
            {data?.gateway && (
              <p className="mt-4 flex items-center gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-600 dark:bg-slate-800/60 dark:text-slate-300">
                <CreditCard className="h-4 w-4 shrink-0" />
                Online gateway: <strong>{data.gateway.label ?? 'Not set'}</strong>
                <span className={clsx('badge ml-auto', data.gateway.configured ? 'badge-green' : 'badge-amber')}>{data.gateway.configured ? `${data.gateway.mode} mode` : 'Keys not configured'}</span>
              </p>
            )}
          </>
        )}
      </div>
    </Card>
  );
}

function OverdueList({ data, loading, onOpen, canRemind }: { data?: Dashboard; loading: boolean; onOpen: (id: number) => void; canRemind: boolean }) {
  const toast = useToast();
  const refresh = useFeesRefresh();
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [sending, setSending] = useState<string | null>(null);
  const rows = data?.overdue ?? [];
  const remind = async (ids: number[], key: string) => {
    setSending(key);
    try {
      const res = await api.post('fees/reminders', { ids });
      toast.success(res.message);
      setSelected(new Set());
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setSending(null);
    }
  };
  const toggle = (id: number) => setSelected((s) => {
    const n = new Set(s);
    if (n.has(id)) n.delete(id);
    else n.add(id);
    return n;
  });
  return (
    <Card className="h-full">
      <CardHeader
        title="Overdue invoices"
        subtitle="Largest overdue balances — send a reminder by app notification, email and SMS"
        icon={AlarmClock}
        actions={
          <>
            {canRemind && selected.size > 0 && <Button size="sm" variant="soft" icon={BellRing} loading={sending === 'bulk'} onClick={() => remind([...selected], 'bulk')}>Remind {selected.size}</Button>}
            <Button size="sm" variant="ghost" to="/fees/reports?tab=defaulters" iconRight={ArrowRight}>Defaulters</Button>
          </>
        }
      />
      {loading || !data ? <div className="p-5"><CardSkeleton lines={5} /></div> : rows.length === 0 ? (
        <EmptyState icon={CalendarCheck} title="Nothing overdue" description="Every invoice is within its due date. Great work!" />
      ) : (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {rows.map((o) => (
            <li key={o.id} className="flex items-center gap-3 px-5 py-3">
              {canRemind && <input type="checkbox" className="form-checkbox" checked={selected.has(o.id)} onChange={() => toggle(o.id)} aria-label={`Select ${o.invoice_no}`} />}
              <Avatar name={o.student_name} src={o.photo} size="sm" />
              <button type="button" onClick={() => onOpen(o.id)} className="min-w-0 flex-1 text-left">
                <p className="truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{o.student_name}</p>
                <p className="truncate text-xs text-slate-500">
                  {o.invoice_no} · {o.program_short} Sem {o.current_semester} · since {formatDate(o.overdue_since)}
                  {o.last_reminder_at ? ` · reminded ${timeAgo(o.last_reminder_at)}` : ''}
                </p>
              </button>
              <div className="hidden shrink-0 text-right sm:block">
                <p className="text-sm font-semibold tabular-nums text-red-600 dark:text-red-400">{formatMoney(o.overdue_amount)}</p>
                <p className="text-[11px] text-slate-500">{o.days_overdue} days</p>
              </div>
              {canRemind && (
                <Button size="xs" variant="secondary" icon={BellRing} loading={sending === String(o.id)} onClick={() => remind([o.id], String(o.id))} title={o.last_reminder_at ? `Last reminder ${formatDateTime(o.last_reminder_at)}` : 'Send reminder'}>
                  <span className="hidden md:inline">Remind</span>
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}
