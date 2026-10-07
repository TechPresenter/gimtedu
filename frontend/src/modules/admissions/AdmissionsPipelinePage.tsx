import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  ArrowDown, BadgeCheck, CalendarClock, CheckCheck, ClipboardList, FilePlus2, Hourglass, ListChecks, MessageCircle, Percent, PhoneCall, Plus, SquareKanban, UserCheck, UserX,
} from 'lucide-react';
import {
  Alert, Avatar, Button, Card, CardHeader, CardSkeleton, EmptyState, PageHeader, ProgressBar, Reveal, Skeleton, Stagger, StatCard, Tabs, toneClasses, useToast,
} from '@/components/ui';
import { BarChart, DoughnutChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi, useInvalidate } from '@/lib/queries';
import { useAcademicSession, useAuth } from '@/lib/auth';
import { formatMoneyShort, formatNumber, formatPercent } from '@/lib/format';
import { KanbanBoard } from './KanbanBoard';
import { DueChip, FollowupModal, STAGE_TONE, StageBadge, followupIcon, type DueFollowup, type FollowupTarget } from './shared';

interface Dashboard {
  session: { id: number; name: string; admissions_open: boolean } | null;
  kpis: {
    total: number; new_30: number; prev_30: number; new_7: number; pending: number; approved: number; rejected: number; withdrawn: number; waitlisted: number;
    confirmed: number; converted: number; fee_pending: number; fee_collected: number; conversion_rate: number; new_trend: number | null; enquiries_open: number;
  };
  stage_counts: Record<string, number>;
  funnel: { stage: string; label: string; reached: number; current: number }[];
  sources: { source: string; label: string; total: number; confirmed: number }[];
  programs: { id: number; program: string; intake: number; total: number; confirmed: number; approved: number }[];
  trend: { month: string; label: string; applications: number; confirmed: number }[];
  counsellors: { id: number; name: string; avatar: string | null; total: number; confirmed: number; active: number; rate: number }[];
  followups: { overdue: { rows: DueFollowup[]; total: number }; today: { rows: DueFollowup[]; total: number } };
}

export default function AdmissionsPipelinePage() {
  const { id: sessionId } = useAcademicSession();
  const { can } = useAuth();
  const { data, isLoading, error, refetch } = useApi<Dashboard>(['adm-dashboard', sessionId], 'admissions/dashboard', { session_id: sessionId ?? undefined });
  const k = data?.kpis;
  const trendDir = k?.new_trend === null || k?.new_trend === undefined ? 'flat' : k.new_trend > 0 ? 'up' : k.new_trend < 0 ? 'down' : 'flat';

  return (
    <>
      <PageHeaderBlock data={data} canCreate={can('admissions', 'create')} canEnquiries={can('enquiries', 'view')} />
      {error ? (
        <Alert variant="error" title="Unable to load the admissions dashboard" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <>
          <Stagger className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
            {[
              <StatCard key="new" label="New Applications" icon={FilePlus2} tone="blue" loading={isLoading} value={k?.new_30 ?? 0} to="/admissions/list"
                trend={k ? { value: k.new_trend === null ? '—' : formatPercent(Math.abs(k.new_trend)), dir: trendDir, label: 'vs previous 30 days' } : undefined} />,
              <StatCard key="pending" label="In Pipeline" icon={Hourglass} tone="amber" loading={isLoading} value={k?.pending ?? 0} hint={k ? `${formatNumber(k.waitlisted)} waitlisted · ${formatNumber(k.fee_pending)} fee due` : undefined} />,
              <StatCard key="approved" label="Approved" icon={BadgeCheck} tone="purple" loading={isLoading} value={k?.approved ?? 0} to="/admissions/list?f.stage=fee_payment" hint="Offer letters issued" />,
              <StatCard key="rejected" label="Rejected" icon={UserX} tone="red" loading={isLoading} value={k?.rejected ?? 0} to="/admissions/list?f.stage=rejected" hint={k ? `${formatNumber(k.withdrawn)} withdrawn` : undefined} />,
              <StatCard key="converted" label="Converted to Students" icon={UserCheck} tone="green" loading={isLoading} value={k?.converted ?? 0} to="/admissions/list?f.stage=confirmed" hint={k ? `${formatMoneyShort(k.fee_collected)} admission fee` : undefined} />,
              <StatCard key="rate" label="Conversion Rate" icon={Percent} tone="cyan" loading={isLoading} value={k ? formatPercent(k.conversion_rate, 1) : '—'} hint={k ? `${formatNumber(k.confirmed)} of ${formatNumber(k.total)} applications` : undefined} />,
            ]}
          </Stagger>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-7">
              <Card className="h-full">
                <CardHeader title="Admission funnel" subtitle="Applications that reached each stage this session" icon={ListChecks} />
                <div className="card-body">{isLoading || !data ? <FunnelSkeleton /> : <Funnel steps={data.funnel} />}</div>
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-5" delay={80}>
              <FollowupsDue data={data} loading={isLoading} sessionId={sessionId} />
            </Reveal>
          </div>

          <Reveal className="mt-6">
            <Card className="overflow-hidden">
              <CardHeader
                title="Pipeline board"
                subtitle="Drag a card to the next stage or use the arrows. Every move is validated and recorded on the applicant's timeline."
                icon={SquareKanban}
                actions={<Button size="sm" variant="secondary" to="/admissions/list" icon={ClipboardList}>List view</Button>}
              />
              <KanbanBoard sessionId={sessionId} />
            </Card>
          </Reveal>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-5">
              <Card className="h-full">
                <CardHeader title="Applications by source" subtitle="Where applicants are coming from" />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="mx-auto h-48 w-48 rounded-full" /> : data.sources.length ? (
                    <DoughnutChart labels={data.sources.map((s) => s.label)} data={data.sources.map((s) => s.total)} centerValue={formatNumber(data.kpis.total)} centerLabel="Applications" height={190} />
                  ) : (
                    <EmptyState icon={FilePlus2} title="No applications yet" />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-7" delay={80}>
              <Card className="h-full">
                <CardHeader title="Program-wise admissions" subtitle="Applications vs confirmed admissions" />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="h-64 w-full" /> : (
                    <BarChart
                      horizontal
                      height={Math.max(240, data.programs.length * 26)}
                      labels={data.programs.map((p) => p.program)}
                      series={[
                        { label: 'Applications', data: data.programs.map((p) => p.total), color: '#1D4ED8' },
                        { label: 'Confirmed', data: data.programs.map((p) => p.confirmed), color: '#22943F' },
                      ]}
                    />
                  )}
                </div>
              </Card>
            </Reveal>
          </div>

          <div className="mt-6 grid gap-6 lg:grid-cols-12">
            <Reveal className="lg:col-span-8">
              <Card className="h-full">
                <CardHeader title="Admission trend" subtitle="Applications received vs admissions confirmed (last 8 months)" />
                <div className="card-body">
                  {isLoading || !data ? <Skeleton className="h-64 w-full" /> : (
                    <BarChart labels={data.trend.map((t) => t.label)} height={260}
                      series={[{ label: 'Applications', data: data.trend.map((t) => t.applications), color: '#1D4ED8' }, { label: 'Confirmed', data: data.trend.map((t) => t.confirmed), color: '#22943F' }]} />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="lg:col-span-4" delay={80}>
              <Card className="h-full">
                <CardHeader title="Counsellor performance" subtitle="Confirmed admissions per counsellor" />
                <div className="card-body">
                  {isLoading || !data ? <CardSkeleton lines={4} /> : data.counsellors.length === 0 ? (
                    <EmptyState icon={UserCheck} title="No counsellors assigned" description="Assign counsellors to applications to track their conversions." />
                  ) : (
                    <ul className="space-y-4">
                      {data.counsellors.map((c) => (
                        <li key={c.id}>
                          <div className="flex items-center gap-3">
                            <Avatar name={c.name} src={c.avatar} size="sm" />
                            <div className="min-w-0 flex-1">
                              <div className="flex items-baseline justify-between gap-2">
                                <p className="truncate text-sm font-semibold text-slate-800 dark:text-slate-100">{c.name}</p>
                                <span className="text-xs font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{formatPercent(c.rate, 1)}</span>
                              </div>
                              <p className="text-xs text-slate-500">{formatNumber(c.confirmed)} confirmed · {formatNumber(c.active)} active · {formatNumber(c.total)} total</p>
                            </div>
                          </div>
                          <ProgressBar value={c.rate} tone="green" className="mt-2 !h-1.5" label={`${c.name} conversion rate`} />
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </Card>
            </Reveal>
          </div>
        </>
      )}
    </>
  );
}

function PageHeaderBlock({ data, canCreate, canEnquiries }: { data?: Dashboard; canCreate: boolean; canEnquiries: boolean }) {
  return (
    <PageHeader
      title="Admissions"
      breadcrumbs={[{ label: 'Admissions' }]}
      description={
        <span className="inline-flex flex-wrap items-center gap-2">
          {data?.session && (
            <span className={clsx('badge', data.session.admissions_open ? 'badge-green' : 'badge-slate')}>
              <span className={clsx('h-1.5 w-1.5 rounded-full bg-current', data.session.admissions_open && 'animate-pulse motion-reduce:animate-none')} aria-hidden />
              {data.session.admissions_open ? 'Admissions open' : 'Admissions closed'} · {data.session.name}
            </span>
          )}
          Track every applicant from first enquiry to confirmed admission.
        </span>
      }
      actions={
        <>
          {canEnquiries && (
            <Button variant="secondary" icon={MessageCircle} to="/enquiries">
              Enquiries
            </Button>
          )}
          <Button variant="secondary" icon={ClipboardList} to="/admissions/list">
            All applications
          </Button>
          {canCreate && (
            <Button variant="success" icon={Plus} to="/admissions/new">
              New application
            </Button>
          )}
        </>
      }
    />
  );
}

/* ------------------------------------------------------------------ Funnel */

function Funnel({ steps }: { steps: Dashboard['funnel'] }) {
  const top = Math.max(1, steps[0]?.reached ?? 1);
  if (!steps.length || top <= 0) return <EmptyState icon={ListChecks} title="No applications this session" />;
  return (
    <ol className="space-y-1.5">
      {steps.map((s, i) => {
        const pct = (s.reached / top) * 100;
        const prev = i > 0 ? steps[i - 1].reached : null;
        const step = prev ? Math.round((s.reached / prev) * 100) : null;
        const tone = toneClasses[STAGE_TONE[s.stage] ?? 'slate'];
        return (
          <li key={s.stage}>
            {step !== null && (
              <p className="flex items-center gap-1 pl-[7.5rem] text-[10.5px] text-slate-400 sm:pl-[9.5rem]">
                <ArrowDown className="h-3 w-3" aria-hidden /> {step}% moved on
              </p>
            )}
            <div className="flex items-center gap-3">
              <span className="w-28 shrink-0 truncate text-right text-xs font-medium text-slate-600 dark:text-slate-300 sm:w-36" title={s.label}>{s.label}</span>
              <div className="relative h-8 flex-1 overflow-hidden rounded-lg bg-slate-100 dark:bg-slate-800">
                <div className={clsx('flex h-full items-center rounded-lg px-2.5 transition-all duration-700', tone.bar)} style={{ width: `${Math.max(pct, 8)}%` }}>
                  <span className="text-xs font-bold tabular-nums text-white drop-shadow-sm">{formatNumber(s.reached)}</span>
                </div>
              </div>
              <span className="w-24 shrink-0 text-right text-[11px] text-slate-500 dark:text-slate-400">
                <span className="font-semibold text-slate-700 dark:text-slate-200">{formatNumber(s.current)}</span> here now
              </span>
            </div>
          </li>
        );
      })}
    </ol>
  );
}

function FunnelSkeleton() {
  return (
    <div className="space-y-3">
      {[100, 90, 76, 62, 50, 40, 32].map((w) => (
        <div key={w} className="flex items-center gap-3">
          <Skeleton className="h-3 w-28" />
          <Skeleton className="h-8" />
          <div style={{ width: `${w}%` }} />
        </div>
      ))}
    </div>
  );
}

/* ------------------------------------------------------------------ Follow-ups due */

function FollowupsDue({ data, loading, sessionId }: { data?: Dashboard; loading: boolean; sessionId: number | null }) {
  const [tab, setTab] = useState<'overdue' | 'today'>('overdue');
  const [more, setMore] = useState<Record<string, DueFollowup[]>>({});
  const [page, setPage] = useState<Record<string, number>>({});
  const [busy, setBusy] = useState<number | null>(null);
  const [target, setTarget] = useState<FollowupTarget | null>(null);
  const toast = useToast();
  const invalidate = useInvalidate();
  const { can } = useAuth();
  const block = data?.followups[tab];
  const rows = [...(block?.rows ?? []), ...(more[tab] ?? [])];
  const total = block?.total ?? 0;

  const loadMore = async () => {
    const next = (page[tab] ?? 1) + 1;
    try {
      // first page of 8 is part of the dashboard payload; fetch further pages of 8
      const res = await api.get<{ rows: DueFollowup[] }>('admissions/followups', { scope: tab, page: next, per_page: 8, session_id: sessionId ?? undefined });
      setMore((m) => ({ ...m, [tab]: [...(m[tab] ?? []), ...res.rows] }));
      setPage((p) => ({ ...p, [tab]: next }));
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const markDone = async (f: DueFollowup) => {
    setBusy(f.id);
    try {
      const res = await api.post(`admissions/followups/${f.id}/done`);
      toast.success(res.message);
      setMore({});
      setPage({});
      await invalidate('adm-dashboard', 'adm-board', 'adm-profile');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <Card className="flex h-full flex-col">
      <CardHeader title="Follow-ups due" subtitle="Scheduled calls and meetings for applicants and enquiries" icon={CalendarClock} />
      <div className="px-5 pt-3">
        <Tabs
          variant="pills"
          value={tab}
          onChange={(v) => setTab(v as 'overdue' | 'today')}
          tabs={[
            { key: 'overdue', label: 'Overdue', count: data?.followups.overdue.total ?? 0 },
            { key: 'today', label: 'Due today', count: data?.followups.today.total ?? 0 },
          ]}
        />
      </div>
      <div className="flex-1 px-5 py-3">
        {loading ? (
          <div className="space-y-3">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-14 w-full" />)}</div>
        ) : rows.length === 0 ? (
          <EmptyState icon={CheckCheck} title={tab === 'overdue' ? 'No overdue follow-ups' : 'Nothing due today'} description="Great job — every scheduled follow-up is on track." className="!py-8" />
        ) : (
          <ul className="max-h-[420px] divide-y divide-slate-100 overflow-y-auto pr-1 dark:divide-slate-800">
            {rows.map((f) => {
              const Icon = followupIcon(f.type);
              const link = f.admission_id ? `/admissions/${f.admission_id}?tab=counselling` : `/enquiries?q=${encodeURIComponent(f.phone ?? f.name)}`;
              return (
                <li key={f.id} className="flex gap-3 py-3">
                  <span className={clsx('mt-0.5 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full', f.admission_id ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' : 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300')}>
                    <Icon className="h-4 w-4" aria-hidden />
                  </span>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                      <Link to={link} className="truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{f.name}</Link>
                      {f.stage ? <StageBadge stage={f.stage} dot={false} /> : <span className="badge badge-purple">Enquiry</span>}
                    </div>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {[f.application_no, f.program, f.counsellor ? `@${f.counsellor.split(' ')[0]}` : 'Unassigned'].filter(Boolean).join(' · ')}
                    </p>
                    <p className="mt-0.5 line-clamp-1 text-xs text-slate-600 dark:text-slate-300">{f.notes}</p>
                  </div>
                  <div className="flex shrink-0 flex-col items-end gap-1.5">
                    <DueChip date={f.next_followup_date} />
                    {can(f.admission_id ? 'admissions' : 'enquiries', 'edit') && (
                      <div className="flex gap-1">
                        <button type="button" onClick={() => setTarget({ kind: f.admission_id ? 'admission' : 'enquiry', id: (f.admission_id ?? f.enquiry_id) as number, name: f.name, sub: f.application_no ?? f.phone ?? undefined })}
                          className="rounded-md px-1.5 py-0.5 text-[11px] font-semibold text-brand-700 hover:bg-brand-50 dark:text-brand-300 dark:hover:bg-brand-500/10">
                          <PhoneCall className="mr-0.5 inline h-3 w-3" aria-hidden />Log
                        </button>
                        <button type="button" disabled={busy === f.id} onClick={() => markDone(f)}
                          className="rounded-md px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700 hover:bg-emerald-50 disabled:opacity-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10">
                          <CheckCheck className="mr-0.5 inline h-3 w-3" aria-hidden />Done
                        </button>
                      </div>
                    )}
                  </div>
                </li>
              );
            })}
          </ul>
        )}
      </div>
      {!loading && rows.length < total && (
        <div className="border-t border-slate-100 px-5 py-2.5 text-center dark:border-slate-800">
          <Button variant="ghost" size="xs" onClick={loadMore}>
            Show more ({formatNumber(total - rows.length)} remaining)
          </Button>
        </div>
      )}
      <FollowupModal open={!!target} target={target} onClose={() => setTarget(null)} showStatus={target?.kind === 'enquiry'} onSaved={() => { setMore({}); setPage({}); }} />
    </Card>
  );
}
