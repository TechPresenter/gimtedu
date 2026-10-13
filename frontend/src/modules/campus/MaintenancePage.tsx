import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { CalendarClock, CheckCircle2, Eye, Fuel, IndianRupee, MessageSquareWarning, PlayCircle, Wrench } from 'lucide-react';
import { PageHeader, StatTile, Stagger, StatusBadge, Tabs } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { useApi, useCrudList, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatMoneyShort, formatNumber, isoDate, labelize, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { COMPLAINT_CATEGORIES, COMPLAINT_COLORS, ExpiryCell, PRIORITY_COLORS, SummaryStrip, useTransportRefresh } from './transport/shared';
import { FuelLogPanel } from './transport/components/FuelLogPanel';
import { UpcomingServicesPanel } from './transport/components/UpcomingServicesPanel';
import { CompleteJobModal, type JobTarget } from './transport/components/CompleteJobModal';
import { ComplaintDrawer } from './transport/components/ComplaintDrawer';
import type { UpcomingService } from './transport/types';

const TYPE_SHORT: Record<string, string> = { service: 'Service', repair: 'Repair', tyre: 'Tyres', battery: 'Battery', insurance: 'Insurance', fitness: 'Fitness', permit: 'Permit', puc: 'PUC', other: 'Other' };
const TYPE_COLORS = { service: 'blue', repair: 'amber', tyre: 'slate', battery: 'cyan', insurance: 'purple', fitness: 'navy', permit: 'navy', puc: 'green', other: 'slate' } as const;

type TabKey = 'records' | 'upcoming' | 'fuel' | 'complaints';
const TABS: TabKey[] = ['records', 'upcoming', 'fuel', 'complaints'];

interface MaintSummary {
  records: number;
  cost: number;
  scheduled: number;
  in_progress: number;
  session_cost: number;
}
interface ComplaintSummary {
  total: number;
  open: number;
  in_progress: number;
  resolved: number;
  closed: number;
  critical: number;
  avg_hours: number | null;
}

export default function MaintenancePage() {
  const { can } = useAuth();
  const refresh = useTransportRefresh();
  const [params, setParams] = useSearchParams();
  const tab: TabKey = TABS.includes(params.get('tab') as TabKey) ? (params.get('tab') as TabKey) : 'records';
  const maintQ = useCrudList('transport_maintenance', { per_page: 1 });
  const complaintsQ = useCrudList('transport_complaints', { per_page: 1 });
  const upcomingQ = useApi<{ rows: UpcomingService[] }>(['transport', 'upcoming', '30'], 'transport/maintenance/upcoming', { days: '30' });
  const ms = maintQ.data?.summary as MaintSummary | undefined;
  const cs = complaintsQ.data?.summary as ComplaintSummary | undefined;
  const dueCount = (upcomingQ.data?.rows ?? []).filter((r) => r.status !== 'in_progress').length;

  const complaintMeta = useCrudMeta('transport_complaints');
  const [job, setJob] = useState<JobTarget | null>(null);
  const [complaintId, setComplaintId] = useState<number | null>(null);
  const [editComplaint, setEditComplaint] = useState<number | null>(null);
  const maintDefaults = useMemo(() => ({ service_date: isoDate(), status: 'completed', type: 'service' }), []);

  // Deep link ?complaint=ID (dashboard, route detail)
  useEffect(() => {
    const id = Number(params.get('complaint'));
    if (id) setComplaintId(id);
  }, [params]);

  const setTab = (t: string) => {
    const next = new URLSearchParams();
    if (t !== 'records') next.set('tab', t);
    setParams(next, { replace: true });
  };
  const closeComplaint = () => {
    setComplaintId(null);
    if (params.get('complaint')) {
      const next = new URLSearchParams(params);
      next.delete('complaint');
      setParams(next, { replace: true });
    }
  };

  const maintMenu = (r: Row) => [
    r.status !== 'completed' && can('transport', 'edit') && {
      label: 'Mark completed…', icon: CheckCircle2,
      onClick: () => setJob({ id: Number(r.id), vehicle_no: String(r.vehicle_no), type: String(r.type), service_date: String(r.service_date), cost: r.cost, vendor: r.vendor, description: r.description, odometer: r.current_odometer }),
    },
    { label: `Vehicle ${r.vehicle_no}`, icon: Eye, to: `/transport/vehicles?q=${encodeURIComponent(String(r.vehicle_no))}` },
  ];

  const complaintMenu = (r: Row) => [
    { label: 'Open & update status', icon: PlayCircle, onClick: () => setComplaintId(Number(r.id)) },
    r.route_id && { label: `Route ${r.route_code}`, icon: Eye, to: `/transport/routes/${r.route_id}` },
  ];

  return (
    <>
      <PageHeader
        title="Fuel & Maintenance"
        description="Service and repair records, upcoming services, the fuel log and transport complaints."
        breadcrumbs={[{ label: 'Transport', to: '/transport' }, { label: 'Fuel & Maintenance' }]}
      >
        <Tabs
          value={tab}
          onChange={setTab}
          tabs={[
            { key: 'records', label: 'Maintenance', icon: Wrench, count: ms?.records },
            { key: 'upcoming', label: 'Upcoming services', icon: CalendarClock, count: upcomingQ.data?.rows.length },
            { key: 'fuel', label: 'Fuel log', icon: Fuel },
            { key: 'complaints', label: 'Complaints', icon: MessageSquareWarning, count: cs ? cs.open + cs.in_progress : undefined },
          ]}
        />
      </PageHeader>

      {tab !== 'fuel' && (
        <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 xl:grid-cols-4" step={60}>
          {[
            <StatTile key="c" label="Maintenance spend (session)" value={ms ? formatMoneyShort(ms.session_cost) : '—'} icon={IndianRupee} tone="orange" loading={maintQ.isLoading}
              sub={ms ? `${formatMoney(ms.cost)} across all records` : undefined} />,
            <StatTile key="d" label="Services due (30 days)" value={dueCount} icon={CalendarClock} tone={dueCount ? 'amber' : 'green'} loading={upcomingQ.isLoading}
              sub={ms ? `${ms.scheduled} scheduled jobs` : undefined} />,
            <StatTile key="w" label="In workshop" value={ms?.in_progress ?? 0} icon={Wrench} tone="purple" loading={maintQ.isLoading} sub="Vehicles off the road" />,
            <StatTile key="o" label="Open complaints" value={cs ? cs.open + cs.in_progress : 0} icon={MessageSquareWarning} tone={cs?.critical ? 'red' : 'blue'} loading={complaintsQ.isLoading}
              sub={cs ? `${cs.critical} high / urgent${cs.avg_hours ? ` · avg ${cs.avg_hours}h to resolve` : ''}` : undefined} />,
          ]}
        </Stagger>
      )}

      {tab === 'records' && (
        <CrudTable
          module="transport_maintenance"
          urlState
          title="Maintenance records"
          addLabel="Add record"
          rowMenu={maintMenu}
          formDefaults={maintDefaults}
          onSaved={() => refresh('transport_maintenance', 'vehicles')}
          emptyText="Record services, repairs, tyres and statutory renewals for each vehicle."
          renderers={{
            vehicle_no: (r) => (
              <div className="whitespace-nowrap leading-tight">
                <span className="font-semibold text-slate-900 dark:text-white">{r.vehicle_no}</span>
                <span className="block max-w-[9rem] truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.vehicle_sub ?? '')}>{r.vehicle_sub}</span>
              </div>
            ),
            service_date: (r) => (
              <div className="whitespace-nowrap leading-tight">
                <span>{formatDate(r.service_date)}</span>
                {r.odometer ? <div className="text-xs text-slate-500 dark:text-slate-400">{formatNumber(Number(r.odometer))} km</div> : null}
              </div>
            ),
            type: (r) => (
              <div className="max-w-[15rem] leading-tight">
                <div className="flex min-w-0 items-center gap-1.5">
                  <StatusBadge status={String(r.type)} label={TYPE_SHORT[String(r.type)] ?? undefined} colors={TYPE_COLORS} dot={false} />
                  {r.vendor && <span className="truncate text-xs font-medium text-slate-700 dark:text-slate-200" title={`${r.vendor}${r.bill_no ? ` · ${r.bill_no}` : ''}`}>{r.vendor}</span>}
                </div>
                {r.description && <span className="mt-1 block truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.description)}>{r.description}</span>}
              </div>
            ),
            description: (r) => <span className="block max-w-[16rem] truncate" title={String(r.description ?? '')}>{r.description ?? '—'}</span>,
            vendor: (r) => (
              <div className="leading-tight">
                <span className="block max-w-[9rem] truncate font-medium text-slate-800 dark:text-slate-100" title={String(r.vendor ?? '')}>{r.vendor ?? '—'}</span>
                {r.bill_no && <span className="text-xs text-slate-500 dark:text-slate-400">{r.bill_no}</span>}
              </div>
            ),
            next_service_date: (r) =>
              r.next_service_date ? (
                ['insurance', 'fitness', 'permit', 'puc'].includes(String(r.type)) || r.status !== 'completed' ? (
                  <span className="whitespace-nowrap text-sm">{formatDate(r.next_service_date)}</span>
                ) : (
                  <ExpiryCell date={r.next_service_date} compact />
                )
              ) : (
                <span className="text-slate-400">—</span>
              ),
            status: (r) => <StatusBadge status={r.status} colors={{ scheduled: 'amber', in_progress: 'blue', completed: 'green' }} label={r.status === 'in_progress' ? 'In workshop' : undefined} />,
          }}
          header={({ summary }) => {
            const sm = summary as MaintSummary | null | undefined;
            return sm ? (
              <SummaryStrip
                items={[
                  { label: 'Records', value: formatNumber(sm.records) },
                  { label: 'Spend (completed)', value: formatMoney(sm.cost), tone: 'blue' },
                  { label: 'Scheduled', value: formatNumber(sm.scheduled), tone: sm.scheduled ? 'amber' : 'slate' },
                  { label: 'In workshop', value: formatNumber(sm.in_progress), tone: sm.in_progress ? 'amber' : 'slate' },
                ]}
              />
            ) : null;
          }}
        />
      )}

      {tab === 'upcoming' && <UpcomingServicesPanel />}

      {tab === 'fuel' && <FuelLogPanel />}

      {tab === 'complaints' && (
        <CrudTable
          module="transport_complaints"
          urlState
          title="Transport complaints"
          addLabel="Register complaint"
          onView={(r) => setComplaintId(Number(r.id))}
          rowMenu={complaintMenu}
          onSaved={() => refresh('transport_complaints')}
          emptyTitle="No complaints"
          emptyText="Register complaints from students, parents or staff about routes, vehicles or crew."
          renderers={{
            subject: (r) => (
              <button type="button" onClick={() => setComplaintId(Number(r.id))} className="max-w-[16rem] text-left leading-tight">
                <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300" title={String(r.subject)}>{r.subject}</span>
                <span className="block max-w-[16rem] truncate text-xs text-slate-500 dark:text-slate-400">{r.complaint_no} · {COMPLAINT_CATEGORIES[String(r.category)] ?? labelize(String(r.category))} · {timeAgo(r.created_at)}</span>
              </button>
            ),
            complainant_name: (r) => (
              <div className="leading-tight">
                <span className="block max-w-[10rem] truncate font-medium text-slate-800 dark:text-slate-100" title={String(r.complainant_name)}>{r.complainant_name}</span>
                <span className="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{r.student_uid ?? r.phone ?? ''}</span>
              </div>
            ),
            priority: (r) => <StatusBadge status={r.priority} colors={PRIORITY_COLORS} />,
            status: (r) => <StatusBadge status={r.status} colors={COMPLAINT_COLORS} />,
            assigned_name: (r) => (r.assigned_name ? <span className="whitespace-nowrap">{r.assigned_name}</span> : <span className="text-xs text-slate-400">Unassigned</span>),
          }}
          header={({ summary }) => {
            const sm = summary as ComplaintSummary | null | undefined;
            return sm ? (
              <SummaryStrip
                items={[
                  { label: 'Open', value: formatNumber(sm.open), tone: sm.open ? 'red' : 'green' },
                  { label: 'In progress', value: formatNumber(sm.in_progress), tone: sm.in_progress ? 'amber' : 'slate' },
                  { label: 'Resolved', value: formatNumber(sm.resolved), tone: 'green' },
                  { label: 'Closed', value: formatNumber(sm.closed) },
                  { label: 'Avg. resolution', value: sm.avg_hours ? `${sm.avg_hours} h` : '—' },
                ]}
              />
            ) : null;
          }}
        />
      )}

      <CompleteJobModal job={job} onClose={() => setJob(null)} />
      <ComplaintDrawer complaintId={complaintId} onClose={closeComplaint} onEdit={(id) => setEditComplaint(id)} />
      <CrudFormModal open={editComplaint !== null} onClose={() => setEditComplaint(null)} meta={complaintMeta.data} module="transport_complaints" id={editComplaint}
        onSaved={() => refresh('transport_complaints')} />
    </>
  );
}
