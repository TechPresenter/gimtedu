import { useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import {
  Activity,
  ArrowRight,
  BadgeCheck,
  BarChart3,
  CheckCheck,
  ClipboardList,
  Eye,
  GraduationCap,
  Hourglass,
  Inbox,
  IndianRupee,
  Megaphone,
  MessageCircle,
  MessagesSquare,
  Plug,
  RefreshCw,
  Send,
  UserRound,
  Users,
  Workflow,
  XCircle,
  Zap,
  type LucideIcon,
} from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, Stagger, StatCard, useToast } from '@/components/ui';
import { BarChart, DoughnutChart, LineChart } from '@/components/charts';
import { ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, formatPercent, timeAgo } from '@/lib/format';
import { ModeBanner, useWaRefresh, waRunWorker } from '../shared';
import { formatSeconds, useWaDashboard, workerState, type ActivityItem, type DashboardData, type Kpi, type Period } from '../admin/lib';
import { HealthDot, KV, PeriodPicker } from '../admin/ui';
import { FunnelBars } from './FunnelBars';

const KIND_ICON: Record<ActivityItem['kind'], { icon: LucideIcon; cls: string }> = {
  agent: { icon: UserRound, cls: 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200' },
  event: { icon: Zap, cls: 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300' },
  inbound: { icon: MessageCircle, cls: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300' },
  campaign: { icon: Megaphone, cls: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300' },
};

/**
 * WhatsApp Dashboard (Module 1): KPIs for the selected period, delivery / direction trends, the admission funnel,
 * campaign and event performance, the live activity feed, Interakt connection health and automation status.
 */
export default function DashboardPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const period = (['daily', 'weekly', 'monthly', 'custom'].includes(params.get('period') ?? '') ? params.get('period') : 'daily') as Period;
  const from = params.get('from') ?? undefined;
  const to = params.get('to') ?? undefined;
  const query = period === 'custom' && from && to ? { period, from, to } : { period: period === 'custom' ? 'daily' : period };
  const { data, isLoading, isFetching, error, refetch } = useWaDashboard(query);
  const k = data?.kpis;

  const setPeriod = (v: { period: Period; from?: string; to?: string }) => {
    const next = new URLSearchParams(params);
    next.set('period', v.period);
    if (v.period === 'custom' && v.from && v.to) {
      next.set('from', v.from);
      next.set('to', v.to);
    } else {
      next.delete('from');
      next.delete('to');
    }
    if (v.period === 'daily') next.delete('period');
    setParams(next, { replace: true });
  };

  const rangeLabel = data?.range.label ?? (period === 'weekly' ? 'Last 12 weeks' : period === 'monthly' ? 'Last 12 months' : 'Last 14 days');
  const trend = (x?: Kpi) => (x?.trend ? { ...x.trend, label: 'vs prev.' } : undefined);
  // KPI cards only link to pages the viewer may open (the dashboard is also shown to report / settings viewers)
  const link = (to: string, module: string) => (can(module, 'view') ? to : undefined);
  const simulated = Number(k?.sent.simulated ?? 0);

  return (
    <>
      <PageHeader
        title="WhatsApp Dashboard"
        description="Conversations, admissions follow-up, campaigns and automations on the Interakt WhatsApp Business API."
        breadcrumbs={[{ label: 'Dashboard', to: '/' }, { label: 'WhatsApp' }]}
        actions={
          <div className="flex flex-wrap items-center gap-2">
            {can('whatsapp_reports', 'view') && (
              <Button variant="secondary" icon={BarChart3} to="/whatsapp/reports">
                Reports
              </Button>
            )}
            {can('whatsapp', 'view') && (
              <Button variant="secondary" icon={Inbox} to="/whatsapp/inbox">
                Open inbox
              </Button>
            )}
            {can('whatsapp_campaigns', 'create') && (
              <Button icon={Send} to="/whatsapp/campaigns/new">
                New campaign
              </Button>
            )}
          </div>
        }
      />
      <ModeBanner />

      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <PeriodPicker period={period} from={from} to={to} onChange={setPeriod} />
        <div className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
          <span>
            Showing <span className="font-semibold text-slate-700 dark:text-slate-200">{rangeLabel}</span>
          </span>
          <Button size="xs" variant="ghost" icon={RefreshCw} loading={isFetching && !isLoading} onClick={() => refetch()} aria-label="Refresh dashboard">
            Refresh
          </Button>
        </div>
      </div>

      {error ? (
        <Alert variant="error" title="Unable to load the WhatsApp dashboard" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <>
          <Stagger className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7" step={40}>
            {[
              <StatCard key="contacts" label="WhatsApp contacts" icon={Users} tone="navy" loading={isLoading} value={k?.contacts.value ?? 0} to={link('/whatsapp/contacts', 'whatsapp_contacts')} hint={k ? `${formatNumber(k.contacts.opted_in as number)} opted in · +${formatNumber(k.contacts.new)}` : undefined} />,
              <StatCard key="leads" label="Prospective students" icon={GraduationCap} tone="orange" loading={isLoading} value={k?.leads.value ?? 0} to={link('/whatsapp/leads', 'whatsapp_contacts')} hint={k ? `+${formatNumber(k.leads.new)} new` : undefined} />,
              <StatCard key="enq" label="Admission enquiries" icon={ClipboardList} tone="amber" loading={isLoading} value={k?.enquiries.value ?? 0} trend={trend(k?.enquiries)} hint={k ? `${formatNumber(k.enquiries.whatsapp as number)} via WhatsApp` : undefined} />,
              <StatCard key="sent" label="Messages sent" icon={Send} tone="blue" loading={isLoading} value={k?.sent.value ?? 0} trend={simulated > 0 ? undefined : trend(k?.sent)} hint={simulated > 0 ? `${formatNumber(simulated)} simulated (test mode)` : 'Incl. failed'} />,
              <StatCard key="recv" label="Messages received" icon={Inbox} tone="cyan" loading={isLoading} value={k?.received.value ?? 0} trend={trend(k?.received)} to={link('/whatsapp/inbox', 'whatsapp')} />,
              <StatCard key="del" label="Delivered" icon={CheckCheck} tone="green" loading={isLoading} value={k?.delivered.value ?? 0} hint={k ? `${formatPercent(k.delivered.rate, 1)} of sent` : undefined} />,
              <StatCard key="read" label="Read" icon={Eye} tone="purple" loading={isLoading} value={k?.read.value ?? 0} hint={k ? `${formatPercent(k.read.rate, 1)} read rate` : undefined} />,
              <StatCard key="fail" label="Failed" icon={XCircle} tone="red" loading={isLoading} value={k?.failed.value ?? 0} hint={k ? `${formatPercent(k.failed.rate, 1)} of sent` : undefined} to={link('/whatsapp/api?tab=queue', 'whatsapp_settings')} />,
              <StatCard key="conv" label="Active conversations" icon={MessagesSquare} tone="navy" loading={isLoading} value={k?.conversations.value ?? 0} to={link('/whatsapp/inbox', 'whatsapp')} hint="Open or pending" />,
              <StatCard key="pend" label="Awaiting reply" icon={Hourglass} tone="pink" loading={isLoading} value={k?.pending_replies.value ?? 0} to={link('/whatsapp/inbox', 'whatsapp')} hint={k ? `${formatNumber(k.pending_replies.unassigned as number)} unassigned` : undefined} />,
              <StatCard key="adm" label="Admissions confirmed" icon={BadgeCheck} tone="green" loading={isLoading} value={k?.admissions.value ?? 0} trend={trend(k?.admissions)} to={link('/whatsapp/leads', 'whatsapp_contacts')} />,
              <StatCard key="fee" label="Fee notifications" icon={IndianRupee} tone="amber" loading={isLoading} value={k?.fee_notifications.value ?? 0} trend={trend(k?.fee_notifications)} hint="Payment event messages" />,
              <StatCard key="auto" label="Active automations" icon={Workflow} tone="cyan" loading={isLoading} value={k?.automations.value ?? 0} to={link('/whatsapp/automations', 'whatsapp_automation')} hint={k ? `${formatNumber(k.automations.events as number)} events active` : undefined} />,
              <StatCard key="cmp" label="Active campaigns" icon={Megaphone} tone="purple" loading={isLoading} value={k?.campaigns.value ?? 0} to={link('/whatsapp/campaigns', 'whatsapp_campaigns')} hint={k ? `${formatNumber(k.campaigns.sending as number)} sending · ${formatNumber(k.campaigns.scheduled as number)} scheduled` : undefined} />,
            ]}
          </Stagger>

          <div className="mt-6 grid grid-cols-1 gap-5 xl:grid-cols-12">
            <Reveal className="xl:col-span-8">
              <Card className="h-full">
                <CardHeader title="Message delivery" subtitle={`Sent, delivered, read and failed · ${rangeLabel}`} icon={Send} />
                <div className="card-body">
                  {isLoading || !data ? (
                    <Skeleton className="h-64 w-full" />
                  ) : data.charts.delivery.sent.some((v) => v > 0) || data.charts.delivery.failed.some((v) => v > 0) ? (
                    <LineChart
                      labels={data.charts.labels}
                      legend
                      gradient
                      height={330}
                      series={[
                        { label: 'Sent', data: data.charts.delivery.sent, color: '#1D4ED8' },
                        { label: 'Delivered', data: data.charts.delivery.delivered, color: '#22943F' },
                        { label: 'Read', data: data.charts.delivery.read, color: '#8B5CF6' },
                        { label: 'Failed', data: data.charts.delivery.failed, color: '#EF4444', dashed: true },
                      ]}
                      ariaLabel="Message delivery trend"
                    />
                  ) : (
                    <EmptyState icon={Send} title="No messages in this period" description="Messages sent by agents, events, campaigns and automations appear here." />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="xl:col-span-4" delay={60}>
              <ConnectionCard data={data} loading={isLoading} onRefresh={() => refetch()} />
            </Reveal>

            <Reveal className="xl:col-span-6">
              <Card className="h-full">
                <CardHeader title="Inbound vs outbound" subtitle="Conversation volume" icon={MessagesSquare} />
                <div className="card-body">
                  {isLoading || !data ? (
                    <Skeleton className="h-60 w-full" />
                  ) : data.charts.direction.outbound.some((v) => v > 0) || data.charts.direction.inbound.some((v) => v > 0) ? (
                    <BarChart
                      labels={data.charts.labels}
                      legend
                      height={310}
                      series={[
                        { label: 'Outbound', data: data.charts.direction.outbound, color: '#0B2A5B' },
                        { label: 'Inbound', data: data.charts.direction.inbound, color: '#F05A28' },
                      ]}
                      ariaLabel="Inbound and outbound messages"
                    />
                  ) : (
                    <EmptyState icon={MessagesSquare} title="No conversations yet" description="Inbound chats need the Interakt Advanced plan with the message_received webhook." />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="xl:col-span-6" delay={60}>
              <Card className="h-full">
                <CardHeader
                  title="Admission enquiry funnel"
                  subtitle={data ? `${formatNumber(data.charts.funnel.total)} leads · ${formatPercent(data.charts.funnel.conversion_rate, 1)} converted · ${formatNumber(data.charts.funnel.lost)} not interested` : 'Leads created in the period'}
                  icon={GraduationCap}
                  actions={can('whatsapp_reports', 'view') ? <Button size="xs" variant="ghost" iconRight={ArrowRight} to={`/whatsapp/reports?report=conversion&${params.toString()}`}>Details</Button> : undefined}
                />
                <div className="card-body">
                  {isLoading || !data ? (
                    <Skeleton className="h-60 w-full" />
                  ) : data.charts.funnel.total > 0 ? (
                    <FunnelBars stages={data.charts.funnel.stages} compact />
                  ) : (
                    <EmptyState icon={GraduationCap} title="No leads in this period" description="Enquiries from the website, admissions portal and WhatsApp land here." />
                  )}
                </div>
              </Card>
            </Reveal>

            <Reveal className="xl:col-span-7">
              <Card className="h-full">
                <CardHeader
                  title="Campaign performance"
                  subtitle="Delivery and read rate of the biggest campaigns"
                  icon={Megaphone}
                  actions={can('whatsapp_campaigns', 'view') ? <Button size="xs" variant="ghost" iconRight={ArrowRight} to="/whatsapp/campaigns">All campaigns</Button> : undefined}
                />
                <div className="card-body">
                  {isLoading || !data ? (
                    <Skeleton className="h-60 w-full" />
                  ) : data.charts.campaigns.length ? (
                    <BarChart
                      horizontal
                      percent
                      legend
                      max={100}
                      height={Math.max(200, data.charts.campaigns.length * 52)}
                      labels={data.charts.campaigns.map((c) => (c.name.length > 34 ? `${c.name.slice(0, 33)}…` : c.name))}
                      series={[
                        { label: 'Delivered %', data: data.charts.campaigns.map((c) => c.delivery_rate), color: '#22943F' },
                        { label: 'Read %', data: data.charts.campaigns.map((c) => c.read_rate), color: '#F05A28' },
                      ]}
                      barThickness={14}
                      ariaLabel="Campaign delivery and read rates"
                    />
                  ) : (
                    <EmptyState icon={Megaphone} title="No campaigns sent in this period" action={can('whatsapp_campaigns', 'create') ? <Button size="sm" to="/whatsapp/campaigns/new">Create a campaign</Button> : undefined} />
                  )}
                </div>
              </Card>
            </Reveal>
            <Reveal className="xl:col-span-5" delay={60}>
              <Card className="h-full">
                <CardHeader
                  title="Event executions"
                  subtitle="GIMT business events by category"
                  icon={Zap}
                  actions={can('whatsapp_automation', 'view') ? <Button size="xs" variant="ghost" iconRight={ArrowRight} to="/whatsapp/events?tab=logs">Logs</Button> : undefined}
                />
                <div className="card-body">
                  {isLoading || !data ? (
                    <Skeleton className="h-60 w-full" />
                  ) : data.charts.events.length ? (
                    <DoughnutChart
                      labels={data.charts.events.map((e) => e.label)}
                      data={data.charts.events.map((e) => e.total)}
                      valueFormat="number"
                      centerValue={formatNumber(data.charts.events.reduce((a, e) => a + e.total, 0))}
                      centerLabel="events"
                      height={210}
                      ariaLabel="Event executions by category"
                    />
                  ) : (
                    <EmptyState icon={Zap} title="No events fired in this period" description="Admissions, payments and exams fire events that send WhatsApp messages." />
                  )}
                </div>
              </Card>
            </Reveal>

            <Reveal className="xl:col-span-7">
              <ActivityCard items={data?.activity} loading={isLoading} />
            </Reveal>
            <Reveal className="xl:col-span-5" delay={60}>
              <AutomationCard data={data} loading={isLoading} rangeLabel={rangeLabel} />
            </Reveal>
          </div>
        </>
      )}
    </>
  );
}

function ConnectionCard({ data, loading, onRefresh }: { data?: DashboardData; loading: boolean; onRefresh: () => void }) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const [running, setRunning] = useState(false);
  const c = data?.connection;
  const runWorker = async () => {
    setRunning(true);
    try {
      const r = await waRunWorker();
      toast.success(r.message || 'Worker run complete.');
      await refresh();
      onRefresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setRunning(false);
    }
  };
  const health = c ? (c.health === 'ok' ? 'ok' : c.health === 'warning' ? 'warning' : 'error') : 'off';
  const worker = c ? workerState(c) : null;
  return (
    <Card className="h-full">
      <CardHeader
        title="API connection"
        subtitle="Interakt WhatsApp Business API"
        icon={Plug}
        actions={
          c && (
            <Badge color={c.mode === 'live' ? 'green' : 'amber'} dot>
              {c.mode === 'live' ? 'Live' : 'Test mode'}
            </Badge>
          )
        }
      />
      <div className="card-body">
        {loading || !c ? (
          <div className="space-y-3">
            {[0, 1, 2, 3, 4].map((i) => (
              <Skeleton key={i} className="h-5 w-full" />
            ))}
          </div>
        ) : (
          <>
            <div
              className={clsx(
                'mb-3 flex items-start gap-2.5 rounded-xl px-3 py-2.5 text-sm',
                health === 'ok' && 'bg-emerald-50 text-emerald-800 dark:bg-emerald-500/10 dark:text-emerald-200',
                health === 'warning' && 'bg-amber-50 text-amber-900 dark:bg-amber-500/10 dark:text-amber-100',
                health === 'error' && 'bg-red-50 text-red-800 dark:bg-red-500/10 dark:text-red-200',
              )}
            >
              <span className="mt-1.5">
                <HealthDot state={health} pulse={health === 'ok'} />
              </span>
              <div className="min-w-0">
                <p className="font-semibold">
                  {health === 'ok' ? 'All systems healthy' : health === 'warning' ? 'Needs attention' : c.webhook_health === 'silent' || c.webhook_health === 'bad_signature' ? 'Webhook not working' : 'Not connected'}
                </p>
                {c.issues.length > 0 && <p className="mt-0.5 text-xs opacity-90">{c.issues.join(' · ')}</p>}
              </div>
            </div>
            <dl className="divide-y divide-slate-100 dark:divide-slate-800">
              <KV label="API key">{c.configured ? <Badge color="green">Configured</Badge> : <Badge color="amber">Not saved</Badge>}</KV>
              <KV label="Plan">
                <span className="capitalize">{c.plan}</span>
                <span className="block text-xs font-normal text-slate-500 dark:text-slate-400">{c.incoming_supported ? 'Incoming messages enabled' : 'Incoming messages need Advanced+'}</span>
              </KV>
              <KV label="Last webhook">
                {c.last_webhook_at ? (
                  <span title={formatDateTime(c.last_webhook_at)}>
                    {timeAgo(c.last_webhook_at)}
                    {c.last_webhook_simulated && <span className="ml-1 text-xs font-normal text-slate-500">(simulated)</span>}
                  </span>
                ) : (
                  'Never'
                )}
                {(c.webhook_health === 'silent' || c.webhook_health === 'bad_signature') && (
                  <span className="mt-0.5 block max-w-[16rem] text-xs font-normal text-red-600 dark:text-red-400" title={c.webhook_health_note ?? undefined}>
                    {c.webhook_health === 'silent' ? 'Silent — re-enable it in Interakt › Developer Settings' : 'Failing the signature check'}
                  </span>
                )}
              </KV>
              <KV label="Background worker">
                <span className="inline-flex items-center gap-1.5" title={worker?.detail}>
                  <HealthDot state={worker?.state ?? 'off'} />
                  {c.worker.healthy && c.last_cron_at ? timeAgo(c.last_cron_at) : worker?.title}
                </span>
                {!c.worker.healthy && (
                  <span className="mt-0.5 block max-w-[16rem] text-xs font-normal text-slate-500 dark:text-slate-400">
                    {c.pages_only
                      ? `Processed only while WhatsApp pages are open (last ${c.light_tick_age !== null && c.light_tick_age !== undefined ? `${formatSeconds(c.light_tick_age)} ago` : 'recently'})`
                      : c.last_cron_at
                        ? `Cron last ran ${timeAgo(c.last_cron_at)}`
                        : 'Add the cron job (Integrations)'}
                  </span>
                )}
              </KV>
              <KV label="Queue backlog">
                {formatNumber(c.queue.due)} due · {formatNumber(c.queue.pending)} pending
              </KV>
              <KV label="Failures (24 h)">
                <span className={clsx((c.webhooks_24h.failed + c.webhooks_24h.bad_signature + c.api_24h.failed) > 0 && 'text-red-600 dark:text-red-400')}>
                  {formatNumber(c.webhooks_24h.failed + c.webhooks_24h.bad_signature)} webhook · {formatNumber(c.api_24h.failed)} API · {formatNumber(c.queue.failed_24h)} messages
                </span>
              </KV>
            </dl>
            <div className="mt-4 flex flex-wrap gap-2">
              {can('whatsapp_settings', 'view') && (
                <Button size="sm" variant="secondary" icon={Plug} to="/whatsapp/integrations">
                  Integrations
                </Button>
              )}
              {(can('whatsapp_settings', 'manage') || can('whatsapp', 'manage')) && (
                <Button size="sm" variant="soft" icon={RefreshCw} loading={running} onClick={runWorker}>
                  Run worker now
                </Button>
              )}
            </div>
          </>
        )}
      </div>
    </Card>
  );
}

/** Permission module each activity kind links into. */
const KIND_MODULE: Record<ActivityItem['kind'], string> = { agent: 'whatsapp', inbound: 'whatsapp', event: 'whatsapp_automation', campaign: 'whatsapp_campaigns' };

function ActivityCard({ items, loading }: { items?: ActivityItem[]; loading: boolean }) {
  const { can } = useAuth();
  return (
    <Card className="h-full">
      <CardHeader title="Recent WhatsApp activity" subtitle="Messages, events, campaigns and agent actions" icon={Activity} />
      <div className="card-body">
        {loading || !items ? (
          <div className="space-y-3">
            {[0, 1, 2, 3, 4, 5].map((i) => (
              <Skeleton key={i} className="h-10 w-full" />
            ))}
          </div>
        ) : items.length ? (
          <ul className="max-h-[26rem] space-y-1 overflow-y-auto pr-1">
            {items.map((it) => {
              const v = KIND_ICON[it.kind];
              const body = (
                <>
                  <span className={clsx('mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg', v.cls)}>
                    <v.icon className="h-4 w-4" aria-hidden />
                  </span>
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-medium text-slate-800 dark:text-slate-100">{it.title}</span>
                    {it.detail && <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{it.detail}</span>}
                  </span>
                  <time className="shrink-0 text-[11px] text-slate-400" dateTime={it.at} title={formatDateTime(it.at)}>
                    {timeAgo(it.at)}
                  </time>
                </>
              );
              return (
                <li key={it.id}>
                  {it.link && can(KIND_MODULE[it.kind], 'view') ? (
                    <Link to={it.link} className="flex items-start gap-3 rounded-xl px-2 py-2 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:hover:bg-slate-800/60">
                      {body}
                    </Link>
                  ) : (
                    <div className="flex items-start gap-3 rounded-xl px-2 py-2">{body}</div>
                  )}
                </li>
              );
            })}
          </ul>
        ) : (
          <EmptyState icon={Activity} title="No activity yet" description="Agent replies, events and campaigns show up here as they happen." />
        )}
      </div>
    </Card>
  );
}

function AutomationCard({ data, loading, rangeLabel }: { data?: DashboardData; loading: boolean; rangeLabel: string }) {
  const { can } = useAuth();
  const a = data?.automation;
  const tiles: { label: string; value: string; tone?: string }[] = a
    ? [
        { label: 'Active workflows', value: formatNumber(a.workflows_active) },
        { label: 'Runs today', value: formatNumber(a.runs_today) },
        { label: 'Failed today', value: formatNumber(a.failed_today), tone: a.failed_today > 0 ? 'text-red-600 dark:text-red-400' : undefined },
        { label: 'In progress', value: formatNumber(a.in_progress) },
        { label: 'Events today', value: formatNumber(a.events_today) },
        { label: 'Follow-ups due', value: formatNumber(a.followups_due) },
      ]
    : [];
  return (
    <Card className="h-full">
      <CardHeader
        title="Automation status"
        subtitle={a ? `${formatPercent(a.completion_rate, 1)} of ${formatNumber(a.runs_window)} runs completed · ${rangeLabel}` : 'Workflows, events and follow-ups'}
        icon={Workflow}
        actions={can('whatsapp_automation', 'view') ? <Button size="xs" variant="ghost" iconRight={ArrowRight} to="/whatsapp/automations">Builder</Button> : undefined}
      />
      <div className="card-body">
        {loading || !a ? (
          <Skeleton className="h-56 w-full" />
        ) : (
          <>
            {a.paused && (
              <Alert variant="warning" className="mb-3" title="Automations paused">
                Automated messages are on hold (WhatsApp › Settings › Automation).
              </Alert>
            )}
            <div className="grid grid-cols-3 gap-2">
              {tiles.map((t) => (
                <div key={t.label} className="rounded-xl bg-slate-50 px-3 py-2.5 dark:bg-slate-800/60">
                  <p className={clsx('font-display text-lg font-bold tabular-nums text-slate-900 dark:text-white', t.tone)}>{t.value}</p>
                  <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{t.label}</p>
                </div>
              ))}
            </div>
            <h4 className="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">Active workflows</h4>
            {a.workflows.length ? (
              <ul className="space-y-2">
                {a.workflows.map((w) => {
                  const rate = w.runs ? Math.round((w.completed / w.runs) * 100) : 0;
                  const inner = (
                    <>
                        <div className="flex items-center justify-between gap-2">
                          <span className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{w.name}</span>
                          <span className="shrink-0 text-xs text-slate-500 dark:text-slate-400">{formatNumber(w.runs_today)} today</span>
                        </div>
                        <div className="mt-1.5 flex items-center gap-2">
                          <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                            <div className="h-full rounded-full bg-emerald-500" style={{ width: `${rate}%` }} />
                          </div>
                          <span className="text-[11px] tabular-nums text-slate-500 dark:text-slate-400">
                            {formatNumber(w.runs)} runs{w.failed ? ` · ${formatNumber(w.failed)} failed` : ''}
                          </span>
                        </div>
                    </>
                  );
                  const box = 'block rounded-xl border border-slate-100 px-3 py-2 dark:border-slate-800';
                  return (
                    <li key={w.id}>
                      {can('whatsapp_automation', 'view') ? (
                        <Link to={`/whatsapp/automations/${w.id}`} className={clsx(box, 'transition hover:-translate-y-0.5 hover:shadow-soft')}>
                          {inner}
                        </Link>
                      ) : (
                        <div className={box}>{inner}</div>
                      )}
                    </li>
                  );
                })}
              </ul>
            ) : (
              <p className="text-sm text-slate-500 dark:text-slate-400">No active workflows.</p>
            )}
          </>
        )}
      </div>
    </Card>
  );
}
