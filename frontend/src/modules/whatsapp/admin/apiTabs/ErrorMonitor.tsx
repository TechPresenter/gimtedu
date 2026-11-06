import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, MessageSquareX, ScrollText, Webhook } from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, Skeleton, Stagger, StatCard } from '@/components/ui';
import { LineChart } from '@/components/charts';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatNumber, formatPercent, labelize } from '@/lib/format';
import type { ErrorSummary } from '../lib';

/** Error monitoring: failure rates of API calls, messages and webhooks + the most frequent errors. */
export default function ErrorMonitor() {
  const [days, setDays] = useState(7);
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['wa', 'admin', 'errors', days],
    queryFn: ({ signal }) => api.get<ErrorSummary>('whatsapp/logs/errors', { days }, { signal }),
  });

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-slate-500 dark:text-slate-400">Failure rates and the most frequent errors.</p>
        <div className="pill-tabs" role="tablist" aria-label="Window">
          {[1, 7, 30].map((d) => (
            <button key={d} type="button" role="tab" aria-selected={days === d} className={`pill-tab ${days === d ? 'pill-tab-active' : ''}`} onClick={() => setDays(d)}>
              {d === 1 ? 'Today' : `${d} days`}
            </button>
          ))}
        </div>
      </div>
      {error ? (
        <Alert variant="error" title="Could not load error monitoring" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
      ) : (
        <>
          <Stagger className="grid grid-cols-1 gap-3 sm:grid-cols-3" step={50}>
            {[
              <StatCard key="m" label="Message failure rate" icon={MessageSquareX} tone="red" loading={isLoading} value={data ? formatPercent(data.messages.rate, 1) : 0} hint={data ? `${formatNumber(data.messages.failed)} of ${formatNumber(data.messages.total)} messages` : undefined} to="/whatsapp/api?tab=queue" />,
              <StatCard key="a" label="API failure rate" icon={ScrollText} tone="orange" loading={isLoading} value={data ? formatPercent(data.api.rate, 1) : 0} hint={data ? `${formatNumber(data.api.failed)} of ${formatNumber(data.api.total)} calls` : undefined} to="/whatsapp/api?tab=api" />,
              <StatCard key="w" label="Webhook failure rate" icon={Webhook} tone="amber" loading={isLoading} value={data ? formatPercent(data.webhooks.rate, 1) : 0} hint={data ? `${formatNumber(data.webhooks.failed)} of ${formatNumber(data.webhooks.total)} webhooks` : undefined} to="/whatsapp/api?tab=webhooks" />,
            ]}
          </Stagger>
          <Card>
            <CardHeader title="Failures per day" icon={AlertTriangle} subtitle={data ? `Outbox now: ${Object.entries(data.outbox).map(([k, v]) => `${formatNumber(v)} ${k}`).join(' · ') || 'empty'}` : undefined} />
            <div className="card-body">
              {isLoading || !data ? (
                <Skeleton className="h-60 w-full" />
              ) : (
                <LineChart
                  labels={data.labels}
                  legend
                  height={240}
                  beginAtZero
                  series={[
                    { label: 'Messages', data: data.messages.series, color: '#EF4444' },
                    { label: 'API calls', data: data.api.series, color: '#F97316' },
                    { label: 'Webhooks', data: data.webhooks.series, color: '#F59E0B', dashed: true },
                  ]}
                  ariaLabel="Failures per day"
                />
              )}
            </div>
          </Card>
          <div className="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <TopList title="Top message errors" loading={isLoading} rows={data?.top_messages.map((r) => ({ title: r.error, sub: r.code ? `Code ${r.code}` : 'No error code', count: r.count, at: r.last_at })) ?? []} />
            <TopList title="Top API errors" loading={isLoading} rows={data?.top_api.map((r) => ({ title: r.error || `HTTP ${r.http_status ?? '?'}`, sub: `${r.endpoint}${r.http_status ? ` · HTTP ${r.http_status}` : ''}`, count: r.count, at: r.last_at })) ?? []} />
            <TopList title="Top webhook errors" loading={isLoading} rows={data?.top_webhooks.map((r) => ({ title: r.error || 'Failed', sub: labelize(r.event_type), count: r.count, at: r.last_at })) ?? []} />
          </div>
        </>
      )}
    </div>
  );
}

function TopList({ title, rows, loading }: { title: string; rows: { title: string; sub: string; count: number; at: string }[]; loading: boolean }) {
  return (
    <Card>
      <CardHeader title={title} />
      <div className="card-body">
        {loading ? (
          <Skeleton className="h-40 w-full" />
        ) : rows.length ? (
          <ul className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((r, i) => (
              <li key={i} className="flex items-start justify-between gap-3 py-2.5">
                <div className="min-w-0">
                  <p className="line-clamp-2 text-sm font-medium text-slate-800 dark:text-slate-100" title={r.title}>{r.title}</p>
                  <p className="truncate text-xs text-slate-500 dark:text-slate-400">{r.sub} · last {formatDateTime(r.at)}</p>
                </div>
                <span className="shrink-0 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold tabular-nums text-red-700 dark:bg-red-500/15 dark:text-red-300">{formatNumber(r.count)}</span>
              </li>
            ))}
          </ul>
        ) : (
          <EmptyState icon={AlertTriangle} title="No errors" description="Nothing failed in this window." />
        )}
      </div>
    </Card>
  );
}
