import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Ban, CheckCircle2, Crown, Gift, HandCoins, HandHeart, Landmark, Megaphone, PieChart, Plus, Printer, TrendingUp, Users, Wallet } from 'lucide-react';
import { Alert, Avatar, Button, Card, CardHeader, EmptyState, PageHeader, ProgressBar, Reveal, Skeleton, StatCard, Stagger } from '@/components/ui';
import { BarChart, DoughnutChart, chartColor } from '@/components/charts';
import { CrudFormModal } from '@/components/crud';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { printUrl } from '@/lib/config';
import { formatDate, formatMoney, formatMoneyShort, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CancelDonationModal, ReceivePledgeModal } from './alumni/DonationDialogs';
import { donationRenderers, receiptLabel } from './alumni/renderers';
import { BarList, LinkedCrudTable, statsKey, useRefreshModules } from './alumni/shared';

interface DonationStats {
  kpis: {
    all_time: number; this_fy: number; last_fy: number; last_fy_to_date: number; this_fy_count: number; received_count: number; donors: number; donors_fy: number;
    pledged: number; pledged_count: number; largest: number; average: number; fy_label: string; last_fy_label: string; growth_pct: number | null;
  };
  by_purpose: { label: string; total: number; count: number }[];
  by_year: { fy: number; label: string; total: number; count: number; donors: number }[];
  by_mode: { mode: string; label: string; total: number; count: number }[];
  top_donors: (Row & { total: number; n: number })[];
  campaigns: { label: string; total: number; pledged: number; donors: number; last_date: string }[];
}

const openReceipt = (id: number) => window.open(printUrl('donation-receipt.php', { id }), '_blank');

export default function AlumniDonationsPage() {
  const { can } = useAuth();
  const refresh = useRefreshModules();
  const { data: meta } = useCrudMeta('alumni_donations');
  const { data, isLoading, error } = useApi<DonationStats>(statsKey('alumni_donations'), 'alumni/donations/stats');
  const [formOpen, setFormOpen] = useState(false);
  const [receiving, setReceiving] = useState<Row | null>(null);
  const [cancelling, setCancelling] = useState<Row | null>(null);
  const k = data?.kpis;
  const done = () => void refresh('alumni_donations', 'alumni');
  const growth = k?.growth_pct;

  return (
    <>
      <PageHeader
        title="Donations"
        description="Alumni giving to scholarships, infrastructure and other funds — with receipts for every contribution."
        breadcrumbs={[{ label: 'Alumni', to: '/alumni' }, { label: 'Donations' }]}
        actions={can('alumni', 'create') && <Button icon={Plus} onClick={() => setFormOpen(true)}>Record donation</Button>}
      />
      {error ? <Alert variant="error" title="Unable to load donation statistics" className="mb-6">{(error as Error).message}</Alert> : null}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
        {[
          <StatCard key="fy" label={`Received in ${k?.fy_label ?? 'this FY'}`} icon={HandCoins} tone="green" loading={isLoading} value={k ? formatMoneyShort(k.this_fy) : '—'}
            trend={growth !== null && growth !== undefined ? { value: `${Math.abs(growth)}%`, dir: growth > 0 ? 'up' : growth < 0 ? 'down' : 'flat', label: 'vs same period last FY' } : undefined}
            hint={k ? `${formatNumber(k.this_fy_count)} donations` : undefined} />,
          <StatCard key="all" label="All-time donations" icon={Landmark} tone="navy" loading={isLoading} value={k ? formatMoneyShort(k.all_time) : '—'}
            hint={k ? `${formatNumber(k.received_count)} gifts · avg ${formatMoneyShort(k.average)}` : undefined} />,
          <StatCard key="donors" label="Donors" icon={Users} tone="blue" loading={isLoading} value={k?.donors ?? 0} hint={k ? `${k.donors_fy} gave in ${k.fy_label}` : undefined} />,
          <StatCard key="pledged" label="Pledged (pending)" icon={HandHeart} tone="orange" loading={isLoading} value={k ? formatMoneyShort(k.pledged) : '—'}
            hint={k ? `${k.pledged_count} pledge${k.pledged_count === 1 ? '' : 's'} to follow up` : undefined} to="/alumni/donations?f.status=pledged" />,
          <StatCard key="largest" label="Largest gift" icon={Crown} tone="purple" loading={isLoading} value={k ? formatMoneyShort(k.largest) : '—'} hint={k ? `${k.last_fy_label}: ${formatMoneyShort(k.last_fy)}` : undefined} />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardHeader title="Donations by financial year" subtitle="Received amount, April – March" icon={TrendingUp} />
          <div className="card-body !pt-1">
            {isLoading || !data ? (
              <Skeleton className="h-[260px] w-full" />
            ) : data.by_year.every((y) => !y.total) ? (
              <EmptyState icon={TrendingUp} title="No donations received yet" className="py-10" />
            ) : (
              <BarChart labels={data.by_year.map((y) => y.label)} series={[{ label: 'Received', data: data.by_year.map((y) => y.total), color: chartColor(1) }]} money height={260} />
            )}
          </div>
        </Card>
        <Card>
          <CardHeader title="By purpose" subtitle="Share of all received funds" icon={PieChart} />
          <div className="card-body">
            {isLoading || !data ? (
              <Skeleton className="h-52 w-full" />
            ) : data.by_purpose.length === 0 ? (
              <EmptyState icon={PieChart} title="No data yet" className="py-8" />
            ) : (
              <>
                <div className="flex justify-center">
                  <DoughnutChart labels={data.by_purpose.map((p) => p.label)} data={data.by_purpose.map((p) => p.total)} centerValue={formatMoneyShort(k?.all_time ?? 0)} centerLabel="received" height={170} legendList={false} />
                </div>
                <ul className="mt-4 space-y-1.5 border-t border-slate-100 pt-3 text-xs dark:border-slate-800">
                  {data.by_purpose.map((p, i) => (
                    <li key={p.label} className="flex items-center justify-between gap-2">
                      <Link to={`/alumni/donations?f.purpose=${encodeURIComponent(p.label)}`} className="flex min-w-0 items-center gap-2 text-slate-600 hover:text-brand-700 dark:text-slate-300">
                        <span className="h-2 w-2 shrink-0 rounded-full" style={{ background: chartColor(i) }} />
                        <span className="truncate">{p.label}</span>
                      </Link>
                      <span className="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{formatMoneyShort(p.total)}</span>
                    </li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </Card>
      </div>

      <div className="mb-6 grid grid-cols-1 gap-6 lg:grid-cols-2 xl:grid-cols-3">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="Top donors" subtitle="Lifetime giving (anonymous gifts excluded)" icon={Gift} />
            <div className="card-body !pt-0">
              {isLoading || !data ? (
                <div className="space-y-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-10" />)}</div>
              ) : data.top_donors.length === 0 ? (
                <EmptyState icon={Gift} title="No donors yet" className="py-8" />
              ) : (
                <ol className="divide-y divide-slate-100 dark:divide-slate-800">
                  {data.top_donors.map((d, i) => (
                    <li key={String(d.id)}>
                      <Link to={`/alumni/${d.id}`} className="-mx-2 flex items-center gap-3 rounded-lg px-2 py-2.5 transition hover:bg-slate-50 dark:hover:bg-slate-800/60">
                        <span className="w-4 text-center text-xs font-bold text-slate-400">{i + 1}</span>
                        <Avatar name={String(d.name)} src={d.photo as string | null} size="sm" />
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{String(d.name)}</span>
                          <span className="block truncate text-xs text-slate-500">Batch {String(d.batch_year)}{d.company ? ` · ${d.company}` : ''} · {d.n} gift{d.n === 1 ? '' : 's'}</span>
                        </span>
                        <span className="shrink-0 text-sm font-bold tabular-nums text-accent-700 dark:text-accent-400">{formatMoneyShort(d.total)}</span>
                      </Link>
                    </li>
                  ))}
                </ol>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60}>
          <Card className="h-full">
            <CardHeader title="Recent campaigns" subtitle="Received and pledged per campaign" icon={Megaphone} />
            <div className="card-body !pt-0">
              {isLoading || !data ? (
                <div className="space-y-4">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-12" />)}</div>
              ) : data.campaigns.length === 0 ? (
                <EmptyState icon={Megaphone} title="No campaigns yet" description="Tag donations with a campaign name to track drives." className="py-8" />
              ) : (
                <ul className="space-y-4">
                  {data.campaigns.map((c) => {
                    const total = c.total + c.pledged;
                    return (
                      <li key={c.label}>
                        <div className="mb-1 flex items-baseline justify-between gap-2">
                          <Link to={`/alumni/donations?q=${encodeURIComponent(c.label)}`} className="truncate text-sm font-semibold text-slate-800 hover:text-brand-700 dark:text-slate-100">{c.label}</Link>
                          <span className="shrink-0 text-sm font-bold tabular-nums text-slate-900 dark:text-white">{formatMoneyShort(total)}</span>
                        </div>
                        <ProgressBar value={total ? (c.total / total) * 100 : 0} tone="green" label={`${c.label} received share`} />
                        <p className="mt-1 text-xs text-slate-500">{formatMoneyShort(c.total)} received{c.pledged ? ` · ${formatMoneyShort(c.pledged)} pledged` : ''} · {c.donors} donor{c.donors === 1 ? '' : 's'} · last {formatDate(c.last_date)}</p>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={120} className="lg:col-span-2 xl:col-span-1">
          <Card className="h-full">
            <CardHeader title="Payment modes" subtitle="How alumni prefer to give" icon={Wallet} />
            <div className="card-body !pt-1">
              {isLoading || !data ? (
                <div className="space-y-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-8" />)}</div>
              ) : data.by_mode.length === 0 ? (
                <EmptyState icon={Wallet} title="No data yet" className="py-8" />
              ) : (
                <BarList items={data.by_mode.map((m) => ({ key: m.mode, label: m.label, value: m.total, sub: `${m.count} donation${m.count === 1 ? '' : 's'}` }))} format={formatMoneyShort} tone="bg-cyan-500" />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <LinkedCrudTable
        module="alumni_donations"
        title="All donations"
        addLabel="Record donation"
        onSaved={done}
        emptyTitle="No donations recorded"
        emptyText="Record contributions from alumni to generate receipts and track fund-raising."
        renderers={donationRenderers}
        header={({ summary }) =>
          summary ? (
            <div className="flex flex-wrap gap-x-5 gap-y-1 border-b border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
              <span><strong className="text-emerald-700 dark:text-emerald-400">{formatMoney(summary.received)}</strong> received</span>
              <span><strong className="text-amber-700 dark:text-amber-400">{formatMoney(summary.pledged)}</strong> pledged</span>
              <span><strong className="text-slate-800 dark:text-slate-100">{formatNumber(summary.donors)}</strong> donors in this view</span>
            </div>
          ) : null
        }
        rowMenu={(r) => [
          { label: receiptLabel(r.status), icon: Printer, onClick: () => openReceipt(Number(r.id)) },
          can('alumni', 'edit') && r.status === 'pledged' && { label: 'Mark as received', icon: CheckCircle2, onClick: () => setReceiving(r) },
          can('alumni', 'edit') && r.status !== 'cancelled' && { label: 'Cancel donation…', icon: Ban, danger: true, onClick: () => setCancelling(r) },
        ]}
      />

      <CrudFormModal open={formOpen} onClose={() => setFormOpen(false)} meta={meta} module="alumni_donations" onSaved={done} />
      <ReceivePledgeModal donation={receiving} onClose={() => setReceiving(null)} onDone={done} />
      <CancelDonationModal donation={cancelling} onClose={() => setCancelling(null)} onDone={done} />
    </>
  );
}

