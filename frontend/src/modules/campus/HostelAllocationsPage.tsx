import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  ArrowRightLeft, BedDouble, CalendarPlus, Eye, IndianRupee, LayoutGrid, LogOut, Printer, ReceiptIndianRupee, UserMinus, UserPlus, Users, XCircle,
} from 'lucide-react';
import { Button, PageHeader, PersonCell, StatCard, Stagger, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatMoney, formatMoneyShort, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { AllocateModal, CancelModal, InvoiceModal, TransferModal, VacateModal, useHostelRefresh, type AllocateDefaults } from './hostel/AllocationDialogs';
import { AllocationDrawer } from './hostel/AllocationDrawer';
import { ALLOCATION_COLORS, ALLOCATION_STATUS, INVOICE_COLORS, INVOICE_LABELS, openHostelPrint, useUrlNavKey } from './hostel/shared';

interface Stats {
  active: number;
  boys: number;
  girls: number;
  new_30: number;
  vacated_30: number;
  transferred_30: number;
  not_invoiced: number;
  ending_30: number;
  annual_rent: number;
  free_beds: number;
  total_beds: number;
  pending_fees: number;
  pending_fee_students: number;
  overdue_fees: number;
}

const STATUS_TABS = [
  { key: 'active', label: 'Current residents' },
  { key: 'vacated', label: 'Vacated' },
  { key: 'transferred', label: 'Transferred' },
  { key: 'cancelled', label: 'Cancelled' },
  { key: 'all', label: 'All history' },
];

type Action = { kind: 'vacate' | 'transfer' | 'cancel' | 'invoice'; row: Row } | null;

export default function HostelAllocationsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useHostelRefresh();
  const [params, setParams] = useSearchParams();
  const tableKey = useUrlNavKey();
  const status = STATUS_TABS.some((t) => t.key === params.get('status')) ? String(params.get('status')) : 'active';
  const { data: s, isLoading } = useApi<Stats>(['hostel', 'allocation-stats'], 'hostel/allocations/stats');
  const [drawer, setDrawer] = useState<number | null>(Number(params.get('view')) || null);
  const [allocate, setAllocate] = useState<AllocateDefaults | null>(params.get('new') ? {} : null);
  const [action, setAction] = useState<Action>(null);
  const canEdit = can('hostel', 'edit');

  useEffect(() => {
    const v = Number(params.get('view')) || null;
    if (v) setDrawer(v);
  }, [params]);

  const setParam = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    Object.entries(patch).forEach(([k, v]) => (v === null ? next.delete(k) : next.set(k, v)));
    setParams(next, { replace: true });
  };
  // push (not replace) so the table is re-keyed and picks the new tab / filters up from the URL
  const goFilter = (f: Record<string, string>, st = 'active') => {
    const next = new URLSearchParams();
    next.set('status', st);
    Object.entries(f).forEach(([k, v]) => next.set(`f.${k}`, v));
    setParams(next);
  };

  const bulkInvoice = async (ids: number[], clear: () => void) => {
    const ok = await confirm({ title: 'Raise hostel fee invoices?', message: `An invoice (rent + mess) will be raised for each selected active allocation that does not have one yet (${ids.length} selected).`, confirmText: 'Raise invoices' });
    if (!ok) return;
    try {
      const res = await api.post<{ created: number; failed: number }>('crud/hostel_allocations/bulk', { action: 'invoice', ids, include_mess: 1 });
      if (res.data.failed) toast.warning(res.message);
      else toast.success(res.message);
      clear();
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Room Allocation"
        description="Allot free beds, transfer, vacate and raise hostel fee invoices."
        breadcrumbs={[{ label: 'Hostel', to: '/hostel' }, { label: 'Room Allocation' }]}
        actions={
          <>
            <Button variant="secondary" icon={LayoutGrid} to="/hostel/rooms?tab=grid">Room map</Button>
            {can('hostel', 'create') && <Button icon={UserPlus} onClick={() => setAllocate({})}>Allot room</Button>}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="res" label="Current residents" icon={Users} tone="navy" loading={isLoading} value={s?.active ?? 0} hint={s ? `${formatNumber(s.boys)} boys · ${formatNumber(s.girls)} girls` : undefined} />,
          <StatCard key="new" label="Allotted (30 days)" icon={CalendarPlus} tone="green" loading={isLoading} value={s?.new_30 ?? 0} hint="New allotments" />,
          <StatCard key="out" label="Vacated (30 days)" icon={UserMinus} tone="purple" loading={isLoading} value={s?.vacated_30 ?? 0} hint={s ? `${formatNumber(s.transferred_30)} room transfers` : undefined} />,
          <StatCard key="free" label="Free beds" icon={BedDouble} tone="cyan" loading={isLoading} value={s?.free_beds ?? 0} hint={s ? `of ${formatNumber(s.total_beds)} beds` : undefined} to="/hostel/rooms?tab=grid" />,
          <StatCard key="noinv" label="Fee not invoiced" icon={ReceiptIndianRupee} tone="amber" loading={isLoading} value={s?.not_invoiced ?? 0} hint="Active allotments" />,
          <StatCard
            key="due"
            label="Pending hostel fees"
            icon={IndianRupee}
            tone="red"
            loading={isLoading}
            value={s ? formatMoneyShort(s.pending_fees) : '—'}
            hint={s ? `${formatNumber(s.pending_fee_students)} students with dues` : undefined}
          />,
        ]}
      </Stagger>
      <div className="mb-4 -mt-2 flex flex-wrap gap-2 text-xs">
        {s && s.not_invoiced > 0 && <button type="button" className="link" onClick={() => goFilter({ fee: 'none' })}>Show {s.not_invoiced} allotments without an invoice →</button>}
        {s && s.pending_fee_students > 0 && <button type="button" className="link" onClick={() => goFilter({ fee: 'due' })}>Show residents with hostel fee due →</button>}
      </div>

      <Tabs tabs={STATUS_TABS.map((t) => ({ ...t, count: t.key === 'active' && s ? s.active : undefined }))} value={status} onChange={(k) => goFilter({}, k)} variant="pills" className="mb-4" />

      <CrudTable
        key={tableKey}
        module="hostel_allocations"
        urlState
        scope={status === 'all' ? undefined : { status }}
        title={STATUS_TABS.find((t) => t.key === status)?.label}
        addLabel="Allot room"
        onCreate={() => setAllocate({})}
        emptyTitle={status === 'active' ? 'No students allotted yet' : 'No records'}
        emptyText={status === 'active' ? 'Allot a free bed to a student to get started.' : 'Nothing in this list yet.'}
        onView={(r) => setDrawer(Number(r.id))}
        // the status column repeats the tab on every row except "All history"
        hideColumns={[...(status === 'active' ? ['vacated_on'] : []), ...(status === 'all' ? [] : ['status'])]}
        renderers={{
          student_name: (r) => (
            <button type="button" onClick={() => setDrawer(Number(r.id))} className="block w-[13rem] max-w-full text-left" title={`${r.student_name} · ${r.student_sub ?? ''}`}>
              <PersonCell name={String(r.student_name)} src={r.student_photo as string | null} sub={String(r.student_sub ?? '')} />
            </button>
          ),
          room_label: (r) => (
            <div className="leading-tight">
              <p className="whitespace-nowrap font-semibold text-slate-900 dark:text-white">{String(r.room_label)}</p>
              <p className="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{String(r.room_sub ?? '')}</p>
            </div>
          ),
          invoice_status: (r) =>
            r.invoice_status ? (
              <div className="leading-tight">
                <StatusBadge status={String(r.invoice_status)} label={INVOICE_LABELS[String(r.invoice_status)] ?? String(r.invoice_status)} colors={INVOICE_COLORS} />
                {r.invoice_no && Number(r.invoice_balance) > 0 && <p className="mt-0.5 whitespace-nowrap text-[11px] text-slate-500">{formatMoney(r.invoice_balance)} due</p>}
              </div>
            ) : (
              <span className="text-slate-400">—</span>
            ),
          status: (r) => <StatusBadge status={String(r.status)} label={ALLOCATION_STATUS[String(r.status)]} colors={ALLOCATION_COLORS} />,
        }}
        rowMenu={(r) => {
          const active = r.status === 'active';
          const invoiceable = active && (!r.invoice_no || r.invoice_status === 'cancelled');
          return [
            { label: 'View details', icon: Eye, onClick: () => setDrawer(Number(r.id)) },
            { label: 'Print allotment letter', icon: Printer, onClick: () => openHostelPrint(`allotment/${r.id}`) },
            canEdit && active && { label: 'Transfer room', icon: ArrowRightLeft, onClick: () => setAction({ kind: 'transfer', row: r }) },
            canEdit && active && { label: 'Vacate', icon: LogOut, onClick: () => setAction({ kind: 'vacate', row: r }) },
            canEdit && invoiceable && { label: 'Raise hostel fee invoice', icon: ReceiptIndianRupee, onClick: () => setAction({ kind: 'invoice', row: r }) },
            canEdit && active && { label: 'Cancel allocation', icon: XCircle, danger: true, onClick: () => setAction({ kind: 'cancel', row: r }) },
          ];
        }}
        bulkActions={canEdit && status !== 'cancelled' ? [{ label: 'Raise invoices', icon: ReceiptIndianRupee, onClick: bulkInvoice }] : []}
      />

      <AllocationDrawer
        id={drawer}
        onClose={() => {
          setDrawer(null);
          if (params.get('view')) setParam({ view: null });
        }}
      />
      <AllocateModal
        open={allocate !== null}
        defaults={allocate}
        onClose={() => {
          setAllocate(null);
          if (params.get('new')) setParam({ new: null });
        }}
        onDone={(id) => setDrawer(id)}
      />
      <VacateModal allocation={action?.kind === 'vacate' ? action.row : null} onClose={() => setAction(null)} />
      <TransferModal allocation={action?.kind === 'transfer' ? action.row : null} onClose={() => setAction(null)} />
      <CancelModal allocation={action?.kind === 'cancel' ? action.row : null} onClose={() => setAction(null)} />
      <InvoiceModal allocation={action?.kind === 'invoice' ? action.row : null} onClose={() => setAction(null)} />
    </>
  );
}
