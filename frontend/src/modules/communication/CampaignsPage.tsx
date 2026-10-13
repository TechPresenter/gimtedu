import { useEffect, useRef, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BarChart3, CalendarClock, CheckCheck, Copy, Eye, FilePen, FlaskConical, MailPlus, MailX, PieChart, Send, SendHorizonal, UserCheck, Users, XCircle } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, PageHeader, ProgressBar, Reveal, Skeleton, StatCard, Stagger, StatusBadge, Tabs } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { BarChart, DoughnutChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CAMPAIGN_STATUS_COLORS, useCommRefresh, type QueueProgress } from './shared';
import { useCampaignActions } from './campaigns/actions';
import { CampaignComposer } from './campaigns/CampaignComposer';
import { CampaignDrawer } from './campaigns/CampaignDrawer';
import { CronCard } from './campaigns/CronCard';
import type { NewsletterSummary } from './campaigns/types';

/** Keeps campaigns in "sending" moving while this screen is open (also dispatches scheduled campaigns when due). */
function useAutoDispatch(active: NewsletterSummary['active'], enabled: boolean, onProgress: (id: number, p: QueueProgress) => void, onDone: () => void) {
  const key = active.map((a) => a.id).join(',');
  const progressRef = useRef(onProgress);
  progressRef.current = onProgress;
  const doneRef = useRef(onDone);
  doneRef.current = onDone;
  useEffect(() => {
    if (!enabled || !key) return;
    let alive = true;
    (async () => {
      for (const id of key.split(',').map(Number)) {
        let guard = 0;
        while (alive && guard++ < 400) {
          const p = await api.post<QueueProgress>(`newsletter/campaigns/${id}/process`).then((r) => r.data);
          if (!alive) return;
          progressRef.current(id, p);
          if (p.done || p.status !== 'sending') break;
        }
      }
      if (alive) doneRef.current();
    })().catch(() => undefined);
    return () => {
      alive = false;
    };
  }, [key, enabled]);
}

export default function CampaignsPage() {
  const { can } = useAuth();
  const refresh = useCommRefresh();
  const [params, setParams] = useSearchParams();
  const tab = ['draft', 'scheduled', 'sent'].includes(String(params.get('status'))) ? String(params.get('status')) : 'all';
  const { data: s, isLoading, error } = useApi<NewsletterSummary>(['comm', 'newsletter-summary'], 'newsletter/summary');
  const [viewId, setViewId] = useState<number | null>(Number(params.get('view')) || null);
  const [composer, setComposer] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });
  const [live, setLive] = useState<Record<number, QueueProgress>>({});
  const changed = () => void refresh('crud');
  const actions = useCampaignActions(changed);

  useEffect(() => {
    const v = Number(params.get('view')) || null;
    if (v) setViewId(v);
  }, [params]);

  useAutoDispatch(
    s?.active ?? [],
    can('communication', 'publish') && !actions.sendingId,
    (id, p) => setLive((m) => ({ ...m, [id]: p })),
    changed,
  );

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    next.delete('page');
    setParams(next, { replace: true });
  };

  const ref = (r: Row) => ({ id: Number(r.id), title: String(r.title), channel: String(r.channel), recipients: Number(r.recipients_count) || undefined });

  const tabs = [
    { key: 'all', label: 'All campaigns' },
    { key: 'draft', label: 'Drafts', count: s?.campaigns.drafts },
    { key: 'scheduled', label: 'Scheduled', count: s?.campaigns.scheduled },
    { key: 'sent', label: 'Sent', count: s?.campaigns.sent },
  ];
  const sub = s?.subscribers;

  return (
    <>
      <PageHeader
        title="Newsletter & Campaigns"
        description="Compose newsletters and announcements, choose an audience and track real delivery."
        breadcrumbs={[{ label: 'Communication' }, { label: 'Newsletter / Email', to: '/newsletter' }, { label: 'Campaigns' }]}
        actions={
          <>
            <Button variant="secondary" icon={Users} to="/newsletter/subscribers">
              Subscribers
            </Button>
            {can('communication', 'create') && (
              <Button icon={MailPlus} onClick={() => setComposer({ open: true, id: null })}>
                New campaign
              </Button>
            )}
          </>
        }
      />

      {error && <Alert variant="error" className="mb-6" title="Unable to load newsletter statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="subs" label="Active subscribers" icon={UserCheck} tone="green" loading={isLoading} value={sub?.subscribed ?? 0} hint={sub ? `+${formatNumber(sub.new_month)} this month` : undefined} to="/newsletter/subscribers" />,
          <StatCard key="sent" label="Campaigns sent" icon={SendHorizonal} tone="blue" loading={isLoading} value={s?.campaigns.sent ?? 0} hint={s ? `${formatNumber(s.campaigns.drafts)} drafts` : undefined} />,
          <StatCard key="del" label="Messages delivered" icon={CheckCheck} tone="navy" loading={isLoading} value={s?.campaigns.delivered ?? 0} hint={s ? `${formatNumber(s.last30.sent)} in the last 30 days` : undefined} />,
          <StatCard key="fail" label="Failed deliveries" icon={XCircle} tone="red" loading={isLoading} value={s?.campaigns.failed ?? 0} hint={s ? `${formatNumber(s.last30.failed)} in the last 30 days` : undefined} />,
          <StatCard key="sched" label="Scheduled" icon={CalendarClock} tone="amber" loading={isLoading} value={s?.campaigns.scheduled ?? 0} hint={s?.next_scheduled ? `Next ${formatDateTime(s.next_scheduled.scheduled_at)}` : 'Nothing scheduled'} />,
          <StatCard key="unsub" label="Unsubscribed" icon={MailX} tone="slate" loading={isLoading} value={sub?.unsubscribed ?? 0} hint={sub ? `${formatNumber(sub.bounced)} bounced` : undefined} />,
        ]}
      </Stagger>

      {s && s.active.length > 0 && (
        <Card className="mb-6 border-violet-200 dark:border-violet-500/30">
          <CardHeader title="Sending now" subtitle={can('communication', 'publish') ? 'Delivery continues automatically while this screen is open.' : 'A colleague with publish rights must keep the campaign moving.'} icon={Send} />
          <div className="card-body space-y-4">
            {s.active.map((a) => {
              const p = live[a.id] ?? a.progress;
              return (
                <div key={a.id}>
                  <div className="mb-1.5 flex flex-wrap items-baseline justify-between gap-2 text-sm">
                    <button type="button" onClick={() => setViewId(a.id)} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{a.title}</button>
                    <span className="tabular-nums text-slate-500">{formatNumber(p.sent)} sent · {formatNumber(p.failed)} failed · {formatNumber(p.pending)} pending</span>
                  </div>
                  <ProgressBar value={p.percent} tone="purple" label={`Progress of ${a.title}`} />
                </div>
              );
            })}
          </div>
        </Card>
      )}

      <Tabs tabs={tabs} value={tab} onChange={(k) => setParam('status', k === 'all' ? '' : k)} variant="pills" className="mb-4" />

      <CrudTable
        key={tab}
        module="email_campaigns"
        urlState
        scope={tab === 'all' ? undefined : { status: tab }}
        title={tab === 'all' ? 'Campaign history' : `${tabs.find((t) => t.key === tab)?.label} campaigns`}
        addLabel="New campaign"
        emptyTitle={tab === 'all' ? 'No campaigns yet' : 'Nothing here'}
        emptyText="Write a newsletter or announcement, choose an audience and send it — or schedule it for later."
        onCreate={() => setComposer({ open: true, id: null })}
        onEdit={(r) => (['sending', 'sent'].includes(String(r.status)) ? setViewId(Number(r.id)) : setComposer({ open: true, id: Number(r.id) }))}
        onView={(r) => setViewId(Number(r.id))}
        renderers={{
          title: (r) => (
            <button type="button" onClick={() => setViewId(Number(r.id))} className="block max-w-[14rem] text-left leading-tight">
              <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white" title={String(r.title)}>{String(r.title)}</span>
              <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{String(r.subject_display ?? (r.channel !== 'email' ? `${String(r.channel).toUpperCase()} message` : '—'))}</span>
            </button>
          ),
          recipients_count: (r) => {
            const total = Number(r.recipients_count);
            if (!total) return <span className="text-xs text-slate-400">Not sent</span>;
            if (r.status === 'scheduled') return <span className="text-sm tabular-nums">{formatNumber(total)} <span className="text-xs text-slate-400">recipients</span></span>;
            const pct = Math.round(((Number(r.sent_count) + Number(r.failed_count)) / total) * 100);
            return (
              <div className="min-w-[8rem]">
                <div className="flex justify-between text-xs">
                  <span className="font-semibold tabular-nums text-slate-800 dark:text-slate-100">{formatNumber(r.sent_count)}<span className="font-normal text-slate-400"> / {formatNumber(total)}</span></span>
                  {Number(r.failed_count) > 0 && <span className="text-red-600 dark:text-red-400">{formatNumber(r.failed_count)} failed</span>}
                </div>
                <ProgressBar value={pct} tone={r.status === 'sending' ? 'purple' : 'green'} className="mt-1 !h-1.5" />
              </div>
            );
          },
          sent_at: (r) =>
            r.sent_at ? <span className="whitespace-nowrap text-sm">{formatDateTime(r.sent_at)}</span> : r.scheduled_at ? <span className="whitespace-nowrap text-sm text-brand-700 dark:text-brand-300">{formatDateTime(r.scheduled_at)}</span> : <span className="text-slate-400">—</span>,
          audience_label: (r) => <span className="block max-w-[8.5rem] truncate text-sm" title={String(r.audience_label)}>{String(r.audience_label)}</span>,
          status: (r) => <StatusBadge status={String(r.status)} colors={CAMPAIGN_STATUS_COLORS} />,
        }}
        rowMenu={(r) => {
          const st = String(r.status);
          const editable = ['draft', 'scheduled', 'failed', 'cancelled'].includes(st);
          return [
            { label: 'View report', icon: Eye, onClick: () => setViewId(Number(r.id)) },
            can('communication', 'create') && editable && { label: 'Send a test', icon: FlaskConical, onClick: () => actions.openTest(ref(r)) },
            can('communication', 'publish') && editable && { label: 'Send now', icon: Send, onClick: () => void actions.sendNow(ref(r)) },
            can('communication', 'publish') && editable && { label: st === 'scheduled' ? 'Reschedule' : 'Schedule', icon: CalendarClock, onClick: () => actions.openSchedule(ref(r)) },
            can('communication', 'publish') && (st === 'scheduled' || st === 'sending') && { label: st === 'sending' ? 'Stop sending' : 'Cancel schedule', icon: XCircle, onClick: () => void actions.cancel({ ...ref(r), status: st }) },
            can('communication', 'create') && { label: 'Duplicate', icon: Copy, onClick: () => void actions.duplicate(ref(r), (id) => setComposer({ open: true, id })) },
          ];
        }}
      />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
        <Reveal className="lg:col-span-3">
          <Card className="h-full">
            <CardHeader title="Subscriber growth" subtitle="New subscriptions vs unsubscribes (last 6 months)" icon={BarChart3} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-60 w-full" />
              ) : (
                <BarChart
                  labels={s.growth.labels}
                  height={250}
                  series={[
                    { label: 'Subscribed', data: s.growth.joined, color: '#22943F' },
                    { label: 'Unsubscribed', data: s.growth.left, color: '#F43F5E' },
                  ]}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="lg:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Where subscribers come from" subtitle="Active subscribers by sign-up source" icon={PieChart} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-52 w-full" />
              ) : s.by_source.length === 0 ? (
                <EmptyState icon={Users} title="No subscribers yet" description="Subscribers join from the website footer or are imported here." className="py-8" />
              ) : (
                <DoughnutChart labels={s.by_source.map((x) => x.label)} data={s.by_source.map((x) => x.count)} centerValue={formatNumber(s.subscribers.subscribed)} centerLabel="Subscribers" height={180} />
              )}
            </div>
          </Card>
        </Reveal>
        {s && s.recent.length > 0 && (
          <Reveal className="lg:col-span-5" delay={60}>
            <Card>
              <CardHeader title="Recent campaign delivery" subtitle="Delivered vs failed for the latest sent campaigns" icon={FilePen} />
              <div className="card-body">
                <BarChart
                  labels={s.recent.map((r) => (r.title.length > 26 ? `${r.title.slice(0, 25)}…` : r.title))}
                  height={240}
                  stacked
                  series={[
                    { label: 'Delivered', data: s.recent.map((r) => r.sent), color: '#1D4ED8' },
                    { label: 'Failed', data: s.recent.map((r) => r.failed), color: '#F43F5E' },
                  ]}
                />
              </div>
            </Card>
          </Reveal>
        )}
      </div>

      {s?.cron.url && can('communication', 'manage') && (
        <Reveal className="mt-6">
          <CronCard cron={s.cron} onRan={() => void refresh('crud')} />
        </Reveal>
      )}

      <CampaignDrawer
        id={viewId}
        onClose={() => {
          setViewId(null);
          if (params.get('view')) setParam('view', '');
        }}
        onEdit={(id) => {
          setViewId(null);
          setComposer({ open: true, id });
        }}
        actions={actions}
        onChanged={changed}
      />
      <CampaignComposer
        open={composer.open}
        id={composer.id}
        channels={s?.channels}
        onClose={() => setComposer({ open: false, id: null })}
        onSaved={(c, next) => {
          changed();
          setComposer(next === 'close' ? { open: false, id: null } : { open: true, id: c.id });
          if (next === 'test') actions.openTest(c);
          if (next === 'schedule') {
            setComposer({ open: false, id: null });
            actions.openSchedule(c);
          }
          if (next === 'send') {
            setComposer({ open: false, id: null });
            void actions.sendNow(c);
          }
        }}
      />
      {actions.dialogs}
    </>
  );
}
