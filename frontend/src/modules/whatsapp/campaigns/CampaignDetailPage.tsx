import { Suspense, lazy, useMemo, useState } from 'react';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  AlertTriangle,
  Ban,
  CalendarClock,
  CheckCheck,
  CircleCheck,
  CirclePause,
  CirclePlay,
  CircleX,
  Clock,
  Copy,
  Download,
  Eye,
  FileSpreadsheet,
  FlaskConical,
  History,
  ListChecks,
  MessageCircle,
  MessageCircleReply,
  MoreHorizontal,
  Pause,
  Pencil,
  Play,
  RotateCcw,
  Send,
  Trash2,
  TriangleAlert,
  Upload,
  Users,
  XCircle,
  Zap,
  type LucideIcon,
} from 'lucide-react';
import {
  Alert,
  Badge,
  Button,
  Card,
  CardHeader,
  CardSkeleton,
  DataTable,
  DescriptionList,
  Dropdown,
  EmptyState,
  PageHeader,
  PageLoader,
  Pagination,
  ProgressBar,
  Reveal,
  SearchInput,
  Select,
  Skeleton,
  Stagger,
  StatCard,
  Tabs,
  Timeline,
  useToast,
  type Column,
  type DropdownItem,
  type TimelineItem,
  type Tone,
} from '@/components/ui';
import { BarChart, DoughnutChart, LineChart } from '@/components/charts';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import type { BadgeColor } from '@/lib/status';
import { formatDateTime, formatNumber, labelize, timeAgo } from '@/lib/format';
import { useDebounce, useMediaQuery } from '@/lib/hooks';
import { ModeBanner, StatusTicks, WaBubble, WaPhoneFrame, waRunWorker } from '../shared';
import { useCampaign, useCampaignMeta, useCampaignRefresh, useRecipients } from './api';
import { businessHoursText, durationText, futureText, samplePreview, templateTitle } from './lib';
import type { CampaignDetail, CampaignRecipient } from './types';
import { CMP_BREAD, CampaignStatusBadge, CategoryBadge, DeliveryBar, TypeTile, rate, useCampaignActions } from './ui';

const RECIPIENT_TABS = [
  { key: '', label: 'All' },
  { key: 'read', label: 'Read' },
  { key: 'delivered', label: 'Delivered' },
  { key: 'sent', label: 'Sent' },
  { key: 'replied', label: 'Replied' },
  { key: 'failed', label: 'Failed' },
  { key: 'skipped', label: 'Skipped' },
  { key: 'pending', label: 'Waiting' },
] as const;

const TIMELINE_ICON: Record<string, { icon: LucideIcon; tone: Tone }> = {
  created: { icon: Pencil, tone: 'slate' },
  update: { icon: Pencil, tone: 'slate' },
  duplicate: { icon: Copy, tone: 'slate' },
  upload: { icon: Upload, tone: 'cyan' },
  test: { icon: FlaskConical, tone: 'purple' },
  scheduled: { icon: CalendarClock, tone: 'blue' },
  schedule: { icon: CalendarClock, tone: 'blue' },
  started: { icon: Send, tone: 'orange' },
  send: { icon: Send, tone: 'orange' },
  paused: { icon: Pause, tone: 'amber' },
  pause: { icon: Pause, tone: 'amber' },
  resume: { icon: Play, tone: 'green' },
  retry: { icon: RotateCcw, tone: 'amber' },
  completed: { icon: CircleCheck, tone: 'green' },
  cancelled: { icon: CircleX, tone: 'red' },
  cancel: { icon: CircleX, tone: 'red' },
  failed: { icon: TriangleAlert, tone: 'red' },
  export: { icon: Download, tone: 'navy' },
};

const CampaignEditorPage = lazy(() => import('./CampaignEditorPage'));

/**
 * /whatsapp/campaigns/:id — the report; with ?step=… the editor (for creators editing their own draft and publishers
 * sending from the Review step, whom the `/:id/edit` route — gated on the `edit` permission — would turn away).
 */
export default function CampaignDetailRoute() {
  const [search] = useSearchParams();
  if (search.get('step')) {
    return (
      <Suspense fallback={<PageLoader />}>
        <CampaignEditorPage />
      </Suspense>
    );
  }
  return <CampaignDetailPage />;
}

function CampaignDetailPage() {
  const id = Number(useParams().id) || 0;
  const navigate = useNavigate();
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useCampaignRefresh();
  const actions = useCampaignActions();
  const q = useCampaign(id);
  const c = q.data;
  const [workerBusy, setWorkerBusy] = useState(false);
  const canRunWorker = can('whatsapp', 'manage') || can('whatsapp_settings', 'manage');

  if (q.error && !c) {
    const notFound = (q.error as ApiError).status === 404;
    return (
      <div>
        <PageHeader title="Campaign" breadcrumbs={[...CMP_BREAD, { label: 'Campaigns', to: '/whatsapp/campaigns' }, { label: 'Not found' }]} />
        {notFound ? (
          <Card>
            <EmptyState icon={Send} title="Campaign not found" description="It may have been deleted." action={<Button to="/whatsapp/campaigns">Back to campaigns</Button>} />
          </Card>
        ) : (
          <Alert variant="error" title="The campaign could not be loaded" action={<Button size="sm" variant="secondary" onClick={() => q.refetch()}>Retry</Button>}>
            {(q.error as Error).message}
          </Alert>
        )}
      </div>
    );
  }

  if (!c) {
    return (
      <div>
        <PageHeader title="Campaign" breadcrumbs={[...CMP_BREAD, { label: 'Campaigns', to: '/whatsapp/campaigns' }, { label: '…' }]} />
        <div className="grid grid-cols-2 gap-4 lg:grid-cols-6">
          {Array.from({ length: 6 }, (_, i) => (
            <Skeleton key={i} className="h-28 rounded-2xl" />
          ))}
        </div>
        <div className="mt-6 grid gap-4 lg:grid-cols-3">
          <div className="lg:col-span-2">
            <CardSkeleton lines={8} />
          </div>
          <CardSkeleton lines={6} />
        </div>
      </div>
    );
  }

  const started = !!c.started_at;
  const runWorker = async () => {
    setWorkerBusy(true);
    try {
      const res = await waRunWorker();
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setWorkerBusy(false);
    }
  };

  const menu: DropdownItem[] = [
    actions.canCreate && { label: 'Duplicate as new draft', icon: Copy, onClick: () => actions.duplicate(c) },
    actions.canExport && actions.xlsx && started && { label: 'Recipient report (Excel)', icon: FileSpreadsheet, onClick: () => actions.exportReport(c, 'xlsx') },
    actions.canExport && started && { label: 'Recipient report (CSV)', icon: Download, onClick: () => actions.exportReport(c, 'csv') },
    actions.canExport && c.failed_count > 0 && { label: 'Failed report (CSV)', icon: TriangleAlert, onClick: () => actions.exportReport(c, 'csv', 'failed') },
    actions.canPublish && ['scheduled', 'sending', 'paused'].includes(c.status) && { label: 'Cancel campaign', icon: XCircle, danger: true, divider: true, onClick: () => actions.cancel(c) },
    actions.canDelete &&
      (['draft', 'cancelled', 'failed'].includes(c.status) || (c.status === 'completed' && actions.canManage)) && {
        label: 'Delete campaign',
        icon: Trash2,
        danger: true,
        divider: true,
        onClick: () => actions.remove(c, () => navigate('/whatsapp/campaigns')),
      },
  ].filter(Boolean) as DropdownItem[];

  return (
    <div>
      <PageHeader
        title={c.name}
        description={
          <span className="inline-flex flex-wrap items-center gap-x-2 gap-y-1">
            <CampaignStatusBadge status={c.status} />
            <span>{c.type_label}</span>
            {c.template && (
              <>
                <span aria-hidden>·</span>
                <span className="font-mono text-xs">{c.template.name}</span>
                <CategoryBadge category={c.template.category} />
              </>
            )}
            <span aria-hidden>·</span>
            <span>
              by {c.created_by_name ?? 'System'} {timeAgo(c.created_at)}
            </span>
          </span>
        }
        breadcrumbs={[...CMP_BREAD, { label: 'Campaigns', to: '/whatsapp/campaigns' }, { label: c.name }]}
        actions={
          <>
            {actions.canEditCampaign(c) && (
              <Button icon={Pencil} variant={c.status === 'draft' && !c.readiness?.ok ? 'primary' : 'secondary'} to={actions.editPath(c.id, c.status === 'draft' ? undefined : 'review')}>
                {c.status === 'draft' ? 'Continue editing' : 'Edit'}
              </Button>
            )}
            {c.status === 'draft' && actions.canPublish && (c.readiness?.ok || !actions.canEditCampaign(c)) && (
              <Button icon={Send} to={actions.editPath(c.id, 'review')}>
                Review &amp; send
              </Button>
            )}
            {actions.canPublish && c.status === 'sending' && (
              <Button variant="secondary" icon={Pause} onClick={() => actions.pause(c)}>
                Pause
              </Button>
            )}
            {actions.canPublish && c.status === 'paused' && (
              <Button icon={Play} onClick={() => actions.resume(c)}>
                Resume
              </Button>
            )}
            {actions.canPublish && c.failed_count > 0 && ['completed', 'sending', 'paused', 'failed'].includes(c.status) && (
              <Button variant="secondary" icon={RotateCcw} onClick={() => actions.retry(c)}>
                Retry failed ({formatNumber(c.failed_count)})
              </Button>
            )}
            {menu.length > 0 && <Dropdown label="More actions" triggerClassName="btn btn-secondary !px-2.5" trigger={<MoreHorizontal className="h-4 w-4" />} items={menu} />}
          </>
        }
      />
      <ModeBanner />

      {c.status === 'failed' && c.last_error && (
        <Alert variant="error" title="The campaign stopped" className="mb-4">
          {c.last_error}
        </Alert>
      )}

      {!started ? <NotStarted c={c} /> : <Started c={c} canRunWorker={canRunWorker} runWorker={runWorker} workerBusy={workerBusy} />}

      {started && (
        <div className="mt-6">
          <Recipients c={c} />
        </div>
      )}

      <div className="mt-6 grid items-start gap-5 md:grid-cols-2 xl:grid-cols-3">
        {started ? (
          <>
            <TemplateCard c={c} />
            <InfoCard c={c} />
            <TimelineCard c={c} />
          </>
        ) : (
          <>
            <AudienceCard c={c} />
            <TemplateCard c={c} />
            <div className="space-y-5">
              <InfoCard c={c} />
              <TimelineCard c={c} />
            </div>
          </>
        )}
      </div>
    </div>
  );
}

function TimelineCard({ c }: { c: CampaignDetail }) {
  return (
    <Card>
      <CardHeader title="Timeline" icon={History} />
      <div className="max-h-[32rem] overflow-y-auto p-5 pt-0">
        <Timeline
          items={c.timeline.map(
            (t): TimelineItem => ({
              icon: TIMELINE_ICON[t.kind]?.icon ?? Clock,
              tone: TIMELINE_ICON[t.kind]?.tone ?? 'slate',
              title: t.title,
              description: [t.detail, t.by ? `by ${t.by}` : null].filter(Boolean).join(' · ') || undefined,
              time: `${formatDateTime(t.at)} · ${timeAgo(t.at)}`,
            }),
          )}
        />
      </div>
    </Card>
  );
}

function cumulative(list: number[]): number[] {
  let sum = 0;
  return list.map((n) => (sum += n));
}

/* ------------------------------------------------------------------ not started (draft / scheduled) */

function NotStarted({ c }: { c: CampaignDetail }) {
  const actions = useCampaignActions();
  const r = c.readiness;
  const eligible = r?.eligible ?? Math.max(0, c.total_recipients - c.skipped_count);
  const skipped = r?.skipped ?? c.skipped_count;
  const items = [
    { k: 'template', label: 'Approved template', err: r?.errors.template, to: 'template' },
    { k: 'audience', label: 'Audience with recipients', err: r?.errors.audience, to: 'audience' },
    { k: 'personalise', label: 'Variables mapped', err: r?.errors.personalise, to: 'personalise' },
  ];
  return (
    <div className="grid gap-5 lg:grid-cols-3">
      <Card className="overflow-hidden lg:col-span-2">
        <div className="flex flex-col gap-4 p-5 sm:flex-row sm:items-center">
          <TypeTile type={c.type} />
          <div className="min-w-0 flex-1">
            {c.status === 'scheduled' && c.scheduled_at ? (
              <>
                <p className="text-xs font-semibold uppercase tracking-wide text-brand-700 dark:text-brand-300">Scheduled</p>
                <p className="font-display text-xl font-bold text-slate-900 dark:text-white">{formatDateTime(c.scheduled_at)}</p>
                <p className="text-sm text-slate-500 dark:text-slate-400">
                  Starts {futureText(c.scheduled_at) ?? 'any moment now'}. The audience is rebuilt at that moment, so new contacts who match are included.
                </p>
              </>
            ) : (
              <>
                <p className="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Draft</p>
                <p className="font-display text-xl font-bold text-slate-900 dark:text-white">{r?.ok ? 'Ready to send' : 'Not ready yet'}</p>
                <p className="text-sm text-slate-500 dark:text-slate-400">Finish the steps, send yourself a test, then send now or schedule it.</p>
              </>
            )}
          </div>
          <div className="grid grid-cols-2 gap-3 text-center">
            <div className="rounded-xl bg-emerald-50 px-4 py-2 dark:bg-emerald-500/10">
              <p className="font-display text-2xl font-bold tabular-nums text-emerald-800 dark:text-emerald-200">{formatNumber(eligible)}</p>
              <p className="text-xs text-emerald-800/80 dark:text-emerald-200/80">recipients</p>
            </div>
            <div className="rounded-xl bg-slate-100 px-4 py-2 dark:bg-slate-800">
              <p className="font-display text-2xl font-bold tabular-nums text-slate-900 dark:text-white">{formatNumber(skipped)}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">skipped</p>
            </div>
          </div>
        </div>
      </Card>
      <Card>
        <CardHeader title="Checklist" icon={ListChecks} />
        <ul className="space-y-3 p-5 pt-0">
          {items.map((i) => (
            <li key={i.k} className="flex items-start gap-2.5 text-sm">
              {i.err ? <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-amber-600" aria-hidden /> : <CircleCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden />}
              <span className="min-w-0">
                <span className="block font-medium text-slate-800 dark:text-slate-100">{i.label}</span>
                {i.err && (
                  <span className="block text-xs text-slate-500 dark:text-slate-400">
                    {i.err}{' '}
                    {actions.canEditCampaign(c) && (
                      <Link to={actions.editPath(c.id, i.to)} className="font-semibold text-brand-700 hover:underline dark:text-brand-300">
                        Fix
                      </Link>
                    )}
                  </span>
                )}
              </span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

function AudienceCard({ c }: { c: CampaignDetail }) {
  const meta = useCampaignMeta();
  const a = c.audience;
  const f = meta.data?.facets;
  const label = (list: { value: string | number; label: string }[] | undefined, vals: (string | number)[]) => vals.map((v) => list?.find((o) => String(o.value) === String(v))?.label ?? labelize(String(v))).join(', ');
  const rows: { label: string; value: string }[] = [];
  if (a.segment_id) rows.push({ label: 'Saved segment', value: meta.data?.segments.find((s) => s.id === a.segment_id)?.name ?? `#${a.segment_id}` });
  if (a.contact_types.length) rows.push({ label: 'Contact type', value: label(f?.types, a.contact_types) });
  if (a.programs.length) rows.push({ label: 'Programmes', value: label(f?.programs, a.programs) });
  if (a.stages.length) rows.push({ label: 'Lead stage', value: label(f?.stages, a.stages) });
  if (a.admission_status.length) rows.push({ label: 'Admission status', value: label(f?.admission_statuses, a.admission_status) });
  if (a.payment_status.length) rows.push({ label: 'Fee status', value: label(f?.payment_statuses, a.payment_status) });
  if (a.cities.length) rows.push({ label: 'City', value: a.cities.join(', ') });
  if (a.states.length) rows.push({ label: 'State', value: a.states.join(', ') });
  if (a.batches.length) rows.push({ label: 'Batch', value: a.batches.join(', ') });
  if (a.tags.length) rows.push({ label: 'Tags', value: a.tags.map((t) => f?.tags.find((x) => x.id === t)?.name ?? `#${t}`).join(', ') });
  return (
    <Card>
      <CardHeader title="Audience" icon={Users} subtitle={c.audience_source === 'filters' ? 'CRM filters' : c.audience_source === 'csv' ? 'Uploaded list' : 'CRM filters + uploaded list'} />
      <div className="space-y-4 p-5 pt-0">
        {c.audience_source !== 'csv' &&
          (rows.length ? (
            <DescriptionList items={rows.map((r) => ({ label: r.label, value: r.value }))} />
          ) : (
            <p className="text-sm text-slate-600 dark:text-slate-300">All active WhatsApp contacts.</p>
          ))}
        <p className="text-sm text-slate-600 dark:text-slate-300">Only contacts who opted in to WhatsApp{c.audience_source !== 'filters' ? ' (or marked “yes” in the consent column of the list)' : ''} — everyone else is skipped.</p>
        {c.upload && c.audience_source !== 'filters' && (
          <div className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 p-3 dark:border-slate-700">
            <FileSpreadsheet className="h-6 w-6 text-emerald-600" aria-hidden />
            <div className="min-w-0 flex-1 text-sm">
              <p className="truncate font-semibold text-slate-900 dark:text-white">{c.upload.file_name}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">
                {formatNumber(c.upload.valid_rows)} valid · {formatNumber(c.upload.invalid_rows)} invalid · {formatNumber(c.upload.duplicate_rows)} duplicate
              </p>
            </div>
          </div>
        )}
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ started */

function Started({ c, canRunWorker, runWorker, workerBusy }: { c: CampaignDetail; canRunWorker: boolean; runWorker: () => void; workerBusy: boolean }) {
  const waiting = c.status_counts.pending + c.status_counts.queued;
  const eligible = Math.max(0, c.total_recipients - c.skipped_count);
  const funnel = [eligible, c.sent_count, c.delivered_count, c.read_count, c.replied_count];
  const outcome = [
    { label: 'Read', value: c.read_count, color: '#2F5FB0' },
    { label: 'Delivered', value: Math.max(0, c.delivered_count - c.read_count), color: '#10B981' },
    { label: 'Sent', value: Math.max(0, c.sent_count - c.delivered_count), color: '#38BDF8' },
    { label: 'Failed', value: c.failed_count, color: '#EF4444' },
    { label: 'Skipped', value: c.skipped_count, color: '#94A3B8' },
    { label: 'Waiting', value: waiting, color: '#F59E0B' },
  ].filter((o) => o.value > 0);

  return (
    <>
      {(c.status === 'sending' || c.status === 'paused') && (
        <Card className="mb-5 overflow-hidden">
          <div className="flex flex-col gap-4 p-5 md:flex-row md:items-center">
            <div className="min-w-0 flex-1">
              <div className="flex items-baseline justify-between gap-3">
                <p className="text-sm font-semibold text-slate-900 dark:text-white">{c.status === 'paused' ? 'Paused' : 'Sending'} · {c.progress.percent}% done</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  {formatNumber(c.progress.processed)} of {formatNumber(c.total_recipients)} processed
                </p>
              </div>
              <ProgressBar value={c.progress.percent} tone={c.status === 'paused' ? 'purple' : 'orange'} className="mt-2 !h-3" label="Campaign progress" />
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                {c.status === 'paused'
                  ? `${formatNumber(waiting)} recipient(s) are waiting. Resume to continue.`
                  : c.respect_business_hours && !c.in_business_hours
                    ? 'Waiting for business hours to continue.'
                    : `${formatNumber(waiting)} left at ${formatNumber(c.rate_effective)} / min — ${c.eta_minutes ? durationText(c.eta_minutes) : 'finishing up'}.`}
                {c.mode === 'test' && ' Test mode: messages are simulated.'}
              </p>
            </div>
            {canRunWorker && c.status === 'sending' && (
              <Button variant="secondary" icon={Zap} loading={workerBusy} onClick={runWorker}>
                Process queue now
              </Button>
            )}
          </div>
        </Card>
      )}

      <Stagger className="grid grid-cols-2 gap-3 sm:gap-4 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="a" label="Audience" value={c.total_recipients} icon={Users} tone="navy" hint={`${formatNumber(c.skipped_count)} skipped by policy`} />,
          <StatCard key="s" label="Sent" value={c.sent_count} icon={Send} tone="orange" hint={`${formatNumber(waiting)} waiting`} />,
          <StatCard key="d" label="Delivered" value={c.delivered_count} icon={CheckCheck} tone="green" hint={`${rate(c.progress.delivery_rate)} of sent`} />,
          <StatCard key="r" label="Read" value={c.read_count} icon={Eye} tone="blue" hint={`${rate(c.progress.read_rate)} of delivered`} />,
          <StatCard key="p" label="Replied" value={c.replied_count} icon={MessageCircleReply} tone="purple" hint={`${rate(c.progress.reply_rate)} within 72 h`} />,
          <StatCard key="f" label="Failed" value={c.failed_count} icon={TriangleAlert} tone="red" hint={`${rate(c.progress.failure_rate)} of attempts`} />,
        ]}
      </Stagger>

      <Card className="mt-5 p-5">
        <DeliveryBar campaign={c} legend height="h-3" />
      </Card>

      <div className="mt-5 grid gap-5 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Delivery over time" subtitle={`Cumulative, ${c.activity?.granularity === 'day' ? 'per day' : c.activity?.granularity === 'hour' ? 'per hour' : 'every 10 minutes'}`} />
            <div className="p-5 pt-0">
              {c.activity && c.activity.labels.length > 0 ? (
                <LineChart
                  height={260}
                  labels={c.activity.labels}
                  series={[
                    { label: 'Sent', data: cumulative(c.activity.series.sent), color: '#F05A28' },
                    { label: 'Delivered', data: cumulative(c.activity.series.delivered), color: '#10B981' },
                    { label: 'Read', data: cumulative(c.activity.series.read), color: '#2F5FB0', fill: true },
                    { label: 'Replied', data: cumulative(c.activity.series.replied), color: '#8B5CF6' },
                    { label: 'Failed', data: cumulative(c.activity.series.failed), color: '#EF4444', dashed: true },
                  ]}
                  legend
                  beginAtZero
                  options={c.activity.labels.length > 18 ? { elements: { point: { radius: 0, hoverRadius: 4 } } } : undefined}
                  ariaLabel="Campaign messages sent, delivered, read, replied and failed over time"
                />
              ) : (
                <EmptyState icon={Clock} title="No activity yet" description="The chart fills in as messages go out." />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Outcome" />
            <div className="p-5 pt-0">
              {outcome.length ? (
                <DoughnutChart
                  height={190}
                  labels={outcome.map((o) => o.label)}
                  data={outcome.map((o) => o.value)}
                  colors={outcome.map((o) => o.color)}
                  centerValue={`${c.progress.percent}%`}
                  centerLabel="processed"
                  valueFormat="number"
                  ariaLabel="Recipients by outcome"
                />
              ) : (
                <EmptyState icon={Users} title="No recipients" />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="mt-5 grid gap-5 lg:grid-cols-3">
        <Reveal className="lg:col-span-2">
          <Card className="h-full">
            <CardHeader title="Funnel" subtitle="From eligible recipients to replies" />
            <div className="p-5 pt-0">
              <BarChart
                horizontal
                height={220}
                labels={['Eligible', 'Sent', 'Delivered', 'Read', 'Replied']}
                series={[{ label: 'Recipients', data: funnel, color: '#2F5FB0' }]}
                beginAtZero
                barThickness={26}
                ariaLabel="Campaign funnel: eligible, sent, delivered, read, replied"
              />
            </div>
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <ReasonsCard c={c} />
        </Reveal>
      </div>
    </>
  );
}

function ReasonsCard({ c }: { c: CampaignDetail }) {
  const actions = useCampaignActions();
  const failedTotal = c.reasons.failed.reduce((s, r) => s + r.count, 0);
  return (
    <Card className="h-full">
      <CardHeader
        title="Failures & skips"
        icon={AlertTriangle}
        actions={
          actions.canExport && c.failed_count > 0 ? (
            <Button size="xs" variant="ghost" icon={Download} onClick={() => actions.exportReport(c, 'csv', 'failed')}>
              Failed report
            </Button>
          ) : undefined
        }
      />
      <div className="space-y-4 p-5 pt-0">
        {c.reasons.failed.length === 0 && c.reasons.skipped.length === 0 ? (
          <p className="flex items-center gap-2 text-sm text-emerald-700 dark:text-emerald-300">
            <CircleCheck className="h-4 w-4" aria-hidden /> No failures or skips.
          </p>
        ) : null}
        {c.reasons.failed.length > 0 && (
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-red-600 dark:text-red-300">Failed · {formatNumber(failedTotal)}</p>
            <ul className="space-y-2">
              {c.reasons.failed.map((r) => (
                <li key={r.reason} className="text-sm">
                  <div className="flex items-start justify-between gap-2">
                    <span className="min-w-0 text-slate-700 dark:text-slate-200">
                      {r.reason}
                      {r.code && <span className="ml-1 font-mono text-[11px] text-slate-400">#{r.code}</span>}
                    </span>
                    <span className="shrink-0 font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(r.count)}</span>
                  </div>
                  <ProgressBar value={failedTotal ? (r.count / failedTotal) * 100 : 0} tone="red" className="mt-1 !h-1.5" />
                </li>
              ))}
            </ul>
            {actions.canPublish && ['completed', 'sending', 'paused', 'failed'].includes(c.status) && (
              <Button size="sm" variant="secondary" icon={RotateCcw} className="mt-3" onClick={() => actions.retry(c)}>
                Retry failed
              </Button>
            )}
          </div>
        )}
        {c.reasons.skipped.length > 0 && (
          <div>
            <p className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Skipped · {formatNumber(c.skipped_count)}</p>
            <ul className="space-y-1.5">
              {c.reasons.skipped.map((r) => (
                <li key={r.reason} className="flex items-center justify-between gap-2 text-sm">
                  <span className="flex min-w-0 items-center gap-2 text-slate-700 dark:text-slate-200">
                    <Ban className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />
                    <span className="truncate">{r.label}</span>
                  </span>
                  <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(r.count)}</span>
                </li>
              ))}
            </ul>
          </div>
        )}
      </div>
    </Card>
  );
}

/* ------------------------------------------------------------------ recipients */

function recipientColor(s: string): BadgeColor {
  return s === 'read' ? 'blue' : s === 'delivered' || s === 'sent' ? 'green' : s === 'failed' ? 'red' : s === 'skipped' ? 'slate' : 'amber';
}

function Recipients({ c }: { c: CampaignDetail }) {
  const meta = useCampaignMeta();
  const actions = useCampaignActions();
  const [params, setParams] = useSearchParams();
  const status = params.get('rs') ?? '';
  const reason = params.get('reason') ?? '';
  const source = params.get('source') ?? '';
  const page = Math.max(1, Number(params.get('rp')) || 1);
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v);
    else next.delete(k);
    if (k !== 'rp') next.delete('rp');
    if (k === 'rs' && v !== 'skipped') next.delete('reason');
    setParams(next, { replace: true });
  };
  const query = useMemo(() => ({ status: status || undefined, reason: reason || undefined, source: source || undefined, q: dq || undefined, page, per_page: 25 }), [status, reason, source, dq, page]);
  const r = useRecipients(c.id, query, c.status === 'sending');
  const wide = useMediaQuery('(min-width: 768px)');
  const counts = r.data?.counts;

  const columns: Column<CampaignRecipient>[] = [
    {
      key: 'name',
      header: 'Recipient',
      render: (x) => (
        <div className="min-w-[11rem]">
          {x.contact_id ? (
            <Link to={`/whatsapp/contacts/${x.contact_id}`} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
              {x.name || 'Unnamed'}
            </Link>
          ) : (
            <span className="font-semibold text-slate-900 dark:text-white">{x.name || 'Unnamed'}</span>
          )}
          <p className={clsx('text-xs tabular-nums', x.valid_phone ? 'text-slate-500 dark:text-slate-400' : 'text-red-600 dark:text-red-300')}>{x.phone_display}</p>
        </div>
      ),
    },
    {
      key: 'type',
      header: 'Type',
      render: (x) => (
        <div className="whitespace-nowrap text-xs">
          <p className="text-slate-700 dark:text-slate-200">{x.contact_type ? labelize(x.contact_type) : 'New number'}</p>
          <p className="text-slate-400">{x.source === 'csv' ? 'Uploaded list' : 'CRM filter'}</p>
        </div>
      ),
    },
    {
      key: 'status',
      header: 'Status',
      render: (x) => (
        <div className="flex items-center gap-1.5 whitespace-nowrap">
          {['sent', 'delivered', 'read', 'failed', 'queued'].includes(x.status) && <StatusTicks status={x.message_status ?? x.status} size={14} />}
          <Badge color={recipientColor(x.status)}>{x.status === 'pending' ? 'Waiting' : labelize(x.status)}</Badge>
          {x.replied_at && (
            <Badge color="purple">
              <MessageCircleReply className="h-3 w-3" aria-hidden /> Replied
            </Badge>
          )}
        </div>
      ),
    },
    {
      key: 'error',
      header: 'Reason / error',
      render: (x) =>
        x.error ? (
          <p className="max-w-[16rem] text-xs text-slate-600 dark:text-slate-300" title={x.error}>
            <span className="line-clamp-2">{x.error}</span>
            {x.error_code && <span className="font-mono text-[10px] text-slate-400">#{x.error_code}</span>}
          </p>
        ) : (
          <span className="text-slate-400">—</span>
        ),
    },
    {
      key: 'times',
      header: 'Sent · Delivered · Read',
      render: (x) => (
        <div className="whitespace-nowrap text-xs leading-5 text-slate-600 dark:text-slate-300">
          <p>{x.sent_at ? formatDateTime(x.sent_at) : x.queued_at ? <span className="text-amber-700 dark:text-amber-300">Queued {timeAgo(x.queued_at)}</span> : '—'}</p>
          {(x.delivered_at || x.read_at) && (
            <p className="text-slate-400">
              {x.delivered_at ? `✓✓ ${formatDateTime(x.delivered_at).split(', ').pop()}` : ''}
              {x.read_at ? ` · read ${timeAgo(x.read_at)}` : ''}
            </p>
          )}
          {x.replied_at && <p className="text-violet-600 dark:text-violet-300">replied {timeAgo(x.replied_at)}</p>}
        </div>
      ),
    },
  ];

  return (
    <Card className="overflow-hidden">
      <CardHeader
        title="Recipients"
        subtitle={counts ? `${formatNumber(counts.all)} in the report` : undefined}
        icon={Users}
        actions={
          actions.canExport ? (
            <>
              {actions.xlsx && (
                <Button size="sm" variant="secondary" icon={FileSpreadsheet} onClick={() => actions.exportReport(c, 'xlsx', status)}>
                  Excel
                </Button>
              )}
              <Button size="sm" variant="secondary" icon={Download} onClick={() => actions.exportReport(c, 'csv', status)}>
                CSV
              </Button>
            </>
          ) : undefined
        }
      />
      <div className="space-y-3 border-b border-slate-100 px-5 pb-4 dark:border-slate-800">
        <Tabs
          variant="pills"
          value={status}
          onChange={(k) => set('rs', k)}
          tabs={RECIPIENT_TABS.map((t) => ({
            key: t.key,
            label: t.label,
            count: counts ? formatNumber(t.key === '' ? counts.all : t.key === 'pending' ? counts.pending + counts.queued : counts[t.key as keyof typeof counts]) : undefined,
          }))}
        />
        <div className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_12rem_12rem]">
          <SearchInput value={q} onChange={(v) => { setQ(v); set('rp', ''); }} placeholder="Search name or number…" />
          <Select aria-label="Source" value={source} onChange={(e) => set('source', e.target.value)} placeholder="All sources" options={[{ value: 'filter', label: 'CRM filters' }, { value: 'csv', label: 'Uploaded list' }]} />
          {status === 'skipped' ? (
            <Select aria-label="Skip reason" value={reason} onChange={(e) => set('reason', e.target.value)} placeholder="Any reason" options={(meta.data?.skip_reasons ?? []).map((s) => ({ value: s.value, label: s.label }))} />
          ) : (
            <span className="hidden sm:block" />
          )}
        </div>
      </div>
      {r.error && !r.data ? (
        <div className="p-5">
          <Alert variant="error" action={<Button size="sm" variant="secondary" onClick={() => r.refetch()}>Retry</Button>}>
            {(r.error as Error).message}
          </Alert>
        </div>
      ) : (
        <>
          {wide || !r.data ? (
          <DataTable
            columns={columns}
            rows={r.data?.data ?? []}
            rowKey={(x) => x.id}
            loading={r.isLoading || (r.isFetching && r.isPlaceholderData)}
            dense
            skeletonRows={6}
            actions={(x) =>
              x.conversation_id ? (
                <Button size="xs" variant="ghost" icon={MessageCircle} to={`/whatsapp/inbox?c=${x.conversation_id}`} aria-label={`Open chat with ${x.name ?? x.phone_display}`}>
                  <span className="hidden xl:inline">Chat</span>
                </Button>
              ) : null
            }
            empty={<EmptyState icon={Users} title="No recipients here" description={status ? 'Nobody matches this status yet.' : 'The recipient list is empty.'} />}
          />
          ) : r.data && r.data.data.length > 0 ? (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {r.data.data.map((x) => (
                <li key={x.id} className="flex items-start gap-3 px-4 py-3">
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-x-2">
                      {x.contact_id ? (
                        <Link to={`/whatsapp/contacts/${x.contact_id}`} className="font-semibold text-slate-900 dark:text-white">
                          {x.name || 'Unnamed'}
                        </Link>
                      ) : (
                        <span className="font-semibold text-slate-900 dark:text-white">{x.name || 'Unnamed'}</span>
                      )}
                      <span className={clsx('text-xs tabular-nums', x.valid_phone ? 'text-slate-500 dark:text-slate-400' : 'text-red-600 dark:text-red-300')}>{x.phone_display}</span>
                    </div>
                    <div className="mt-1 flex flex-wrap items-center gap-1.5">
                      {['sent', 'delivered', 'read', 'failed', 'queued'].includes(x.status) && <StatusTicks status={x.message_status ?? x.status} size={14} />}
                      <Badge color={recipientColor(x.status)}>{x.status === 'pending' ? 'Waiting' : labelize(x.status)}</Badge>
                      {x.replied_at && <Badge color="purple">Replied</Badge>}
                      <span className="text-xs text-slate-500 dark:text-slate-400">{x.sent_at ? formatDateTime(x.sent_at) : x.contact_type ? labelize(x.contact_type) : 'New number'}</span>
                    </div>
                    {x.error && <p className="mt-1 text-xs text-slate-600 dark:text-slate-300">{x.error}</p>}
                  </div>
                  {x.conversation_id && <Button size="xs" variant="ghost" icon={MessageCircle} to={`/whatsapp/inbox?c=${x.conversation_id}`} aria-label={`Open chat with ${x.name ?? x.phone_display}`} />}
                </li>
              ))}
            </ul>
          ) : (
            <EmptyState icon={Users} title="No recipients here" description={status ? 'Nobody matches this status yet.' : 'The recipient list is empty.'} />
          )}
          {r.data && r.data.meta.total > 0 && (
            <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
              <Pagination page={r.data.meta.page} pages={r.data.meta.pages} total={r.data.meta.total} perPage={r.data.meta.per_page} onPage={(p) => set('rp', String(p))} />
            </div>
          )}
        </>
      )}
    </Card>
  );
}

/* ------------------------------------------------------------------ side cards */

function TemplateCard({ c }: { c: CampaignDetail }) {
  const t = c.template_full;
  return (
    <Card className="overflow-hidden">
      <CardHeader
        title="Message"
        subtitle={t ? `${templateTitle(t)} · ${t.language}` : 'No template chosen'}
        actions={t ? <CategoryBadge category={t.category} /> : undefined}
      />
      <div className="px-4 pb-4">
        {t ? (
          <WaPhoneFrame height={400} subtitle="Sample values">
            <WaBubble direction="out" type="template" template={samplePreview(t)} time={null} showStatus={false} />
          </WaPhoneFrame>
        ) : (
          <EmptyState icon={Send} title="Choose a template" className="!py-8" />
        )}
        {t && t.status !== 'approved' && (
          <Alert variant="warning" className="mt-3">
            The template is now {t.status}; it cannot be sent until it is approved again.
          </Alert>
        )}
      </div>
    </Card>
  );
}

function InfoCard({ c }: { c: CampaignDetail }) {
  const meta = useCampaignMeta();
  return (
    <Card>
      <CardHeader title="Details" icon={c.status === 'paused' ? CirclePause : CirclePlay} />
      <div className="p-5 pt-0">
        <DescriptionList
          columns={1}
          items={[
            { label: 'Description', value: c.description },
            { label: 'Audience', value: `${c.audience_source === 'filters' ? 'CRM filters' : c.audience_source === 'csv' ? 'Uploaded list' : 'CRM filters + list'} · opted-in only` },
            { label: 'Sending speed', value: `${formatNumber(c.rate_effective)} per minute${c.rate_per_minute ? '' : ' (account rate)'}` },
            { label: 'Business hours', value: c.respect_business_hours ? `Only during business hours — ${businessHoursText(meta.data?.business_hours)}` : 'Any time' },
            { label: 'Scheduled for', value: c.scheduled_at ? formatDateTime(c.scheduled_at) : null },
            { label: 'Started', value: c.started_at ? formatDateTime(c.started_at) : null },
            { label: c.status === 'cancelled' ? 'Cancelled' : 'Completed', value: c.cancelled_at ? formatDateTime(c.cancelled_at) : c.completed_at ? formatDateTime(c.completed_at) : null },
            { label: 'WhatsApp cost', value: c.cost !== null ? `₹${c.cost.toLocaleString('en-IN', { maximumFractionDigits: 2 })}` : null },
            { label: 'Interakt campaign', value: c.interakt_campaign_id ? <span className="font-mono text-xs">{c.interakt_campaign_id}</span> : c.mode === 'test' && c.started_at ? 'Not created (test mode)' : null },
          ].filter((i) => i.value !== null && i.value !== undefined && i.value !== '')}
        />
      </div>
    </Card>
  );
}
