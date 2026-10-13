import { useMemo } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { BriefcaseBusiness, CalendarClock, CheckCircle2, Download, ExternalLink, PlayCircle, UserPlus, Users } from 'lucide-react';
import { PageHeader, Stagger, StatTile, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError, downloadFile } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { driveTransitions } from './placement/driveActions';
import { CompanyLogo, DRIVE_STATUS, DRIVE_STATUS_COLORS, JOB_TYPES, relativeDays, usePlacementRefresh } from './placement/shared';

interface DriveStats {
  session: string;
  total: number;
  upcoming: number;
  ongoing: number;
  completed: number;
  applicants: number;
  next: { id: number; title: string; drive_date: string; company_name: string } | null;
}

const TABS = [
  { key: 'all', label: 'All drives' },
  { key: 'active', label: 'Upcoming & ongoing' },
  { key: 'completed', label: 'Completed' },
  { key: 'cancelled', label: 'Cancelled' },
];

export default function DrivesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = usePlacementRefresh();
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? (params.get('tab') as string) : 'all';
  const company = Number(params.get('company') ?? 0) || null;
  const { data: st, isLoading } = useApi<DriveStats>(['placement', 'stats', 'drives'], 'placement/stats/drives');
  const defaults = useMemo(() => (tab === 'all' ? undefined : { phase: tab }), [tab]);
  const formDefaults = useMemo(() => (company ? { company_id: company } : undefined), [company]);

  const setTab = (k: string) => setParams(k === 'all' ? {} : { tab: k });

  const setStatus = async (row: Row, status: string, question?: string) => {
    if (question) {
      const ok = await confirm({ title: 'Change drive status?', message: question, confirmText: 'Continue', danger: status === 'cancelled' });
      if (!ok) return;
    }
    try {
      await api.post(`crud/placement_drives/${row.id}`, { status });
      toast.success(`Drive marked ${status}.`);
      await refresh('placement_drives');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Placement Drives"
        description="Campus, pool and virtual recruitment drives — roles, CTC, eligibility and selection rounds."
        breadcrumbs={[{ label: 'Placement', to: '/placement' }, { label: 'Drives' }]}
      />
      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="t" label={`Drives in ${st?.session ?? 'this session'}`} value={st?.total ?? 0} icon={BriefcaseBusiness} tone="navy" loading={isLoading} sub={st ? `${st.completed} completed` : undefined} />,
          <StatTile key="u" label="Upcoming & open" value={st?.upcoming ?? 0} icon={CalendarClock} tone="amber" loading={isLoading}
            sub={st?.next ? <Link to={`/placement/drives/${st.next.id}`} className="hover:text-brand-700">Next: {st.next.company_name} · {relativeDays(st.next.drive_date)}</Link> : 'Nothing scheduled'} />,
          <StatTile key="o" label="Ongoing now" value={st?.ongoing ?? 0} icon={PlayCircle} tone="blue" loading={isLoading} sub="Rounds in progress" />,
          <StatTile key="a" label="Applications" value={st?.applicants ?? 0} icon={Users} tone="green" loading={isLoading} sub={st && st.total ? `${Math.round(st.applicants / st.total)} per drive on average` : undefined} />,
        ]}
      </Stagger>

      <Tabs tabs={TABS} value={tab} onChange={setTab} variant="pills" className="mb-4" />

      <CrudTable
        key={tab}
        module="placement_drives"
        urlState
        title={TABS.find((t) => t.key === tab)?.label}
        defaultFilters={defaults}
        hideFilters={tab === 'all' ? [] : ['phase', 'status']}
        formDefaults={formDefaults}
        addLabel="New drive"
        emptyTitle={tab === 'all' ? 'No placement drives yet' : 'No drives here'}
        emptyText="Create a drive with the company, role, CTC and eligibility criteria, then register eligible students."
        viewTo={(r: Row) => `/placement/drives/${r.id}`}
        rowMenu={(r: Row) => [
          { label: 'Open drive', icon: ExternalLink, to: `/placement/drives/${r.id}` },
          can('placement', 'create') && !['completed', 'cancelled'].includes(String(r.status)) && { label: 'Register eligible students', icon: UserPlus, to: `/placement/drives/${r.id}?tab=eligible` },
          { label: 'Applicants', icon: Users, to: `/placement/applications?f.drive_id=${r.id}` },
          can('placement', 'export') && { label: 'Export applicants (Excel)', icon: Download, onClick: () => downloadFile('crud/placement_applications/export', { format: 'xlsx', f: { drive_id: r.id } }) },
          ...(can('placement', 'edit') ? [{ label: '', divider: true }, ...driveTransitions(String(r.status)).map((t) => ({ label: t.label, icon: t.icon, danger: t.danger, onClick: () => void setStatus(r, t.status, t.confirm) }))] : []),
        ]}
        renderers={{
          title: (r: Row) => (
            <Link to={`/placement/drives/${r.id}`} className="group flex min-w-[14rem] max-w-[19rem] items-center gap-3">
              <CompanyLogo name={String(r.company_name)} src={r.company_logo as string | null} size="sm" wide />
              <span className="min-w-0 leading-tight">
                <span className="block truncate font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300" title={String(r.title)}>{String(r.title)}</span>
                <span className="block truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.job_role)}>{String(r.company_name)} · {String(r.job_role)}</span>
              </span>
            </Link>
          ),
          job_type: (r: Row) => <span className="whitespace-nowrap text-sm text-slate-700 dark:text-slate-200">{JOB_TYPES[String(r.job_type)] ?? String(r.job_type)}</span>,
          ctc_label: (r: Row) => (
            <div className="leading-tight">
              {r.ctc_label ? <div className="whitespace-nowrap font-medium tabular-nums">{String(r.ctc_label)}</div> : <div className="text-slate-400">{r.stipend ? 'Stipend' : '—'}</div>}
              <div className="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{JOB_TYPES[String(r.job_type)] ?? String(r.job_type)}</div>
            </div>
          ),
          status: (r: Row) => <span className="whitespace-nowrap"><StatusBadge status={String(r.status)} label={DRIVE_STATUS[String(r.status)]} colors={DRIVE_STATUS_COLORS} /></span>,
          drive_date: (r: Row) =>
            r.drive_date ? (
              <div className="leading-tight">
                <div className="whitespace-nowrap">{formatDate(r.drive_date)}</div>
                {['upcoming', 'open', 'ongoing'].includes(String(r.status)) && <div className="text-xs text-slate-500 dark:text-slate-400">{relativeDays(String(r.drive_date))}</div>}
              </div>
            ) : (
              <span className="text-slate-400">TBA</span>
            ),
          applicants_count: (r: Row) => (
            <Link to={`/placement/applications?f.drive_id=${r.id}`} className="block leading-tight hover:text-brand-700 dark:hover:text-brand-300">
              <span className="block font-medium tabular-nums">{Number(r.applicants_count)}</span>
              {Number(r.selected_count) > 0 && (
                <span className="inline-flex items-center gap-0.5 whitespace-nowrap text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                  <CheckCircle2 className="h-3 w-3" />
                  {Number(r.selected_count)} selected
                </span>
              )}
            </Link>
          ),
          selected_count: (r: Row) =>
            Number(r.selected_count) ? (
              <span className="inline-flex items-center gap-1 tabular-nums font-semibold text-emerald-700 dark:text-emerald-400">
                <CheckCircle2 className="h-3.5 w-3.5" />
                {Number(r.selected_count)}
              </span>
            ) : (
              <span className="text-slate-400">0</span>
            ),
        }}
        onSaved={() => void refresh('placement_drives')}
      />
    </>
  );
}
