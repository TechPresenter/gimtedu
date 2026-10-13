import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { BadgeCheck, CalendarClock, ClipboardList, ExternalLink, Eye, FileUser, Hourglass, ListChecks, Trophy } from 'lucide-react';
import { Badge, PageHeader, Stagger, StatTile, StatusBadge, Tabs } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber, formatTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ApplicationDrawer } from './placement/ApplicationDrawer';
import { PickApplicationRoundModal } from './placement/RecordRoundModal';
import { APP_ACTIONS, ApplicationActionModal, useApplicationBulkActions, type PendingBulk } from './placement/applicationBulk';
import { IssueOfferModal, offerPresetFromRow, type OfferPreset } from './placement/offers';
import { APP_STATUS, APP_STATUS_COLORS, CompanyLogo, OFFER_STATUS_COLORS, OFFER_STATUS_SHORT, ROUND_RESULT, ROUND_RESULT_COLORS, StudentCell, usePlacementRefresh } from './placement/shared';

interface AppStats {
  session: string;
  total: number;
  in_process: number;
  selected: number;
  rejected: number;
  upcoming_interviews: number;
  selection_rate: number;
  awaiting_offer: number;
}

const TABS = [
  { key: 'applications', label: 'Applications', icon: FileUser },
  { key: 'interviews', label: 'Interview rounds', icon: CalendarClock },
];

export default function PlacementApplicationsPage() {
  const { can } = useAuth();
  const refresh = usePlacementRefresh();
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'interviews' ? 'interviews' : 'applications';
  const openId = Number(params.get('app') ?? 0) || null;
  const [pending, setPending] = useState<PendingBulk | null>(null);
  const [offerPreset, setOfferPreset] = useState<OfferPreset | null>(null);
  const [pickOpen, setPickOpen] = useState(false);
  const { data: st, isLoading } = useApi<AppStats>(['placement', 'stats', 'applications'], 'placement/stats/applications');
  const bulkActions = useApplicationBulkActions((action, ids, clear) => setPending({ action, ids, clear }));

  const open = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('app', String(id));
    else next.delete('app');
    setParams(next, { replace: !id });
  };
  const setTab = (k: string) => setParams(k === 'applications' ? {} : { tab: k });

  return (
    <>
      <PageHeader
        title="Applications & Interviews"
        description="Drive registrations, round-by-round progress and selections."
        breadcrumbs={[{ label: 'Placement', to: '/placement' }, { label: 'Applications' }]}
      />
      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="t" label={`Applications · ${st?.session ?? ''}`} value={st?.total ?? 0} icon={ClipboardList} tone="navy" loading={isLoading} sub={st ? `${formatNumber(st.in_process)} still in process` : undefined} />,
          <StatTile key="s" label="Selected" value={st?.selected ?? 0} icon={Trophy} tone="green" loading={isLoading} sub={st ? `${st.selection_rate}% of decided applications` : undefined} />,
          <StatTile key="u" label="Upcoming interviews" value={st?.upcoming_interviews ?? 0} icon={CalendarClock} tone="amber" loading={isLoading} sub="Rounds awaiting results" />,
          <StatTile key="o" label="Selected, offer pending" value={st?.awaiting_offer ?? 0} icon={Hourglass} tone="purple" loading={isLoading} sub="Record their offers" />,
        ]}
      </Stagger>

      <Tabs tabs={TABS} value={tab} onChange={setTab} variant="pills" className="mb-4" />

      {tab === 'applications' ? (
        <CrudTable
          key="applications"
          module="placement_applications"
          urlState
          title="Applications"
          addLabel="Add application"
          emptyTitle="No applications yet"
          emptyText="Register eligible students from a drive, or add an application for a single student."
          onView={(r: Row) => open(Number(r.id))}
          bulkActions={bulkActions}
          rowMenu={(r: Row) => [
            { label: 'View details & rounds', icon: Eye, onClick: () => open(Number(r.id)) },
            can('placement', 'create') && r.status === 'selected' && !r.offer_status && r.job_type !== 'internship' && { label: 'Record offer', icon: BadgeCheck, onClick: () => setOfferPreset(offerPresetFromRow(r)) },
            can('placement', 'edit') && r.status === 'applied' && { label: APP_ACTIONS.shortlist.label, icon: ListChecks, onClick: () => setPending({ action: 'shortlist', ids: [Number(r.id)] }) },
            { label: 'Open drive', icon: ExternalLink, to: `/placement/drives/${r.drive_id}` },
          ]}
          renderers={{
            student_name: (r: Row) => <StudentCell name={String(r.student_name)} sub={String(r.student_sub ?? r.student_uid ?? '')} src={r.student_photo as string | null} onClick={() => open(Number(r.id))} />,
            company_name: (r: Row) => (
              <Link to={`/placement/drives/${r.drive_id}`} className="group flex w-[15rem] max-w-[15rem] items-center gap-2.5">
                <CompanyLogo name={String(r.company_name)} src={r.company_logo as string | null} size="sm" wide />
                <span className="min-w-0 leading-tight">
                  <span className="block truncate font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">{String(r.company_name)}</span>
                  <span className="block truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.job_role)}>{String(r.job_role)}</span>
                </span>
              </Link>
            ),
            status: (r: Row) => (
              <div className="flex flex-col items-start gap-1">
                <StatusBadge status={String(r.status)} label={APP_STATUS[String(r.status)]} colors={APP_STATUS_COLORS} />
                {r.offer_status && <StatusBadge status={String(r.offer_status)} label={`Offer: ${OFFER_STATUS_SHORT[String(r.offer_status)] ?? r.offer_status}`} colors={OFFER_STATUS_COLORS} dot={false} />}
              </div>
            ),
            cgpa: (r: Row) => (r.cgpa === null || r.cgpa === undefined ? <span className="text-slate-400">—</span> : <span className="tabular-nums font-medium">{Number(r.cgpa).toFixed(2)}</span>),
            current_round: (r: Row) =>
              r.current_round ? (
                <div className="leading-tight">
                  <div>{String(r.current_round)}</div>
                  <div className="text-xs text-slate-500 dark:text-slate-400">{Number(r.rounds_cleared)} round{Number(r.rounds_cleared) === 1 ? '' : 's'} cleared</div>
                </div>
              ) : (
                <span className="text-slate-400">Not started</span>
              ),
          }}
          onSaved={() => void refresh('placement_applications', 'placement_drives')}
        />
      ) : (
        <CrudTable
          key="interviews"
          module="placement_interviews"
          urlState
          title="Interview rounds"
          addLabel="Record round"
          onCreate={() => setPickOpen(true)}
          emptyTitle="No interview rounds recorded"
          emptyText="Rounds appear here as you record them from an application or advance students in bulk."
          onView={(r: Row) => open(Number(r.application_id))}
          rowMenu={(r: Row) => [
            { label: 'Open application', icon: Eye, onClick: () => open(Number(r.application_id)) },
            { label: 'Open drive', icon: ExternalLink, to: `/placement/drives/${r.drive_id}` },
          ]}
          renderers={{
            student_name: (r: Row) => <StudentCell name={String(r.student_name)} sub={String(r.student_uid ?? '')} src={r.student_photo as string | null} onClick={() => open(Number(r.application_id))} />,
            round_name: (r: Row) => (
              <div className="w-[10rem] max-w-[10rem] leading-tight">
                <div className="truncate font-semibold text-slate-900 dark:text-white" title={String(r.round_name)}>{String(r.round_name)}</div>
                <div className="truncate text-xs text-slate-500 dark:text-slate-400">{String(r.company_name)}</div>
              </div>
            ),
            result: (r: Row) => <span className="whitespace-nowrap"><StatusBadge status={String(r.result)} label={ROUND_RESULT[String(r.result)]} colors={ROUND_RESULT_COLORS} /></span>,
            scheduled_at: (r: Row) => (
              <div className="leading-tight">
                <div className="whitespace-nowrap">{formatDate(r.scheduled_at)}</div>
                <div className="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{r.scheduled_at ? formatTime(String(r.scheduled_at).slice(11, 19)) : ''}</div>
                {r.result === 'pending' && new Date(String(r.scheduled_at).replace(' ', 'T')) >= new Date(new Date().toDateString()) && <Badge color="amber" className="mt-0.5">Upcoming</Badge>}
              </div>
            ),
          }}
          onSaved={() => void refresh('placement_applications', 'placement_interviews')}
        />
      )}

      <ApplicationDrawer id={openId} onClose={() => open(null)} />
      <ApplicationActionModal pending={pending} onClose={() => setPending(null)} />
      <IssueOfferModal preset={offerPreset} onClose={() => setOfferPreset(null)} />
      <PickApplicationRoundModal open={pickOpen} onClose={() => setPickOpen(false)} />
    </>
  );
}
