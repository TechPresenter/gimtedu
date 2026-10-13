import { useMemo } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { CalendarCheck2, CalendarClock, CheckCircle2, ClipboardList, GraduationCap, PlayCircle, Target, Users } from 'lucide-react';
import { PageHeader, Stagger, StatTile, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate } from '@/lib/format';
import type { Row } from '@/lib/types';
import { TrainingDrawer } from './placement/TrainingDrawer';
import { TRAINING_STATUS_COLORS, TRAINING_TYPES, relativeDays, usePlacementRefresh } from './placement/shared';

interface TrainingStats {
  session: string;
  upcoming: number;
  ongoing: number;
  completed: number;
  attendances: number;
  students: number;
  mock_avg: number | null;
  next: { id: number; title: string; start_date: string; type: string } | null;
}

const TABS = [
  { key: 'all', label: 'All sessions' },
  { key: 'upcoming', label: 'Upcoming' },
  { key: 'past', label: 'Past' },
];

export default function TrainingPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = usePlacementRefresh();
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? (params.get('tab') as string) : 'all';
  const openId = Number(params.get('training') ?? 0) || null;
  const { data: st, isLoading } = useApi<TrainingStats>(['placement', 'stats', 'trainings'], 'placement/stats/trainings');
  const defaults = useMemo(() => (tab === 'all' ? undefined : { when: tab }), [tab]);

  const open = (id: number | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('training', String(id));
    else next.delete('training');
    setParams(next, { replace: !id });
  };
  const setTab = (k: string) => setParams(k === 'all' ? {} : { tab: k });

  const setStatus = async (r: Row, status: string) => {
    if (status === 'cancelled') {
      const ok = await confirm({ title: 'Cancel this session?', message: `"${r.title}" will be marked cancelled. Participants stay on record.`, confirmText: 'Cancel session', danger: true });
      if (!ok) return;
    }
    try {
      await api.post(`crud/placement_trainings/${r.id}`, { status });
      toast.success(`Session marked ${status}.`);
      await refresh('placement_trainings');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Training & Mock Tests"
        description="Aptitude and soft-skill trainings, workshops, mock tests and mock interviews with attendance and scores."
        breadcrumbs={[{ label: 'Placement', to: '/placement' }, { label: 'Training' }]}
      />
      <Stagger className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4 lg:grid-cols-4" step={60}>
        {[
          <StatTile key="u" label="Upcoming sessions" value={st?.upcoming ?? 0} icon={CalendarClock} tone="amber" loading={isLoading}
            sub={st?.next ? <Link to={`/placement/training?training=${st.next.id}`} className="hover:text-brand-700">Next: {st.next.title} · {relativeDays(st.next.start_date)}</Link> : 'Nothing scheduled'} />,
          <StatTile key="c" label={`Completed · ${st?.session ?? ''}`} value={st?.completed ?? 0} icon={CalendarCheck2} tone="green" loading={isLoading} sub={st ? `${st.ongoing} running now` : undefined} />,
          <StatTile key="s" label="Students trained" value={st?.students ?? 0} icon={GraduationCap} tone="navy" loading={isLoading} sub={st ? `${st.attendances} attendances this session` : undefined} />,
          <StatTile key="m" label="Mock test average" value={st?.mock_avg !== null && st?.mock_avg !== undefined ? `${st.mock_avg}%` : '—'} icon={Target} tone="purple" loading={isLoading} sub="Across all mock tests" />,
        ]}
      </Stagger>

      <Tabs tabs={TABS} value={tab} onChange={setTab} variant="pills" className="mb-4" />

      <CrudTable
        key={tab}
        module="placement_trainings"
        urlState
        title={TABS.find((t) => t.key === tab)?.label}
        defaultFilters={defaults}
        hideFilters={tab === 'all' ? [] : ['when']}
        addLabel="Schedule session"
        emptyTitle="No sessions here"
        emptyText="Schedule trainings, workshops, mock tests or mock interviews and enrol students."
        onView={(r: Row) => open(Number(r.id))}
        rowMenu={(r: Row) => [
          { label: 'Participants & scores', icon: Users, onClick: () => open(Number(r.id)) },
          can('placement', 'edit') && r.status === 'scheduled' && { label: 'Mark ongoing', icon: PlayCircle, onClick: () => void setStatus(r, 'ongoing') },
          can('placement', 'edit') && ['scheduled', 'ongoing'].includes(String(r.status)) && String(r.start_date) <= new Date().toISOString().slice(0, 10) && { label: 'Mark completed', icon: CheckCircle2, onClick: () => void setStatus(r, 'completed') },
          can('placement', 'edit') && ['scheduled', 'ongoing'].includes(String(r.status)) && { label: 'Cancel session', icon: ClipboardList, danger: true, onClick: () => void setStatus(r, 'cancelled') },
        ]}
        renderers={{
          title: (r: Row) => (
            <button type="button" onClick={() => open(Number(r.id))} className="block w-[14.5rem] max-w-[14.5rem] text-left leading-tight">
              <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300" title={String(r.title)}>{String(r.title)}</span>
              <span className="block truncate text-xs text-slate-500 dark:text-slate-400" title={String(r.trainer ?? '')}>{String(r.audience)} · {String(r.mode).replace(/^./, (c) => c.toUpperCase())}{r.trainer ? ` · ${r.trainer}` : ''}</span>
            </button>
          ),
          audience: (r: Row) => <span className="whitespace-nowrap">{String(r.audience)}</span>,
          participants_count: (r: Row) => (
            <div className="text-center leading-tight">
              <div className="font-medium tabular-nums">{Number(r.participants_count)}</div>
              {Number(r.attended_count) > 0 && <div className="whitespace-nowrap text-xs text-slate-500 dark:text-slate-400">{Number(r.attended_count)} attended</div>}
            </div>
          ),
          type: (r: Row) => <span className="whitespace-nowrap"><StatusBadge status={String(r.type)} label={TRAINING_TYPES[String(r.type)]} dot={false} /></span>,
          status: (r: Row) => <span className="whitespace-nowrap"><StatusBadge status={String(r.status)} colors={TRAINING_STATUS_COLORS} /></span>,
          start_date: (r: Row) => (
            <div className="leading-tight">
              <div className="whitespace-nowrap">{formatDate(r.start_date)}</div>
              {r.end_date && r.end_date !== r.start_date ? <div className="text-xs text-slate-500 dark:text-slate-400">to {formatDate(r.end_date)}</div>
                : ['scheduled', 'ongoing'].includes(String(r.status)) ? <div className="text-xs text-slate-500 dark:text-slate-400">{relativeDays(String(r.start_date))}</div> : null}
            </div>
          ),
          avg_score: (r: Row) => (r.avg_score === null || r.avg_score === undefined ? <span className="text-slate-400">—</span> : <span className="tabular-nums font-medium">{Number(r.avg_score)}{r.max_marks ? <span className="text-slate-400">/{Number(r.max_marks)}</span> : null}</span>),
        }}
        onSaved={() => void refresh('placement_trainings')}
      />

      <TrainingDrawer id={openId} onClose={() => open(null)} />
    </>
  );
}
