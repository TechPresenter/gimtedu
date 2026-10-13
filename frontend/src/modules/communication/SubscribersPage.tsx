import { useSearchParams } from 'react-router-dom';
import { MailCheck, MailMinus, MailPlus, MailWarning, MailX, Send, TrendingUp, UserPlus, Users } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, StatCard, Stagger, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { BarChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatNumber, timeAgo } from '@/lib/format';
import type { Row } from '@/lib/types';
import { SUBSCRIBER_SOURCES, useCommRefresh } from './shared';
import type { NewsletterSummary } from './campaigns/types';

const STATUS_COLORS = { subscribed: 'green', unsubscribed: 'slate', bounced: 'red' } as const;

export default function SubscribersPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCommRefresh();
  const [params, setParams] = useSearchParams();
  const tab = ['subscribed', 'unsubscribed', 'bounced'].includes(String(params.get('status'))) ? String(params.get('status')) : 'all';
  const { data: s, isLoading, error } = useApi<NewsletterSummary>(['comm', 'newsletter-summary'], 'newsletter/summary');
  const sub = s?.subscribers;

  const setTab = (k: string) => {
    const next = new URLSearchParams(params);
    if (k === 'all') next.delete('status');
    else next.set('status', k);
    next.delete('page');
    setParams(next, { replace: true });
  };

  const toggle = async (r: Row, action: 'unsubscribe' | 'resubscribe') => {
    if (action === 'unsubscribe') {
      const ok = await confirm({ title: 'Unsubscribe?', message: <><strong>{String(r.email)}</strong> will stop receiving newsletters and campaigns.</>, confirmText: 'Unsubscribe' });
      if (!ok) return;
    }
    try {
      const res = await api.post(`newsletter/subscribers/${r.id}/${action}`);
      toast.success(res.message);
      await refresh('crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const tabs = [
    { key: 'all', label: 'All', count: sub?.total },
    { key: 'subscribed', label: 'Subscribed', count: sub?.subscribed },
    { key: 'unsubscribed', label: 'Unsubscribed', count: sub?.unsubscribed },
    { key: 'bounced', label: 'Bounced', count: sub?.bounced },
  ];

  return (
    <>
      <PageHeader
        title="Subscribers"
        description="Everyone who receives the newsletter — sign-ups from the website footer, events and admissions, plus imported lists."
        breadcrumbs={[{ label: 'Communication' }, { label: 'Newsletter / Email', to: '/newsletter' }, { label: 'Subscribers' }]}
        actions={
          <Button variant="secondary" icon={Send} to="/newsletter">
            Campaigns
          </Button>
        }
      />

      {error && <Alert variant="error" className="mb-6" title="Unable to load subscriber statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
        {[
          <StatCard key="total" label="Total contacts" icon={Users} tone="navy" loading={isLoading} value={sub?.total ?? 0} hint="All sign-ups ever" />,
          <StatCard key="subs" label="Subscribed" icon={MailCheck} tone="green" loading={isLoading} value={sub?.subscribed ?? 0} hint={sub && sub.total ? `${Math.round((sub.subscribed / sub.total) * 100)}% of contacts` : undefined} />,
          <StatCard key="new" label="New this month" icon={UserPlus} tone="blue" loading={isLoading} value={sub?.new_month ?? 0} hint={sub ? `${formatNumber(sub.lost_month)} unsubscribed this month` : undefined} />,
          <StatCard key="unsub" label="Unsubscribed" icon={MailMinus} tone="slate" loading={isLoading} value={sub?.unsubscribed ?? 0} hint="Opted out via link or by staff" />,
          <StatCard key="bounce" label="Bounced" icon={MailWarning} tone="red" loading={isLoading} value={sub?.bounced ?? 0} hint="Invalid or full mailboxes" />,
        ]}
      </Stagger>

      <Tabs tabs={tabs} value={tab} onChange={setTab} variant="pills" className="mb-4" />

      <CrudTable
        key={tab}
        module="newsletter_subscribers"
        urlState
        scope={tab === 'all' ? undefined : { status: tab }}
        title={tab === 'all' ? 'All subscribers' : `${tabs.find((t) => t.key === tab)?.label} contacts`}
        addLabel="Add subscriber"
        emptyTitle="No subscribers here"
        emptyText="People who sign up on the website appear here automatically. You can also add contacts or import a CSV / Excel list."
        onSaved={() => void refresh('crud')}
        renderers={{
          email: (r) => (
            <div className="min-w-0 leading-tight">
              <a href={`mailto:${r.email}`} className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.email)}</a>
              <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{r.name ? String(r.name) : 'No name given'}</span>
            </div>
          ),
          source: (r) => <Badge color="slate">{SUBSCRIBER_SOURCES[String(r.source)] ?? String(r.source ?? 'Website')}</Badge>,
          subscribed_at: (r) => <span className="whitespace-nowrap text-sm" title={String(r.subscribed_at)}>{timeAgo(r.subscribed_at)}</span>,
          status: (r) => <StatusBadge status={String(r.status)} colors={STATUS_COLORS} />,
        }}
        rowMenu={(r) => [
          can('communication', 'edit') && r.status === 'subscribed' && { label: 'Unsubscribe', icon: MailX, onClick: () => void toggle(r, 'unsubscribe') },
          can('communication', 'edit') && r.status !== 'subscribed' && { label: 'Subscribe again', icon: MailPlus, onClick: () => void toggle(r, 'resubscribe') },
        ]}
      />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
        <Reveal className="lg:col-span-3">
          <Card className="h-full">
            <CardHeader title="Growth" subtitle="New subscriptions vs unsubscribes per month" icon={TrendingUp} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-56 w-full" />
              ) : (
                <BarChart labels={s.growth.labels} height={230} series={[{ label: 'Subscribed', data: s.growth.joined, color: '#22943F' }, { label: 'Unsubscribed', data: s.growth.left, color: '#F43F5E' }]} />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="lg:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Sign-up sources" subtitle="Active subscribers by source" icon={Users} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-56 w-full" />
              ) : s.by_source.length === 0 ? (
                <EmptyState icon={Users} title="No subscribers yet" className="py-8" />
              ) : (
                <ul className="space-y-3">
                  {s.by_source.map((x) => {
                    const pct = s.subscribers.subscribed ? (x.count / s.subscribers.subscribed) * 100 : 0;
                    return (
                      <li key={x.key}>
                        <div className="mb-1 flex justify-between text-sm">
                          <span className="text-slate-700 dark:text-slate-200">{x.label}</span>
                          <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(x.count)} <span className="text-xs font-normal text-slate-400">({Math.round(pct)}%)</span></span>
                        </div>
                        <div className="h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                          <div className="h-full rounded-full bg-brand-600" style={{ width: `${pct}%` }} />
                        </div>
                      </li>
                    );
                  })}
                </ul>
              )}
            </div>
          </Card>
        </Reveal>
      </div>
    </>
  );
}
