import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { AlertTriangle, BarChart3, CheckCheck, Clock3, Gauge, Inbox, RotateCcw, Send, XCircle } from 'lucide-react';
import { Alert, Badge, Card, CardHeader, EmptyState, PageHeader, Reveal, Select, Skeleton, StatCard, Stagger, StatusBadge, Tabs, useConfirm, useToast } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { BarChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import { CHANNELS, useCommRefresh } from './shared';
import { MessageDrawer } from './logs/MessageDrawer';

interface LogSummary {
  days: number;
  total: number;
  sent: number;
  failed: number;
  queued: number;
  failed_open: number;
  delivery_rate: number | null;
  all_time: number;
  by_channel: Record<string, { total: number; sent: number; failed: number; queued: number }>;
  by_type: { key: string | null; label: string; count: number }[];
  errors: { error: string; count: number }[];
  daily: { labels: string[]; sent: number[]; failed: number[]; queued: number[] };
  channels: { email: boolean; sms: boolean; whatsapp: boolean };
}

export default function MessageLogsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useCommRefresh();
  const [params, setParams] = useSearchParams();
  const tab = ['email', 'sms', 'whatsapp'].includes(String(params.get('channel'))) ? String(params.get('channel')) : 'all';
  const [days, setDays] = useState('14');
  const { data: s, isLoading, error } = useApi<LogSummary>(['comm', 'logs-summary', days], 'communication/message-logs/summary', { days });
  const [viewId, setViewId] = useState<number | null>(Number(params.get('view')) || null);

  useEffect(() => {
    const v = Number(params.get('view')) || null;
    if (v) setViewId(v);
  }, [params]);

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params);
    if (value) next.set(key, value);
    else next.delete(key);
    if (key !== 'view') next.delete('page');
    setParams(next, { replace: true });
  };

  const resendOne = async (r: Row) => {
    try {
      const res = await api.post(`communication/message-logs/${r.id}/resend`);
      toast.success(res.message);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      await refresh('crud');
    }
  };

  const resendMany = async (ids: number[], clear: () => void) => {
    const ok = await confirm({ title: `Re-send ${ids.length} messages?`, message: 'Failed and queued messages among the selection will be sent again. Delivered messages are skipped.', confirmText: 'Re-send' });
    if (!ok) return;
    try {
      const res = await api.post('communication/message-logs/resend', { ids });
      toast.success(res.message);
      clear();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      await refresh('crud');
    }
  };

  const ch = s?.by_channel ?? {};
  const tabs = [
    { key: 'all', label: 'All channels', count: s ? formatNumber(s.total) : undefined },
    { key: 'email', label: 'E-mail', count: ch.email ? formatNumber(ch.email.total) : s ? 0 : undefined },
    { key: 'sms', label: 'SMS', count: ch.sms ? formatNumber(ch.sms.total) : s ? 0 : undefined },
    { key: 'whatsapp', label: 'WhatsApp', count: ch.whatsapp ? formatNumber(ch.whatsapp.total) : s ? 0 : undefined },
  ];

  return (
    <>
      <PageHeader
        title="Message Logs"
        description="Every e-mail, SMS and WhatsApp message sent by the ERP — notices, campaigns, receipts, reminders and alerts — with delivery status and errors."
        breadcrumbs={[{ label: 'Communication' }, { label: 'Newsletter / Email', to: '/newsletter' }, { label: 'Message Logs' }]}
        actions={
          <div className="w-40">
            <Select inputSize="sm" value={days} onChange={(e) => setDays(e.target.value)} aria-label="Statistics period" options={[{ value: '7', label: 'Last 7 days' }, { value: '14', label: 'Last 14 days' }, { value: '30', label: 'Last 30 days' }, { value: '90', label: 'Last 90 days' }]} />
          </div>
        }
      />

      {error && <Alert variant="error" className="mb-6" title="Unable to load message statistics">{(error as ApiError).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" step={50}>
        {[
          <StatCard key="total" label={`Messages (${days} days)`} icon={Send} tone="navy" loading={isLoading} value={s?.total ?? 0} hint={s ? `${formatNumber(s.all_time)} all time` : undefined} />,
          <StatCard key="sent" label="Delivered" icon={CheckCheck} tone="green" loading={isLoading} value={s?.sent ?? 0} hint="Accepted by the mail server / gateway" />,
          <StatCard key="failed" label="Failed" icon={XCircle} tone="red" loading={isLoading} value={s?.failed ?? 0} hint={s ? `${formatNumber(s.failed_open)} not re-sent yet` : undefined} />,
          <StatCard key="queued" label="Queued" icon={Clock3} tone="amber" loading={isLoading} value={s?.queued ?? 0} hint="Waiting for an SMS / WhatsApp gateway" />,
          <StatCard key="rate" label="Delivery rate" icon={Gauge} tone="cyan" loading={isLoading} value={s?.delivery_rate != null ? `${s.delivery_rate}%` : '—'} hint="Delivered of sent + failed" />,
        ]}
      </Stagger>

      <Tabs tabs={tabs} value={tab} onChange={(k) => setParam('channel', k === 'all' ? '' : k)} variant="pills" className="mb-4" />

      <CrudTable
        key={tab}
        module="message_logs"
        urlState
        scope={tab === 'all' ? undefined : { channel: tab }}
        title={tab === 'all' ? 'All messages' : `${CHANNELS[tab]?.label ?? ''} messages`}
        emptyTitle="No messages logged"
        emptyText="Messages appear here as soon as the ERP sends e-mails, SMS or WhatsApp messages."
        onView={(r) => setParam('view', String(r.id))}
        renderers={{
          recipient: (r) => {
            const C = CHANNELS[String(r.channel)];
            const Icon = C?.icon;
            return (
              <button type="button" onClick={() => setParam('view', String(r.id))} className="flex max-w-[19rem] items-start gap-2.5 text-left">
                {Icon && <span className="mt-0.5 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-300"><Icon className="h-3.5 w-3.5" /></span>}
                <span className="min-w-0 leading-tight">
                  <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(r.recipient)}</span>
                  <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{String(r.subject ?? (r.channel === 'email' ? '(no subject)' : `${String(r.channel).toUpperCase()} message`))}</span>
                </span>
              </button>
            );
          },
          channel: (r) => <Badge color={CHANNELS[String(r.channel)]?.color ?? 'slate'}>{CHANNELS[String(r.channel)]?.label ?? String(r.channel)}</Badge>,
          related_label: (r) => <span className="block max-w-[11rem] truncate text-sm" title={String(r.related_label)}>{String(r.related_label)}</span>,
          created_at: (r) => <span className="whitespace-nowrap text-sm">{formatDateTime(r.created_at)}</span>,
          status: (r) => (
            <div className="leading-tight">
              <StatusBadge status={String(r.status)} />
              {r.status !== 'sent' && r.retried_ok ? <span className="mt-0.5 block text-[11px] font-medium text-emerald-600 dark:text-emerald-400">Re-sent ✓</span> : null}
              {r.status === 'failed' && r.error ? <span className="mt-0.5 block max-w-[12rem] truncate text-[11px] text-red-600 dark:text-red-400" title={String(r.error)}>{String(r.error)}</span> : null}
            </div>
          ),
        }}
        rowMenu={(r) => [
          can('communication', 'edit') && r.status !== 'sent' && !r.retried_ok && (r.channel === 'email' || !!s?.channels[r.channel as 'sms' | 'whatsapp']) && { label: 'Resend', icon: RotateCcw, onClick: () => void resendOne(r) },
        ]}
        bulkActions={can('communication', 'edit') ? [{ label: 'Re-send failed', icon: RotateCcw, onClick: resendMany }] : []}
      />

      <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-5">
        <Reveal className="lg:col-span-3">
          <Card className="h-full">
            <CardHeader title="Daily volume" subtitle={`Messages per day, last ${days} days`} icon={BarChart3} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-60 w-full" />
              ) : (
                <BarChart
                  labels={s.daily.labels}
                  height={250}
                  stacked
                  series={[
                    { label: 'Delivered', data: s.daily.sent, color: '#22943F' },
                    { label: 'Failed', data: s.daily.failed, color: '#F43F5E' },
                    { label: 'Queued', data: s.daily.queued, color: '#F59E0B' },
                  ]}
                />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal className="lg:col-span-2" delay={80}>
          <Card className="h-full">
            <CardHeader title="Top failure reasons" subtitle={`Last ${days} days`} icon={AlertTriangle} />
            <div className="card-body">
              {isLoading || !s ? (
                <Skeleton className="h-52 w-full" />
              ) : s.errors.length === 0 ? (
                <EmptyState icon={Inbox} title="No failures" description="Every message in this period was delivered or is queued." className="py-8" />
              ) : (
                <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                  {s.errors.map((er) => (
                    <li key={er.error} className="flex items-start justify-between gap-3 py-2.5 text-sm first:pt-0">
                      <span className="min-w-0 break-words text-slate-700 dark:text-slate-200">{er.error}</span>
                      <span className="shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-red-700 dark:bg-red-500/10 dark:text-red-300">{formatNumber(er.count)}</span>
                    </li>
                  ))}
                </ul>
              )}
              {s && s.by_type.length > 0 && (
                <div className="mt-5 border-t border-slate-100 pt-4 dark:border-slate-800">
                  <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">By module</p>
                  <div className="flex flex-wrap gap-1.5">
                    {s.by_type.map((t) => (
                      <span key={t.label} className="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600 dark:bg-slate-800 dark:text-slate-300">
                        {t.label} <strong className="tabular-nums">{formatNumber(t.count)}</strong>
                      </span>
                    ))}
                  </div>
                </div>
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <MessageDrawer id={viewId} onClose={() => { setViewId(null); setParam('view', ''); }} onOpen={(id) => setParam('view', String(id))} />
    </>
  );
}
