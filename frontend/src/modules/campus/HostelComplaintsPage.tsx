import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlarmClock, CheckCircle2, CirclePlay, Eye, Hourglass, Lock, MessageSquarePlus, MessageSquareWarning, PieChart, Printer, Siren, UserX } from 'lucide-react';
import { Button, Card, CardHeader, EmptyState, PageHeader, PersonCell, Reveal, Skeleton, StatCard, Stagger, StatusBadge, Tabs, useToast } from '@/components/ui';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { BarChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import { useHostelRefresh } from './hostel/AllocationDialogs';
import { ComplaintDrawer } from './hostel/ComplaintDrawer';
import { COMPLAINT_CATEGORIES, COMPLAINT_STATUS, COMPLAINT_STATUS_COLORS, PRIORITY_COLORS, hoursLabel, openHostelPrint, useUrlNavKey } from './hostel/shared';

interface Stats {
  total: number;
  open: number;
  in_progress: number;
  resolved: number;
  closed: number;
  urgent: number;
  unassigned: number;
  ageing: number;
  resolved_month: number;
  raised_month: number;
  avg_hours: number | null;
  by_category: { category: string; label: string; total: number; open: number }[];
}

export default function HostelComplaintsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useHostelRefresh();
  const [params, setParams] = useSearchParams();
  const tableKey = useUrlNavKey();
  const status = ['open', 'in_progress', 'resolved', 'closed'].includes(String(params.get('status'))) ? String(params.get('status')) : 'all';
  const { data: s, isLoading } = useApi<Stats>(['hostel', 'complaint-stats'], 'hostel/complaints/stats');
  const { data: meta } = useCrudMeta('hostel_complaints');
  const [drawer, setDrawer] = useState<number | null>(Number(params.get('view')) || null);
  const [creating, setCreating] = useState(!!params.get('new'));

  useEffect(() => {
    const v = Number(params.get('view')) || null;
    if (v) setDrawer(v);
  }, [params]);

  // push (not replace): the table is re-keyed on navigation and re-reads the tab / filters from the URL
  const go = (st: string, f: Record<string, string> = {}) => {
    const next = new URLSearchParams();
    if (st !== 'all') next.set('status', st);
    Object.entries(f).forEach(([k, v]) => next.set(`f.${k}`, v));
    setParams(next);
  };
  const quick = async (id: number, action: 'start' | 'close') => {
    try {
      const res = await api.post(`hostel/complaints/${id}/${action}`, {});
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const tabs = [
    { key: 'all', label: 'All', count: s?.total },
    { key: 'open', label: 'Open', count: s?.open },
    { key: 'in_progress', label: 'In progress', count: s?.in_progress },
    { key: 'resolved', label: 'Resolved', count: s?.resolved },
    { key: 'closed', label: 'Closed', count: s?.closed },
  ];

  return (
    <>
      <PageHeader
        title="Hostel Complaints"
        description="Maintenance, electrical, plumbing, mess, Wi-Fi and other issues raised by residents — assign, track and resolve."
        breadcrumbs={[{ label: 'Hostel', to: '/hostel' }, { label: 'Complaints' }]}
        actions={can('hostel', 'create') && <Button icon={MessageSquarePlus} onClick={() => setCreating(true)}>New complaint</Button>}
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="open" label="Open" icon={MessageSquareWarning} tone="red" loading={isLoading} value={s?.open ?? 0} hint="Waiting for action" to="/hostel/complaints?status=open" />,
          <StatCard key="prog" label="In progress" icon={CirclePlay} tone="amber" loading={isLoading} value={s?.in_progress ?? 0} hint="Work started" to="/hostel/complaints?status=in_progress" />,
          <StatCard key="urgent" label="High / urgent open" icon={Siren} tone="pink" loading={isLoading} value={s?.urgent ?? 0} hint={s ? `${formatNumber(s.ageing)} open for 3+ days` : undefined} />,
          <StatCard key="unassigned" label="Unassigned" icon={UserX} tone="purple" loading={isLoading} value={s?.unassigned ?? 0} hint="Open, no staff yet" to="/hostel/complaints?f.assignment=unassigned" />,
          <StatCard key="resolved" label="Resolved this month" icon={CheckCircle2} tone="green" loading={isLoading} value={s?.resolved_month ?? 0} hint={s ? `${formatNumber(s.raised_month)} raised this month` : undefined} />,
          <StatCard key="avg" label="Avg. resolution time" icon={Hourglass} tone="cyan" loading={isLoading} value={s ? hoursLabel(s.avg_hours) : '—'} hint="Last 90 days" />,
        ]}
      </Stagger>

      <Tabs tabs={tabs} value={status} onChange={(k) => go(k)} variant="pills" className="mb-4" />

      <CrudTable
        key={tableKey}
        module="hostel_complaints"
        urlState
        scope={status === 'all' ? undefined : { status }}
        title={status === 'all' ? 'All complaints' : `${COMPLAINT_STATUS[status]} complaints`}
        addLabel="New complaint"
        onCreate={() => setCreating(true)}
        emptyTitle="No complaints"
        emptyText="When residents report a problem, log it here and assign it to the right staff member."
        onView={(r) => setDrawer(Number(r.id))}
        renderers={{
          subject: (r) => (
            <button type="button" onClick={() => setDrawer(Number(r.id))} className="block w-[16rem] max-w-full text-left leading-tight" title={`${r.subject} · ${r.complaint_sub ?? ''}`}>
              <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.subject)}</span>
              <span className="mt-1 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <StatusBadge status={String(r.priority)} colors={PRIORITY_COLORS} dot={false} />
                <span className="truncate">{[r.complaint_no, r.location].filter(Boolean).join(' · ')}</span>
              </span>
            </button>
          ),
          category: (r) => <span className="badge badge-slate whitespace-nowrap">{COMPLAINT_CATEGORIES[String(r.category)] ?? String(r.category)}</span>,
          location: (r) => <span className="whitespace-nowrap">{String(r.location)}</span>,
          student_name: (r) =>
            r.student_name ? (
              <div className="w-[10.5rem] max-w-full" title={`${r.student_name} · ${r.student_uid ?? ''}`}>
                <PersonCell name={String(r.student_name)} src={r.student_photo as string | null} sub={String(r.student_uid ?? '')} size="sm" />
              </div>
            ) : (
              <span className="whitespace-nowrap text-xs text-slate-400">Warden office</span>
            ),
          priority: (r) => <StatusBadge status={String(r.priority)} colors={PRIORITY_COLORS} dot={false} />,
          assigned_to: (r) =>
            r.staff_name || r.assigned_to ? (
              <span className="block max-w-[8.5rem] truncate text-sm" title={String(r.assigned_to ?? r.staff_name)}>{String(r.staff_name || r.assigned_to)}</span>
            ) : (
              <span className="text-xs font-medium text-amber-600 dark:text-amber-400">Unassigned</span>
            ),
          status: (r) => (
            <div className="leading-tight">
              <StatusBadge status={String(r.status)} label={COMPLAINT_STATUS[String(r.status)]} colors={COMPLAINT_STATUS_COLORS} />
              {['open', 'in_progress'].includes(String(r.status)) && Number(r.age_hours) >= 72 ? (
                <span className="mt-0.5 flex items-center gap-1 whitespace-nowrap text-[11px] text-red-600 dark:text-red-400"><AlarmClock className="h-3 w-3" />{hoursLabel(Number(r.age_hours))}</span>
              ) : (
                <span className="mt-0.5 block whitespace-nowrap text-[11px] text-slate-500 dark:text-slate-400">{timeAgo(r.created_at)}</span>
              )}
            </div>
          ),
        }}
        rowMenu={(r) => [
          { label: 'Open', icon: Eye, onClick: () => setDrawer(Number(r.id)) },
          can('hostel', 'edit') && r.status === 'open' && { label: 'Start work', icon: CirclePlay, onClick: () => void quick(Number(r.id), 'start') },
          can('hostel', 'edit') && r.status === 'resolved' && { label: 'Close', icon: Lock, onClick: () => void quick(Number(r.id), 'close') },
          { label: 'Print job card', icon: Printer, onClick: () => openHostelPrint(`complaint/${r.id}`) },
        ]}
        onSaved={() => void refresh()}
      />

      <Reveal className="mt-6">
        <Card>
          <CardHeader title="Complaints by category" subtitle="All complaints vs those still open" icon={PieChart} />
          <div className="card-body">
            {isLoading || !s ? (
              <Skeleton className="h-56 w-full" />
            ) : s.by_category.length === 0 ? (
              <EmptyState icon={MessageSquareWarning} title="No complaints yet" className="py-8" />
            ) : (
              <BarChart
                labels={s.by_category.map((c) => c.label)}
                height={240}
                series={[
                  { label: 'Total', data: s.by_category.map((c) => c.total), color: '#1D4ED8' },
                  { label: 'Open / in progress', data: s.by_category.map((c) => c.open), color: '#F97316' },
                ]}
              />
            )}
          </div>
        </Card>
      </Reveal>

      <ComplaintDrawer
        id={drawer}
        onClose={() => {
          setDrawer(null);
          if (params.get('view')) {
            const next = new URLSearchParams(params);
            next.delete('view');
            setParams(next, { replace: true });
          }
        }}
      />
      <CrudFormModal
        open={creating}
        onClose={() => {
          setCreating(false);
          if (params.get('new')) {
            const next = new URLSearchParams(params);
            next.delete('new');
            setParams(next, { replace: true });
          }
        }}
        meta={meta} module="hostel_complaints" onSaved={(_, id) => { void refresh(); setDrawer(id); }} />
    </>
  );
}
