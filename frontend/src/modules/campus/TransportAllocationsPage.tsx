import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowRightLeft, Armchair, Ban, Bus, Eye, IdCard, IndianRupee, Printer, ReceiptIndianRupee, Route as RouteIcon, UserPlus, Users, UserRoundSearch,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, DataTable, EmptyState, PageHeader, Pagination, PersonCell, SearchInput, StatTile, Stagger, StatusBadge, Tabs, type Column,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi, useCrudList } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, formatTime, labelize } from '@/lib/format';
import type { Paginated, Row } from '@/lib/types';
import { OccupancyBar, SummaryStrip, openPrint, useRouteOptions } from './transport/shared';
import { AllocationFormModal, CancelAllocationModal, RaiseInvoiceModal, TransferModal, type AllocatePrefill } from './transport/components/AllocationDialogs';
import type { PendingStudent } from './transport/types';

interface AllocSummary {
  total: number;
  active: number;
  students: number;
  staff: number;
  fee_total: number;
  invoiced: number;
  not_invoiced: number;
  collected: number;
  outstanding: number;
}

export default function TransportAllocationsPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'pending' ? 'pending' : 'allocations';
  const [tableKey, setTableKey] = useState(0);
  const routesQ = useRouteOptions();
  const summaryQ = useCrudList('transport_allocations', { per_page: 1, f: { status: 'active' } });
  const s = summaryQ.data?.summary as AllocSummary | undefined;
  const pendingCount = useApi<Paginated<PendingStudent>>(['transport', 'pending', 'count'], 'transport/pending', { per_page: 1 });

  const [allocOpen, setAllocOpen] = useState(false);
  const [prefill, setPrefill] = useState<AllocatePrefill | null>(null);
  const [transfer, setTransfer] = useState<Row | null>(null);
  const [cancel, setCancel] = useState<{ ids: number[]; label: string; hasInvoice?: boolean; clear?: () => void } | null>(null);
  const [invoice, setInvoice] = useState<{ ids: number[]; label: string; amount?: number; clear?: () => void } | null>(null);

  // Deep links: ?new=1&route=ID&student=ID (from routes, pending list, dashboard)
  useEffect(() => {
    if (params.get('new') === '1' && can('transport', 'create')) {
      setPrefill({ route_id: params.get('route') ? Number(params.get('route')) : undefined, person_type: 'student', person_id: params.get('student') ? Number(params.get('student')) : undefined });
      setAllocOpen(true);
      const next = new URLSearchParams(params);
      ['new', 'route', 'student'].forEach((k) => next.delete(k));
      setParams(next, { replace: true });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const setTab = (t: string) => {
    const next = new URLSearchParams();
    if (t !== 'allocations') next.set('tab', t);
    setParams(next, { replace: true });
  };
  const filterRoute = (routeId: number | null) => {
    const next = new URLSearchParams();
    if (routeId) next.set('f.route_id', String(routeId));
    next.set('f.status', 'active');
    setParams(next, { replace: true });
    setTableKey((k) => k + 1);
  };
  const activeRoute = params.get('f.route_id');

  const routes = routesQ.data ?? [];
  const seats = routes.reduce((a, r) => a + r.capacity, 0);
  const used = routes.reduce((a, r) => a + r.allocated, 0);

  const rowMenu = (r: Row) => {
    const active = r.status === 'active';
    return [
      r.student_id && { label: 'Student profile', icon: Eye, to: `/students/${r.student_id}` },
      { label: `Route ${r.route_code}`, icon: RouteIcon, to: `/transport/routes/${r.route_id}` },
      active && { label: 'Print transport pass', icon: IdCard, onClick: () => openPrint('transport/allocations/pass', { ids: String(r.id) }) },
      active && can('transport', 'edit') && { label: '', divider: true },
      active && can('transport', 'edit') && { label: 'Transfer route / stop…', icon: ArrowRightLeft, onClick: () => setTransfer(r) },
      active && can('transport', 'edit') && r.person_type === 'student' && (!r.invoice_no || r.invoice_status === 'cancelled') && Number(r.fee_amount) > 0 && {
        label: 'Raise fee invoice…', icon: ReceiptIndianRupee, onClick: () => setInvoice({ ids: [Number(r.id)], label: String(r.person_name), amount: Number(r.fee_amount) }),
      },
      r.invoice_no && can('fees', 'view') && { label: `Open invoice ${r.invoice_no}`, icon: IndianRupee, to: `/fees/invoices?q=${encodeURIComponent(String(r.invoice_no))}` },
      active && can('transport', 'edit') && { label: 'Cancel allocation…', icon: Ban, danger: true, onClick: () => setCancel({ ids: [Number(r.id)], label: String(r.person_name), hasInvoice: !!r.invoice_no && r.invoice_status !== 'cancelled' }) },
    ];
  };

  return (
    <>
      <PageHeader
        title="Transport Allocations"
        description="Assign students and staff to a route and pickup stop, raise transport fee invoices, transfer or cancel seats."
        breadcrumbs={[{ label: 'Transport', to: '/transport' }, { label: 'Allocations' }]}
        actions={
          can('transport', 'create') && (
            <Button icon={UserPlus} onClick={() => { setPrefill(null); setAllocOpen(true); }}>
              Allocate seat
            </Button>
          )
        }
      >
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'allocations', label: 'Allocations', icon: Bus, count: s?.active },
            { key: 'pending', label: 'Awaiting a seat', icon: UserRoundSearch, count: pendingCount.data?.total },
          ]}
        />
      </PageHeader>

      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" step={60}>
        {[
          <StatTile key="a" label="Active riders" value={s?.active ?? 0} icon={Users} tone="navy" loading={summaryQ.isLoading} sub={s ? `${s.students} students · ${s.staff} staff` : undefined} />,
          <StatTile key="s" label="Seats available" value={Math.max(0, seats - used)} icon={Armchair} tone="green" loading={routesQ.isLoading}
            sub={seats ? `${Math.round((used * 100) / seats)}% of ${formatNumber(seats)} seats filled` : 'No vehicles on routes'} />,
          <StatTile key="p" label="Awaiting a seat" value={pendingCount.data?.total ?? 0} icon={UserRoundSearch} tone="amber" loading={pendingCount.isLoading} sub="Day scholars who opted for transport" />,
          <StatTile key="f" label="Transport fees (annual)" value={s ? formatMoneyShort(s.fee_total) : '—'} icon={IndianRupee} tone="orange" loading={summaryQ.isLoading}
            sub={s ? `${formatMoneyShort(s.collected)} collected · ${formatMoneyShort(s.outstanding)} due` : undefined} />,
        ]}
      </Stagger>

      {tab === 'allocations' ? (
        <>
          {/* Route capacity strip */}
          <Card className="mb-5 p-4">
            <div className="mb-3 flex items-center justify-between gap-2">
              <p className="text-sm font-semibold text-slate-800 dark:text-slate-100">Route capacity</p>
              {activeRoute && <button type="button" onClick={() => filterRoute(null)} className="text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">Show all routes</button>}
            </div>
            {routesQ.error ? (
              <Alert variant="error">Unable to load route capacity.</Alert>
            ) : (
              <div className="-mx-1 flex gap-2.5 overflow-x-auto px-1 pb-1 scrollbar-none">
                {routesQ.isLoading
                  ? Array.from({ length: 8 }).map((_, i) => <div key={i} className="skeleton h-[4.25rem] w-40 shrink-0 rounded-xl" />)
                  : routes.map((r) => {
                      const on = activeRoute === String(r.id);
                      return (
                        <button
                          key={r.id}
                          type="button"
                          onClick={() => filterRoute(r.id)}
                          aria-pressed={on}
                          className={clsx(
                            'w-40 shrink-0 rounded-xl border p-2.5 text-left transition hover:-translate-y-0.5 hover:shadow-soft',
                            on ? 'border-brand-400 bg-brand-50 dark:border-brand-500/60 dark:bg-brand-500/10' : 'border-slate-200 bg-white dark:border-slate-800 dark:bg-slate-900',
                          )}
                        >
                          <p className="truncate text-xs font-semibold text-slate-900 dark:text-white">{r.code} <span className="font-normal text-slate-500">· {r.name}</span></p>
                          <OccupancyBar allocated={r.allocated} capacity={r.capacity} className="mt-1.5 !min-w-0" />
                        </button>
                      );
                    })}
              </div>
            )}
          </Card>

          <CrudTable
            key={tableKey}
            module="transport_allocations"
            urlState
            title="Allocations"
            addLabel="Allocate seat"
            onCreate={() => { setPrefill(null); setAllocOpen(true); }}
            rowMenu={rowMenu}
            emptyTitle="No allocations yet"
            emptyText="Allocate the first seat to a student or staff member."
            renderers={{
              person_name: (r) => (
                <div className="flex max-w-[15rem] items-center gap-2">
                  <PersonCell name={String(r.person_name ?? '—')} sub={r.person_sub} src={r.person_photo} to={r.student_id ? `/students/${r.student_id}` : undefined} />
                  {r.person_type !== 'student' && <Badge color="purple">{labelize(String(r.person_type))}</Badge>}
                </div>
              ),
              route_code: (r) => (
                <div className="leading-tight">
                  <span className="font-semibold text-slate-900 dark:text-white">{r.route_code}</span>
                  <div className="max-w-[10rem] truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.route_name ?? '')}>{r.route_name}</div>
                </div>
              ),
              stop_name: (r) => (
                <div className="leading-tight">
                  <span className="block max-w-[9rem] truncate font-medium text-slate-800 dark:text-slate-100" title={String(r.stop_name ?? '')}>{r.stop_name ?? '—'}</span>
                  {r.pickup_time && <span className="text-xs text-slate-500 dark:text-slate-400">Pickup {formatTime(r.pickup_time)}</span>}
                </div>
              ),
              fee_amount: (r) => (
                <div className="whitespace-nowrap leading-tight">
                  <div className="flex items-center gap-1.5">
                    <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatMoney(r.fee_amount)}</span>
                    {r.invoice_no && <StatusBadge status={r.invoice_status} dot={false} />}
                  </div>
                  <div className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">
                    {r.invoice_no ? r.invoice_no : r.person_type === 'student' ? <span className="text-amber-600 dark:text-amber-400">Not invoiced</span> : 'Staff · no invoice'}
                  </div>
                </div>
              ),
              invoice_no: (r) =>
                r.invoice_no ? (
                  <div className="leading-tight">
                    <span className="whitespace-nowrap text-xs font-semibold text-slate-800 dark:text-slate-100">{r.invoice_no}</span>
                    <div className="mt-0.5"><StatusBadge status={r.invoice_status} dot={false} /></div>
                  </div>
                ) : r.person_type === 'student' ? (
                  <span className="text-xs text-slate-400">Not invoiced</span>
                ) : (
                  <span className="text-xs text-slate-400">Staff — no invoice</span>
                ),
            }}
            bulkActions={[
              ...(can('transport', 'edit') ? [{ label: 'Raise invoices', icon: ReceiptIndianRupee, onClick: (ids: number[], clear: () => void) => setInvoice({ ids, label: `${ids.length} allocations`, clear }) }] : []),
              { label: 'Print passes', icon: Printer, onClick: (ids: number[]) => openPrint('transport/allocations/pass', { ids: ids.join(',') }) },
              ...(can('transport', 'edit') ? [{ label: 'Cancel', icon: Ban, danger: true, onClick: (ids: number[], clear: () => void) => setCancel({ ids, label: `${ids.length} allocations`, clear }) }] : []),
            ]}
            header={({ summary }) => {
              const sm = summary as AllocSummary | null | undefined;
              return sm ? (
                <SummaryStrip
                  items={[
                    { label: 'Records', value: formatNumber(sm.total) },
                    { label: 'Active', value: formatNumber(sm.active), tone: 'green' },
                    { label: 'Annual fee (active)', value: formatMoney(sm.fee_total), tone: 'blue' },
                    { label: 'Collected', value: formatMoney(sm.collected ?? 0), tone: 'green' },
                    { label: 'Outstanding', value: formatMoney(sm.outstanding ?? 0), tone: sm.outstanding ? 'amber' : 'green' },
                    { label: 'Not invoiced', value: formatNumber(sm.not_invoiced), tone: sm.not_invoiced ? 'amber' : 'green' },
                  ]}
                />
              ) : null;
            }}
          />
        </>
      ) : (
        <PendingPanel
          onAllocate={(st) => {
            setPrefill({ person_type: 'student', person_id: st.id, person_label: `${st.name} (${st.student_uid})` });
            setAllocOpen(true);
          }}
        />
      )}

      <AllocationFormModal open={allocOpen} onClose={() => setAllocOpen(false)} prefill={prefill} />
      <TransferModal allocation={transfer} onClose={() => setTransfer(null)} />
      <CancelAllocationModal target={cancel} onClose={() => setCancel(null)} onDone={() => cancel?.clear?.()} />
      <RaiseInvoiceModal target={invoice} onClose={() => setInvoice(null)} onDone={() => invoice?.clear?.()} />
    </>
  );
}

function PendingPanel({ onAllocate }: { onAllocate: (s: PendingStudent) => void }) {
  const { can } = useAuth();
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const dq = useDebounce(q, 300);
  useEffect(() => setPage(1), [dq]);
  const query = useMemo(() => ({ q: dq || undefined, page, per_page: 15 }), [dq, page]);
  const { data, isLoading, isFetching, error } = useApi<Paginated<PendingStudent>>(['transport', 'pending', query], 'transport/pending', query, { placeholderData: (p) => p });
  const columns: Column<PendingStudent>[] = [
    {
      key: 'name',
      header: 'Student',
      render: (r) => (
        <PersonCell name={r.name} src={r.photo} to={`/students/${r.id}`} sub={`${r.student_uid}${r.program ? ` · ${r.program} Sem ${r.current_semester ?? ''}` : ''}`} />
      ),
    },
    { key: 'mobile', header: 'Mobile', render: (r) => (r.mobile ? <a href={`tel:${r.mobile.replace(/\s/g, '')}`} className="whitespace-nowrap hover:text-brand-700">{r.mobile}</a> : '—') },
    { key: 'city', header: 'Lives in', render: (r) => <span className="block max-w-[16rem] truncate" title={r.address ?? ''}>{[r.city, r.address].filter(Boolean).join(' · ') || '—'}</span> },
    { key: 'admission_date', header: 'Admitted', render: (r) => <span className="whitespace-nowrap">{formatDate(r.admission_date)}</span> },
  ];
  return (
    <Card className="overflow-hidden">
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800">
        <div>
          <h2 className="card-title">Awaiting a seat</h2>
          <p className="card-subtitle">Day scholars who opted for college transport at admission but have no active allocation.</p>
        </div>
        <SearchInput value={q} onChange={setQ} placeholder="Search name, ID or area…" className="w-full sm:max-w-xs" />
      </div>
      {error ? (
        <div className="p-6"><Alert variant="error">Unable to load the waiting list.</Alert></div>
      ) : (
        <DataTable<PendingStudent>
          columns={columns}
          rows={data?.rows ?? []}
          loading={isLoading || isFetching}
          skeletonRows={6}
          caption="Students awaiting transport"
          empty={<EmptyState icon={UserRoundSearch} title={dq ? 'No matching students' : 'Everyone has a seat'} description={dq ? 'Try another search.' : 'All day scholars who opted for transport are allocated.'} />}
          actions={(r) =>
            can('transport', 'create') ? (
              <Button size="sm" icon={UserPlus} onClick={() => onAllocate(r)}>Allocate</Button>
            ) : (
              <span className="text-xs text-slate-400">—</span>
            )
          }
        />
      )}
      {data && data.total > 0 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} />}
    </Card>
  );
}
