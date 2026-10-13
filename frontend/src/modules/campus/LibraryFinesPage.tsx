import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { AlarmClock, Ban, BadgeIndianRupee, CircleSlash, Eye, HandCoins, IndianRupee, Printer, Receipt, TrendingUp } from 'lucide-react';
import { Avatar, Button, Card, CardBody, CountUp, PageHeader, ProgressBar, Skeleton, StatTile, Stagger, StatusBadge, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { formatDate, formatDateTime, formatMoney, formatNumber, labelize } from '@/lib/format';
import type { Paginated, Row } from '@/lib/types';
import { CollectFinesDialog, WaiveFinesDialog } from './library/dialogs';
import { FINE_REASON_COLORS, FINE_REASON_LABELS, FINE_STATUS_COLORS, libCrumbs, openPrint } from './library/shared';
import type { FinesSummary } from './library/types';

type Selection = { ids: number[]; total: number; members: number; clear?: () => void };
const STATUS_TABS = [
  { key: '', label: 'All fines' },
  { key: 'unpaid', label: 'Unpaid' },
  { key: 'paid', label: 'Collected' },
  { key: 'waived', label: 'Waived' },
];

export default function LibraryFinesPage() {
  const toast = useToast();
  const [params, setParams] = useSearchParams();
  const status = params.get('f.status') ?? '';
  const q = useApi<FinesSummary>(['library', 'fines-summary'], 'library/fines/summary');
  const s = q.data;
  const t = s?.totals;
  const [collect, setCollect] = useState<Selection | null>(null);
  const [waive, setWaive] = useState<Selection | null>(null);

  const setStatus = (v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set('f.status', v);
    else next.delete('f.status');
    next.delete('page');
    setParams(next, { replace: true });
  };

  /** Resolve the unpaid subset of a selection (amounts + members) before opening a dialog. */
  const resolve = async (ids: number[], clear?: () => void): Promise<Selection | null> => {
    try {
      const res = await api.get<Paginated<Row>>('crud/library_fines', { ids: ids.join(','), per_page: 100 });
      const unpaid = res.rows.filter((r) => r.status === 'unpaid');
      if (!unpaid.length) {
        toast.warning('The selected fines are already settled.');
        return null;
      }
      return {
        ids: unpaid.map((r) => Number(r.id)),
        total: unpaid.reduce((a, r) => a + Number(r.amount), 0),
        members: new Set(unpaid.map((r) => r.member_id)).size,
        clear,
      };
    } catch (e) {
      toast.error((e as ApiError).message);
      return null;
    }
  };

  const rowMenu = (r: Row) => {
    const sel: Selection = { ids: [Number(r.id)], total: Number(r.amount), members: 1 };
    return [
      { label: 'Member card', icon: Eye, to: `/library/members?member=${r.member_id}` },
      r.status === 'unpaid' && s?.can_collect && { label: 'Collect…', icon: HandCoins, onClick: () => setCollect(sel) },
      r.status === 'unpaid' && s?.can_waive && { label: 'Waive…', icon: Ban, onClick: () => setWaive(sel) },
      r.status === 'paid' && r.receipt_no && { label: 'Print receipt', icon: Printer, onClick: () => openPrint('receipt', { no: r.receipt_no }) },
    ];
  };

  const reasons = s?.by_reason ?? [];
  const reasonMax = Math.max(1, ...reasons.map((r) => r.amount));

  return (
    <>
      <PageHeader
        title="Library Fines"
        description="Overdue, lost-book and damage fines — collect with a receipt or waive with a recorded reason."
        breadcrumbs={libCrumbs('Fines')}
        actions={
          <>
            <Button variant="secondary" icon={Printer} onClick={() => openPrint('overdue')}>
              Overdue report
            </Button>
            {s?.can_collect && (t?.unpaid_count ?? 0) > 0 && (
              <Button icon={HandCoins} onClick={() => setStatus('unpaid')}>
                Collect dues
              </Button>
            )}
          </>
        }
      />

      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="u" label="Unpaid fines" value={<CountUp value={t?.unpaid ?? 0} format={(n) => formatMoney(n)} />} icon={IndianRupee} tone="red" loading={q.isLoading}
            sub={`${formatNumber(t?.unpaid_count ?? 0)} fines · ${formatNumber(t?.unpaid_members ?? 0)} members`} />,
          <StatTile key="c" label={`Collected${s?.session ? ` · ${s.session}` : ''}`} value={<CountUp value={t?.paid_session ?? 0} format={(n) => formatMoney(n)} />} icon={BadgeIndianRupee} tone="green" loading={q.isLoading}
            sub={`${formatMoney(t?.paid_month ?? 0)} this month`} />,
          <StatTile key="w" label="Waived" value={<CountUp value={t?.waived ?? 0} format={(n) => formatMoney(n)} />} icon={CircleSlash} tone="slate" loading={q.isLoading} sub={`${formatNumber(t?.waived_count ?? 0)} fines written off`} />,
          <StatTile key="a" label="Accruing on overdue books" value={<CountUp value={s?.accrued ?? 0} format={(n) => formatMoney(n)} />} icon={AlarmClock} tone="amber" loading={q.isLoading} sub="Charged on return" />,
        ]}
      </Stagger>

      <Card className="mb-5">
        <CardBody className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          {q.isLoading
            ? Array.from({ length: 4 }).map((_, i) => <Skeleton key={i} className="h-14 w-full" />)
            : (['late_return', 'lost', 'damage', 'other'] as const).map((key) => {
                const r = reasons.find((x) => x.reason === key) ?? { reason: key, count: 0, amount: 0, unpaid: 0 };
                return (
                  <div key={key}>
                    <div className="flex items-baseline justify-between gap-2">
                      <span className="flex items-center gap-1.5 text-sm font-medium text-slate-700 dark:text-slate-200">
                        <TrendingUp className="h-3.5 w-3.5 text-slate-400" /> {FINE_REASON_LABELS[key]}
                      </span>
                      <span className="font-display text-sm font-bold tabular-nums text-slate-900 dark:text-white">{formatMoney(r.amount)}</span>
                    </div>
                    <ProgressBar value={(r.amount / reasonMax) * 100} tone={key === 'late_return' ? 'amber' : key === 'lost' ? 'red' : key === 'damage' ? 'purple' : 'slate'} className="mt-1.5" label={FINE_REASON_LABELS[key]} />
                    <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
                      {formatNumber(r.count)} fine{r.count === 1 ? '' : 's'} · {formatMoney(r.unpaid)} unpaid
                    </p>
                  </div>
                );
              })}
        </CardBody>
      </Card>

      <div className="pill-tabs mb-4 max-w-full overflow-x-auto scrollbar-none" role="tablist" aria-label="Fine status">
        {STATUS_TABS.map((tab) => (
          <button key={tab.key} type="button" role="tab" aria-selected={status === tab.key} onClick={() => setStatus(tab.key)} className={clsx('pill-tab whitespace-nowrap', status === tab.key && 'pill-tab-active')}>
            {tab.label}
            {tab.key === 'unpaid' && t && <span className="ml-1.5 rounded-full bg-red-100 px-1.5 text-[10px] font-semibold text-red-700 dark:bg-red-500/20 dark:text-red-300">{t.unpaid_count}</span>}
          </button>
        ))}
      </div>

      <CrudTable
        key={status}
        module="library_fines"
        urlState
        title="Fines register"
        addLabel="Add Fine"
        emptyTitle="No fines"
        emptyText="Fines are raised automatically for late, lost or damaged returns. Add a manual fine for other charges."
        rowMenu={rowMenu}
        bulkActions={[
          ...(s?.can_collect
            ? [{ label: 'Collect', icon: HandCoins, onClick: async (ids: number[], clear: () => void) => { const sel = await resolve(ids, clear); if (sel) setCollect(sel); } }]
            : []),
          ...(s?.can_waive ? [{ label: 'Waive', icon: Ban, onClick: async (ids: number[], clear: () => void) => { const sel = await resolve(ids, clear); if (sel) setWaive(sel); } }] : []),
        ]}
        renderers={{
          member_name: (r) => (
            <Link to={`/library/members?member=${r.member_id}`} className="flex min-w-[10rem] max-w-[13rem] items-center gap-2.5">
              <Avatar name={String(r.member_name)} src={r.member_photo} size="sm" />
              <span className="min-w-0 leading-tight">
                <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{r.member_name}</span>
                <span className="block text-xs text-slate-500 dark:text-slate-400">{r.membership_no} · {labelize(String(r.member_type))}</span>
              </span>
            </Link>
          ),
          book_title: (r) =>
            r.book_title ? (
              <div className="min-w-[9rem] max-w-[13rem] leading-tight">
                <Link to={`/library/books/${r.book_id}`} className="line-clamp-1 font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">{r.book_title}</Link>
                <span className="font-mono text-xs text-slate-500">{r.accession_no}</span>
              </div>
            ) : (
              <span className="text-xs text-slate-500">{r.remarks ?? 'Manual fine'}</span>
            ),
          reason: (r) => (
            <span className="block whitespace-nowrap leading-tight">
              <StatusBadge status={String(r.reason)} label={FINE_REASON_LABELS[String(r.reason)]} colors={FINE_REASON_COLORS} />
              {Number(r.days_overdue) > 0 && <span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">{r.days_overdue} day{Number(r.days_overdue) === 1 ? '' : 's'} late</span>}
            </span>
          ),
          status: (r) => (
            <span
              className="block whitespace-nowrap leading-tight"
              title={r.status === 'waived' ? `Waived: ${r.waive_reason ?? ''}` : r.status === 'paid' ? `Paid ${formatDateTime(r.paid_at)} by ${r.payment_mode_label ?? ''}` : `Raised on ${formatDate(r.created_at)}`}
            >
              <StatusBadge status={String(r.status)} colors={FINE_STATUS_COLORS} />
              <span className="mt-0.5 block text-xs text-slate-500 dark:text-slate-400">
                {formatDate(r.status === 'paid' && r.paid_at ? r.paid_at : r.status === 'waived' && r.waived_at ? r.waived_at : r.created_at)}
              </span>
            </span>
          ),
          receipt_no: (r) =>
            r.receipt_no ? (
              <button type="button" onClick={() => openPrint('receipt', { no: r.receipt_no })} className="inline-flex items-center gap-1 whitespace-nowrap rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs font-semibold text-slate-700 hover:bg-brand-50 hover:text-brand-800 dark:bg-slate-800 dark:text-slate-200" title="Print receipt">
                <Receipt className="h-3 w-3" /> {r.receipt_no}
              </button>
            ) : r.status === 'unpaid' && s?.can_collect ? (
              <Button size="xs" variant="success" icon={HandCoins} onClick={() => setCollect({ ids: [Number(r.id)], total: Number(r.amount), members: 1 })}>
                Collect
              </Button>
            ) : (
              <span className="text-slate-400">—</span>
            ),
        }}
        header={({ summary }) =>
          summary ? (
            <div className="flex flex-wrap gap-x-5 gap-y-1 border-b border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
              <span>
                <strong className="text-slate-900 dark:text-white">{formatNumber(summary.count)}</strong> fines · <strong className="text-slate-900 dark:text-white">{formatMoney(summary.total)}</strong>
              </span>
              <span>
                Unpaid <strong className="text-red-600 dark:text-red-400">{formatMoney(summary.unpaid)}</strong>
              </span>
              <span>
                Collected <strong className="text-emerald-700 dark:text-emerald-300">{formatMoney(summary.paid)}</strong>
              </span>
              <span>
                Waived <strong className="text-slate-700 dark:text-slate-200">{formatMoney(summary.waived)}</strong>
              </span>
            </div>
          ) : null
        }
      />

      <CollectFinesDialog selection={collect} onClose={() => setCollect(null)} onDone={() => collect?.clear?.()} />
      <WaiveFinesDialog selection={waive} onClose={() => setWaive(null)} onDone={() => waive?.clear?.()} />
    </>
  );
}
