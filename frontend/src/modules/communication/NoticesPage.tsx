import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { BarChart3, CalendarClock, CheckCheck, Copy, Eye, FilePen, Hourglass, Megaphone, Paperclip, Pin, PinOff, Plus, Printer, RotateCcw, Send, Undo2, Users } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, StatCard, Stagger, StatusBadge, Tabs, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { BarChart, DoughnutChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber, formatTime } from '@/lib/format';
import type { Row } from '@/lib/types';
import { ChipFilter, DeliveryProgressModal, NOTICE_CATEGORIES, NOTICE_STATES, openApiPrint, processBatch, useCommRefresh, type QueueProgress } from './shared';
import { NoticeDrawer } from './notices/NoticeDrawer';
import { NoticeFormModal } from './notices/NoticeFormModal';
import { PublishDialog } from './notices/PublishDialog';
import type { NoticeSummary } from './notices/types';

interface Sending {
  title: string;
  initial: QueueProgress | null;
  step: () => Promise<QueueProgress>;
}

export default function NoticesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useCommRefresh();
  const [params, setParams] = useSearchParams();
  const tab = ['live', 'scheduled', 'draft', 'expired'].includes(String(params.get('state'))) ? String(params.get('state')) : 'all';
  const category = NOTICE_CATEGORIES[String(params.get('category'))] ? String(params.get('category')) : '';
  const { data: s, isLoading, error } = useApi<NoticeSummary>(['comm', 'notices-summary'], 'notices/summary');

  const [viewId, setViewId] = useState<number | null>(Number(params.get('view')) || null);
  const [form, setForm] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });
  const [publishId, setPublishId] = useState<number | null>(null);
  const [sending, setSending] = useState<Sending | null>(null);

  useEffect(() => {
    const v = Number(params.get('view')) || null;
    if (v) setViewId(v);
  }, [params]);

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    next.delete('page');
    setParams(next, { replace: true });
  };
  const closeView = () => {
    setViewId(null);
    if (params.get('view')) setParam('view', '');
  };

  const resume = (id: number, title: string) =>
    setSending({ title: `Sending “${title}”`, initial: null, step: () => api.post<QueueProgress>(`notices/${id}/resume`).then((r) => r.data) });

  const quick = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh('crud');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const duplicate = async (r: Row) => {
    try {
      const res = await api.post<{ id: number }>(`notices/${r.id}/duplicate`);
      toast.success(res.message);
      await refresh('crud');
      setForm({ open: true, id: res.data.id });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const tabs = [
    { key: 'all', label: 'All', count: s?.total },
    { key: 'live', label: 'Live', count: s?.live },
    { key: 'scheduled', label: 'Scheduled', count: s?.scheduled },
    { key: 'draft', label: 'Drafts', count: s?.draft },
    { key: 'expired', label: 'Expired', count: s?.expired },
  ];
  const scope: Record<string, string> = {};
  if (tab !== 'all') scope.state = tab;
  if (category) scope.category = category;

  return (
    <>
      <PageHeader
        title="Notices & Circulars"
        description="Publish notices to students, faculty, staff, parents and alumni — on the website, by e-mail, SMS or WhatsApp and as in-app alerts."
        breadcrumbs={[{ label: 'Communication' }, { label: 'Notices & Circulars' }]}
        actions={
          can('notices', 'create') && (
            <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>
              New notice
            </Button>
          )
        }
      />

      {error && <Alert variant="error" className="mb-6" title="Unable to load notice statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="live" label="Live notices" icon={Megaphone} tone="green" loading={isLoading} value={s?.live ?? 0} hint={s ? `${formatNumber(s.pinned)} pinned to the top` : undefined} />,
          <StatCard key="sched" label="Scheduled" icon={CalendarClock} tone="blue" loading={isLoading} value={s?.scheduled ?? 0} hint="Go live automatically" />,
          <StatCard key="draft" label="Drafts" icon={FilePen} tone="amber" loading={isLoading} value={s?.draft ?? 0} hint="Not visible to anyone" />,
          <StatCard key="exp" label="Expiring in 7 days" icon={Hourglass} tone="orange" loading={isLoading} value={s?.expiring ?? 0} hint={s ? `${formatNumber(s.expired)} already expired` : undefined} />,
          <StatCard key="del" label="Delivered (30 days)" icon={CheckCheck} tone="purple" loading={isLoading} value={s?.delivered_30d ?? 0} hint={s ? `${formatNumber(s.failed_30d)} failed` : undefined} />,
          <StatCard key="views" label="Website views" icon={Eye} tone="cyan" loading={isLoading} value={s?.views ?? 0} hint={s ? `${formatNumber(s.this_month)} notices this month` : undefined} />,
        ]}
      </Stagger>

      {s && s.pending_notices.length > 0 && can('notices', 'publish') && (
        <Alert variant="warning" className="mb-4" title={`${formatNumber(s.pending)} notice messages are waiting to be sent`}>
          <div className="mt-1 flex flex-wrap gap-2">
            {s.pending_notices.map((p) => (
              <Button key={p.id} size="xs" variant="secondary" icon={RotateCcw} onClick={() => resume(p.id, p.title)}>
                Resume “{p.title.length > 40 ? `${p.title.slice(0, 39)}…` : p.title}” ({formatNumber(p.pending)})
              </Button>
            ))}
          </div>
        </Alert>
      )}

      <div className="mb-4 space-y-3">
        <Tabs tabs={tabs} value={tab} onChange={(k) => setParam('state', k === 'all' ? '' : k)} variant="pills" />
        <ChipFilter
          allLabel="All categories"
          value={category}
          onChange={(k) => setParam('category', k)}
          items={(s?.by_category ?? []).map((c) => ({ key: c.key, label: c.label, count: c.total }))}
        />
      </div>

      <CrudTable
        key={`${tab}-${category}`}
        module="notices"
        urlState
        scope={Object.keys(scope).length ? scope : undefined}
        hideFilters={['category']}
        title={tab === 'all' ? 'All notices' : `${NOTICE_STATES[tab]?.label ?? ''} notices`}
        addLabel="New notice"
        emptyTitle={tab === 'draft' ? 'No drafts' : 'No notices here yet'}
        emptyText="Create a notice for students, faculty, staff or parents — publish it on the website and send it by e-mail in one go."
        onCreate={() => setForm({ open: true, id: null })}
        onEdit={(r) => setForm({ open: true, id: Number(r.id) })}
        onView={(r) => setViewId(Number(r.id))}
        renderers={{
          title: (r) => (
            <button type="button" onClick={() => setViewId(Number(r.id))} className="block max-w-[16rem] text-left leading-tight">
              <span className="flex items-center gap-1.5">
                {Number(r.is_pinned) ? <Pin className="h-3.5 w-3.5 shrink-0 text-brand-700 dark:text-brand-300" aria-label="Pinned" /> : null}
                <span className="truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300" title={String(r.title)}>{String(r.title)}</span>
              </span>
              <span className="mt-0.5 flex items-center gap-1.5 text-xs text-slate-500 dark:text-slate-400">
                <Users className="h-3 w-3 shrink-0" />
                <span className="truncate">{String(r.audience_label)}</span>
                {r.attachment ? <Paperclip className="h-3 w-3 shrink-0" aria-label="Has attachment" /> : null}
              </span>
            </button>
          ),
          category: (r) => {
            const c = NOTICE_CATEGORIES[String(r.category)];
            return <Badge color={c?.color ?? 'slate'}>{c?.label ?? String(r.category)}</Badge>;
          },
          state: (r) => <StatusBadge status={String(r.state)} label={NOTICE_STATES[String(r.state)]?.label} colors={{ [String(r.state)]: NOTICE_STATES[String(r.state)]?.color ?? 'slate' }} />,
          publish_date: (r) =>
            r.publish_date ? (
              <span className="whitespace-nowrap text-sm leading-tight">
                {formatDate(r.publish_date)}
                <span className="block text-xs text-slate-500 dark:text-slate-400">{formatTime(String(r.publish_date).slice(11, 16))}</span>
              </span>
            ) : (
              <span className="text-xs text-slate-400">{r.state === 'draft' ? 'Not published' : 'Immediately'}</span>
            ),
          delivered_count: (r) =>
            Number(r.delivered_count) || Number(r.failed_count) ? (
              <span className="whitespace-nowrap text-sm tabular-nums">
                {formatNumber(r.delivered_count)}
                {Number(r.failed_count) ? <span className="block text-[11px] text-red-600 dark:text-red-400">{formatNumber(r.failed_count)} failed</span> : null}
              </span>
            ) : (
              <span className="text-slate-400">—</span>
            ),
        }}
        rowMenu={(r) => [
          can('notices', 'publish') && r.state !== 'expired' && { label: r.state === 'draft' ? 'Publish…' : 'Send to audience…', icon: Send, onClick: () => setPublishId(Number(r.id)) },
          can('notices', 'publish') && (r.state === 'live' || r.state === 'scheduled') && { label: 'Unpublish', icon: Undo2, onClick: () => void quick(() => api.post(`notices/${r.id}/unpublish`)) },
          can('notices', 'edit') && { label: Number(r.is_pinned) ? 'Unpin' : 'Pin to top', icon: Number(r.is_pinned) ? PinOff : Pin, onClick: () => void quick(() => api.post(`notices/${r.id}/pin`, { pinned: !Number(r.is_pinned) })) },
          can('notices', 'create') && { label: 'Duplicate', icon: Copy, onClick: () => void duplicate(r) },
          { label: 'Print circular', icon: Printer, onClick: () => openApiPrint(`notices/${r.id}/print`) },
        ]}
      />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
        <Reveal className="lg:col-span-3">
          <Card className="h-full">
            <CardHeader title="Notices by category" subtitle="All notices vs those live right now" icon={BarChart3} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-60 w-full" />
              ) : s.by_category.length === 0 ? (
                <EmptyState icon={Megaphone} title="No notices yet" className="py-8" />
              ) : (
                <BarChart
                  labels={s.by_category.map((c) => c.label)}
                  height={250}
                  series={[
                    { label: 'All notices', data: s.by_category.map((c) => c.total), color: '#1D4ED8' },
                    { label: 'Live', data: s.by_category.map((c) => c.live), color: '#22943F' },
                  ]}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="lg:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Audience mix" subtitle="Who the notices are addressed to" icon={Users} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-52 w-full" />
              ) : s.by_audience.length === 0 ? (
                <EmptyState icon={Users} title="No notices yet" className="py-8" />
              ) : (
                <DoughnutChart labels={s.by_audience.map((a) => a.label)} data={s.by_audience.map((a) => a.total)} centerValue={formatNumber(s.total)} centerLabel="Notices" height={180} />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <NoticeDrawer
        id={viewId}
        onClose={closeView}
        onEdit={(id) => setForm({ open: true, id })}
        onPublish={(id) => setPublishId(id)}
        onResume={resume}
      />
      <NoticeFormModal
        open={form.open}
        id={form.id}
        onClose={() => setForm({ open: false, id: null })}
        onSaved={(id, publish) => {
          setForm({ open: false, id: null });
          void refresh('crud');
          if (publish) setPublishId(id);
          else setViewId(id);
        }}
      />
      <PublishDialog
        id={publishId}
        onClose={() => setPublishId(null)}
        onPublished={(res, title) => {
          setPublishId(null);
          void refresh('crud');
          if (res.batch && res.progress && !res.progress.done) {
            const batch = res.batch;
            setSending({ title: `Sending “${title}”`, initial: res.progress, step: () => processBatch(batch) });
          }
        }}
      />
      <DeliveryProgressModal
        open={!!sending}
        title={sending?.title ?? ''}
        description="Delivering the notice to its audience."
        initial={sending?.initial}
        step={sending?.step ?? (() => Promise.reject(new Error('idle')))}
        onClose={() => {
          setSending(null);
          void refresh('crud');
        }}
        onFinished={() => void refresh('crud')}
      />
    </>
  );
}
