import { useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import {
  BadgeCheck, CalendarDays, ChevronDown, ClipboardCheck, Download, Eye, FileText, GraduationCap, IndianRupee, ListChecks, MapPin, Pencil, Target, Trophy, UserPlus, Users,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, DescriptionList, Dropdown, EmptyState, PageHeader, PageLoader, Reveal, Stagger, StatTile, StatusBadge, Tabs, useConfirm, useToast,
} from '@/components/ui';
import { BarChart, chartColor } from '@/components/charts';
import { CrudFormModal, CrudTable } from '@/components/crud';
import { api, ApiError, downloadFile } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import type { DriveDetail } from './types';
import { APP_STATUS, APP_STATUS_COLORS, CompanyLogo, DRIVE_MODES, DRIVE_STATUS, DRIVE_STATUS_COLORS, JOB_TYPES, OFFER_STATUS, OFFER_STATUS_COLORS, OFFER_STATUS_SHORT, TIE_UP, ctcRange, lpa, privateFileUrl, relativeDays, usePlacementRefresh } from './shared';
import { driveTransitions } from './driveActions';
import { EligibleStudentsPanel } from './EligibleStudentsPanel';
import { ApplicationDrawer } from './ApplicationDrawer';
import { ApplicationActionModal, useApplicationBulkActions, type PendingBulk } from './applicationBulk';
import { IssueOfferModal, offerPresetFromRow, type OfferPreset } from './offers';

const TABS = ['applicants', 'eligible', 'offers'] as const;

export default function DriveDetailPage() {
  const { id: idParam } = useParams();
  const id = Number(idParam);
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = usePlacementRefresh();
  const [params, setParams] = useSearchParams();
  const tab = (TABS as readonly string[]).includes(params.get('tab') ?? '') ? (params.get('tab') as (typeof TABS)[number]) : 'applicants';
  const appId = Number(params.get('app') ?? 0) || null;
  const { data, isLoading, error, refetch } = useApi<DriveDetail>(['placement', 'drive', id], `placement/drives/${id}`, undefined, { enabled: id > 0 });
  const driveMeta = useCrudMeta('placement_drives');
  const [editing, setEditing] = useState(false);
  const [pending, setPending] = useState<PendingBulk | null>(null);
  const [offerPreset, setOfferPreset] = useState<OfferPreset | null>(null);
  const bulkActions = useApplicationBulkActions((action, ids, clear) => setPending({ action, ids, clear }));
  const d = data?.drive;
  const s = data?.stats;
  const offerDefaults = useMemo(() => (d ? { company_id: d.company_id, drive_id: d.id, job_role: d.job_role.split(',')[0].trim(), package_lpa: d.package_max ?? d.package_min ?? '' } : undefined), [d]);
  const appDefaults = useMemo(() => (d ? { drive_id: d.id } : undefined), [d]);

  const setTab = (k: string) => {
    const next = new URLSearchParams();
    if (k !== 'applicants') next.set('tab', k);
    setParams(next, { replace: true });
  };
  const openApp = (aid: number | null) => {
    const next = new URLSearchParams(params);
    if (aid) next.set('app', String(aid));
    else next.delete('app');
    setParams(next, { replace: !aid });
  };

  const setStatus = async (status: string, question?: string) => {
    if (question) {
      const ok = await confirm({ title: 'Change drive status?', message: question, confirmText: 'Continue', danger: status === 'cancelled' });
      if (!ok) return;
    }
    try {
      await api.post(`crud/placement_drives/${id}`, { status });
      toast.success(`Drive marked ${DRIVE_STATUS[status]?.toLowerCase() ?? status}.`);
      await refresh('placement_drives');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  if (error) {
    return (
      <>
        <PageHeader title="Placement drive" breadcrumbs={[{ label: 'Placement', to: '/placement' }, { label: 'Drives', to: '/placement/drives' }, { label: 'Not found' }]} />
        <Alert variant="error" title="Unable to load this drive" action={<Button size="sm" variant="secondary" to="/placement/drives">Back to drives</Button>}>
          {(error as Error).message}
        </Alert>
      </>
    );
  }
  if (isLoading || !data || !d || !s) return <PageLoader label="Loading drive…" />;

  const funnel = data.round_stats;
  const transitions = driveTransitions(d.status);
  const upcoming = ['upcoming', 'open', 'ongoing'].includes(d.status);

  return (
    <>
      <PageHeader
        title={d.title}
        description={`${d.company_name} · ${d.job_role}`}
        breadcrumbs={[{ label: 'Placement', to: '/placement' }, { label: 'Drives', to: '/placement/drives' }, { label: d.company_name }]}
        actions={
          <>
            {can('placement', 'export') && (
              <Button variant="secondary" icon={Download} onClick={() => downloadFile('crud/placement_applications/export', { format: 'xlsx', f: { drive_id: d.id } })}>
                Applicants
              </Button>
            )}
            {can('placement', 'edit') && transitions.length > 0 && (
              <Dropdown
                trigger={<>Status <ChevronDown className="h-4 w-4" /></>}
                items={transitions.map((t) => ({ label: t.label, icon: t.icon, danger: t.danger, onClick: () => void setStatus(t.status, t.confirm) }))}
              />
            )}
            {can('placement', 'edit') && (
              <Button icon={Pencil} onClick={() => setEditing(true)}>
                Edit drive
              </Button>
            )}
          </>
        }
      />

      {/* Hero */}
      <Reveal className="mb-5">
        <Card className="p-5">
          <div className="flex flex-col gap-5 lg:flex-row lg:items-start">
            <div className="flex min-w-0 flex-1 items-start gap-4">
              <CompanyLogo name={d.company_name} src={d.company_logo} size="lg" />
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-1.5">
                  <StatusBadge status={d.status} label={DRIVE_STATUS[d.status]} colors={DRIVE_STATUS_COLORS} />
                  <Badge color="navy">{JOB_TYPES[d.job_type] ?? d.job_type}</Badge>
                  <Badge color="slate">{DRIVE_MODES[d.mode] ?? d.mode}</Badge>
                  {d.session && <Badge color="slate">Session {d.session}</Badge>}
                </div>
                <Link to={`/placement/companies?company=${d.company_id}`} className="mt-2 block font-display text-lg font-bold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
                  {d.company_name}
                </Link>
                <p className="text-sm text-slate-500 dark:text-slate-400">{[d.industry, TIE_UP[d.tie_up_status]].filter(Boolean).join(' · ')}</p>
                <div className="mt-2 flex flex-wrap gap-1.5">
                  {d.job_role.split(',').map((r) => (
                    <span key={r} className="rounded-lg bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800 dark:bg-brand-500/15 dark:text-brand-200">{r.trim()}</span>
                  ))}
                </div>
              </div>
            </div>
            <dl className="grid flex-[1.3] grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
              {[
                { icon: CalendarDays, label: 'Drive date', value: d.drive_date ? `${formatDate(d.drive_date)}${upcoming ? ` (${relativeDays(d.drive_date)})` : ''}` : 'To be announced' },
                { icon: ListChecks, label: 'Register by', value: d.registration_deadline ? formatDate(d.registration_deadline) : '—' },
                { icon: IndianRupee, label: 'CTC', value: ctcRange(d.package_min, d.package_max) },
                { icon: Trophy, label: 'Stipend', value: d.stipend ? `${formatMoney(d.stipend)}/month` : '—' },
                { icon: Users, label: 'Openings', value: d.openings ? formatNumber(d.openings) : '—' },
                { icon: MapPin, label: 'Location', value: d.location ?? '—' },
              ].map((x) => (
                <div key={x.label} className="min-w-0">
                  <dt className="flex items-center gap-1 text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400"><x.icon className="h-3.5 w-3.5" />{x.label}</dt>
                  <dd className="mt-0.5 truncate font-medium text-slate-800 dark:text-slate-100" title={String(x.value)}>{x.value}</dd>
                </div>
              ))}
            </dl>
          </div>
          {d.venue && <p className="mt-4 border-t border-slate-100 pt-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400"><span className="font-semibold text-slate-700 dark:text-slate-200">Venue:</span> {d.venue}</p>}
        </Card>
      </Reveal>

      {/* KPIs */}
      <Stagger className="mb-5 grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 lg:grid-cols-5" step={60}>
        {[
          <StatTile key="e" label="Eligible students" value={s.eligible} icon={GraduationCap} tone="purple" sub={upcoming ? 'Meet the criteria today' : 'Meet the criteria'} />,
          <StatTile key="r" label="Registered" value={s.applied} icon={UserPlus} tone="blue" sub={`${s.registration_rate}% of eligible`} />,
          <StatTile key="p" label="In process" value={s.in_process + (s.by_status.applied ?? 0)} icon={ClipboardCheck} tone="amber" sub={`${s.by_status.on_hold ?? 0} on hold`} />,
          <StatTile key="s" label="Selected" value={s.selected} icon={Target} tone="green" sub={`${s.rejected} not selected`} />,
          <StatTile key="o" label="Offers" value={s.offers} icon={BadgeCheck} tone="navy" sub={s.offers ? `${s.confirmed} accepted` : 'None yet'} />,
        ]}
      </Stagger>

      {/* Eligibility + rounds */}
      <div className="mb-5 grid grid-cols-1 gap-5 xl:grid-cols-5">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Eligibility criteria" icon={GraduationCap} />
            <div className="card-body space-y-4">
              <div>
                <p className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">Eligible programs</p>
                <div className="mt-1.5 flex flex-wrap gap-1.5">
                  {data.programs.length ? data.programs.map((p) => <Badge key={p.id} color="blue">{p.short_name}</Badge>) : <Badge color="slate">All programs</Badge>}
                </div>
              </div>
              <DescriptionList
                items={[
                  { label: 'Passing-out batch', value: d.batch_year ? String(d.batch_year) : 'Final year' },
                  { label: 'Minimum CGPA', value: d.min_cgpa ? Number(d.min_cgpa).toFixed(2) : 'No cut-off' },
                  { label: 'Max active backlogs', value: d.max_backlogs !== null ? String(d.max_backlogs) : 'No limit' },
                  { label: 'Min previous %', value: d.min_percentage ? `${Number(d.min_percentage)}%` : 'No cut-off' },
                  ...(d.eligibility_notes ? [{ label: 'Notes', value: d.eligibility_notes, full: true }] : []),
                ]}
              />
            </div>
          </Card>
        </Reveal>
        <Reveal className="xl:col-span-3" delay={80}>
          <Card className="h-full">
            <CardHeader title="Selection funnel" subtitle={data.rounds.length ? data.rounds.join(' → ') : 'No rounds defined'} icon={ListChecks} />
            <div className="card-body">
              {!funnel.length ? (
                <EmptyState icon={ListChecks} title="No rounds defined" description="Edit the drive and list its selection rounds in order (e.g. Aptitude Test, Technical Interview, HR Interview)."
                  action={can('placement', 'edit') ? <Button variant="secondary" icon={Pencil} onClick={() => setEditing(true)}>Edit drive</Button> : undefined} className="!py-8" />
              ) : !funnel.some((f) => f.appeared) ? (
                <div className="space-y-3">
                  <ol className="flex flex-wrap items-center gap-2">
                    {data.rounds.map((r, i) => (
                      <li key={r} className="flex items-center gap-2">
                        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{i + 1}. {r}</span>
                        {i < data.rounds.length - 1 && <span className="text-slate-300">→</span>}
                      </li>
                    ))}
                  </ol>
                  <p className="text-sm text-slate-500 dark:text-slate-400">Round results will appear here once students are moved through the rounds.</p>
                </div>
              ) : (
                <BarChart
                  labels={funnel.map((f) => f.round)}
                  series={[
                    { label: 'Appeared', data: funnel.map((f) => f.appeared), color: chartColor(0) },
                    { label: 'Cleared', data: funnel.map((f) => f.cleared), color: chartColor(1) },
                    { label: 'Pending', data: funnel.map((f) => f.pending), color: chartColor(6) },
                  ]}
                  height={250}
                />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      {d.description && (
        <Reveal className="mb-5">
          <Card>
            <CardHeader title="Job description" icon={FileText} />
            <div className="card-body whitespace-pre-line text-sm text-slate-700 dark:text-slate-200">{d.description}</div>
          </Card>
        </Reveal>
      )}

      <Tabs
        tabs={[
          { key: 'applicants', label: 'Applicants', icon: Users, count: s.applied },
          { key: 'eligible', label: 'Eligible students', icon: GraduationCap, count: s.eligible },
          { key: 'offers', label: 'Offers', icon: BadgeCheck, count: s.offers_total },
        ]}
        value={tab}
        onChange={setTab}
        className="mb-4"
      />

      {tab === 'applicants' && (
        <CrudTable
          key="drive-apps"
          module="placement_applications"
          scope={{ drive_id: d.id }}
          title="Applicants"
          hideColumns={['company_name', 'drive_date']}
          hideFilters={['company_id', 'session']}
          formDefaults={appDefaults}
          addLabel="Add applicant"
          emptyTitle="No applicants yet"
          emptyText="Register eligible students from the Eligible students tab."
          onView={(r: Row) => openApp(Number(r.id))}
          bulkActions={bulkActions}
          rowMenu={(r: Row) => [
            { label: 'View details & rounds', icon: Eye, onClick: () => openApp(Number(r.id)) },
            can('placement', 'create') && r.status === 'selected' && !r.offer_status && d.job_type !== 'internship' && { label: 'Record offer', icon: BadgeCheck, onClick: () => setOfferPreset(offerPresetFromRow(r)) },
          ]}
          renderers={{
            status: (r: Row) => (
              <div className="flex flex-col items-start gap-1">
                <StatusBadge status={String(r.status)} label={APP_STATUS[String(r.status)]} colors={APP_STATUS_COLORS} />
                {r.offer_status && <StatusBadge status={String(r.offer_status)} label={`Offer: ${OFFER_STATUS_SHORT[String(r.offer_status)] ?? r.offer_status}`} colors={OFFER_STATUS_COLORS} dot={false} />}
              </div>
            ),
            cgpa: (r: Row) => (r.cgpa === null || r.cgpa === undefined ? <span className="text-slate-400">—</span> : <span className="tabular-nums font-medium">{Number(r.cgpa).toFixed(2)}</span>),
          }}
          onSaved={() => void refresh('placement_applications', 'placement_drives')}
        />
      )}
      {tab === 'eligible' && <EligibleStudentsPanel detail={data} />}
      {tab === 'offers' && (
        <CrudTable
          key="drive-offers"
          module="placement_offers"
          scope={{ drive_id: d.id }}
          title="Offers"
          hideFilters={['company_id', 'session']}
          formDefaults={offerDefaults}
          addLabel="Record offer"
          emptyTitle="No offers from this drive yet"
          emptyText={d.job_type === 'internship' ? 'This is an internship drive — selections are tracked on the applicants tab.' : 'Record offers for selected students to mark them placed.'}
          renderers={{
            status: (r: Row) => <StatusBadge status={String(r.status)} label={OFFER_STATUS[String(r.status)]} colors={OFFER_STATUS_COLORS} />,
            package_lpa: (r: Row) => <span className="whitespace-nowrap font-semibold tabular-nums">{lpa(r.package_lpa)}</span>,
            offer_letter: (r: Row) =>
              r.offer_letter ? (
                <a href={privateFileUrl(String(r.offer_letter), `Offer-${r.student_uid}.pdf`)} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1 text-xs font-medium text-brand-700 hover:underline dark:text-brand-300">
                  <Download className="h-3.5 w-3.5" /> PDF
                </a>
              ) : (
                <span className="text-slate-400">—</span>
              ),
          }}
          onSaved={() => void refresh('placement_offers', 'placement_applications')}
        />
      )}

      <CrudFormModal open={editing} onClose={() => setEditing(false)} meta={driveMeta.data} module="placement_drives" id={d.id} onSaved={() => { void refresh('placement_drives'); void refetch(); }} />
      <ApplicationDrawer id={appId} onClose={() => openApp(null)} />
      <ApplicationActionModal pending={pending} onClose={() => setPending(null)} />
      <IssueOfferModal preset={offerPreset} onClose={() => setOfferPreset(null)} />
    </>
  );
}
