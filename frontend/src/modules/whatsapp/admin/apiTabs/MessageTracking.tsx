import { useEffect, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowDownLeft, ArrowUpRight, CheckCircle2, Circle, MessagesSquare, Route, Search, XCircle } from 'lucide-react';
import { Alert, Badge, Button, Card, CardHeader, EmptyState, Input, Skeleton, StatusBadge } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatNumber, labelize } from '@/lib/format';
import type { TrackDetail, TrackHit } from '../lib';

/** Message ID tracking: search by Interakt id / GIMT id / phone and follow one message through queue, API and webhooks. */
export default function MessageTracking() {
  const [params, setParams] = useSearchParams();
  const selected = Number(params.get('m')) || null;
  const [input, setInput] = useState(params.get('q') ?? '');
  const q = params.get('q') ?? '';
  useEffect(() => setInput(params.get('q') ?? ''), [params]);

  const search = useQuery({
    queryKey: ['wa', 'admin', 'track', q],
    queryFn: ({ signal }) => api.get<TrackHit[]>('whatsapp/logs/track', { q }, { signal }),
    enabled: q.trim().length > 0,
  });
  const setParam = (patch: Record<string, string | null>) => {
    const next = new URLSearchParams(params);
    next.set('tab', 'track');
    Object.entries(patch).forEach(([k, v]) => (v ? next.set(k, v) : next.delete(k)));
    setParams(next, { replace: true });
  };

  return (
    <div className="grid grid-cols-1 gap-5 xl:grid-cols-12">
      <div className="xl:col-span-5">
        <Card>
          <CardHeader title="Find a message" subtitle="Interakt message id, GIMT message # or phone number" icon={Search} />
          <div className="card-body">
            <form
              className="flex gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                setParam({ q: input.trim() || null });
              }}
            >
              <Input value={input} onChange={(e) => setInput(e.target.value)} placeholder="e.g. 60076f05-…, 1234 or 98765 43210" aria-label="Message id or phone" maxLength={80} />
              <Button type="submit" icon={Search} disabled={!input.trim()}>
                Search
              </Button>
            </form>
            <div className="mt-4">
              {!q ? (
                <p className="text-sm text-slate-500 dark:text-slate-400">Paste the id from a webhook, an Interakt report or a support ticket. Results show the 30 most recent matches.</p>
              ) : search.isLoading ? (
                <Skeleton className="h-40 w-full" />
              ) : search.error ? (
                <Alert variant="error">{(search.error as ApiError).message}</Alert>
              ) : search.data && search.data.length ? (
                <ul className="max-h-[32rem] space-y-1 overflow-y-auto pr-1">
                  {search.data.map((h) => (
                    <li key={h.id}>
                      <button
                        type="button"
                        onClick={() => setParam({ m: String(h.id) })}
                        className={clsx(
                          'flex w-full items-start gap-3 rounded-xl border px-3 py-2 text-left transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                          selected === h.id ? 'border-brand-300 bg-brand-50 dark:border-brand-500/50 dark:bg-brand-500/10' : 'border-transparent hover:bg-slate-50 dark:hover:bg-slate-800/60',
                        )}
                      >
                        {h.direction === 'out' ? <ArrowUpRight className="mt-0.5 h-4 w-4 shrink-0 text-brand-600" aria-label="Outbound" /> : <ArrowDownLeft className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-label="Inbound" />}
                        <span className="min-w-0 flex-1">
                          <span className="flex items-center justify-between gap-2">
                            <span className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">#{h.id} · {h.contact_name || h.phone}</span>
                            <StatusBadge status={h.status} />
                          </span>
                          <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{h.template_name ?? h.preview ?? labelize(h.type)}</span>
                          <span className="block text-[11px] text-slate-400">{formatDateTime(h.created_at)}</span>
                        </span>
                      </button>
                    </li>
                  ))}
                </ul>
              ) : (
                <EmptyState icon={Search} title="No messages found" description="Check the id or search by the contact's phone number." />
              )}
            </div>
          </div>
        </Card>
      </div>
      <div className="xl:col-span-7">{selected ? <TrackPanel id={selected} /> : <Card><div className="card-body"><EmptyState icon={Route} title="Pick a message" description="Its full journey — queue, Interakt API calls and webhooks — appears here." /></div></Card>}</div>
    </div>
  );
}

function TrackPanel({ id }: { id: number }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['wa', 'admin', 'track-detail', id],
    queryFn: ({ signal }) => api.get<TrackDetail>(`whatsapp/logs/track/${id}`, undefined, { signal }),
  });
  if (isLoading) return <Skeleton className="h-96 w-full rounded-2xl" />;
  if (error) return <Alert variant="error" title="Message not found">{(error as ApiError).message}</Alert>;
  if (!data) return null;
  const m = data.message;
  return (
    <Card>
      <CardHeader
        title={`Message #${m.id}`}
        subtitle={`${m.direction === 'out' ? 'To' : 'From'} ${m.contact_name ?? ''} ${m.phone} · ${labelize(m.sender_type)}${m.sender_name ? ` (${m.sender_name})` : ''}`}
        icon={Route}
        actions={
          <Button size="xs" variant="secondary" icon={MessagesSquare} to={`/whatsapp/inbox?c=${m.conversation_id}`}>
            Open chat
          </Button>
        }
      />
      <div className="card-body space-y-5">
        <div className="flex flex-wrap items-center gap-2 text-sm">
          <StatusBadge status={m.status} />
          <Badge color="slate">{labelize(m.type)}</Badge>
          {m.template_name && <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">{m.template_name}</code>}
          {m.cost !== null && <Badge color="amber">Cost ₹{m.cost.toFixed(2)}</Badge>}
          {m.campaign_id && <Link className="text-xs text-brand-700 hover:underline dark:text-brand-300" to={`/whatsapp/campaigns/${m.campaign_id}`}>Campaign #{m.campaign_id}</Link>}
        </div>
        {m.interakt_id && (
          <p className="text-xs text-slate-500 dark:text-slate-400">
            Interakt id: <code className="break-all">{m.interakt_id}</code>
          </p>
        )}
        {m.body && <p className="whitespace-pre-line rounded-xl bg-slate-50 p-3 text-sm text-slate-700 dark:bg-slate-800/60 dark:text-slate-200">{m.body}</p>}

        <ol className="relative space-y-4 border-l-2 border-slate-200 pl-5 dark:border-slate-700">
          {data.steps.map((s) => {
            const failed = s.key === 'failed' || s.key === 'cancelled';
            return (
              <li key={s.key} className="relative">
                <span className={clsx('absolute -left-[1.72rem] top-0.5 rounded-full bg-white dark:bg-slate-900', failed ? 'text-red-500' : s.done ? 'text-emerald-500' : 'text-slate-300 dark:text-slate-600')}>
                  {failed ? <XCircle className="h-5 w-5" /> : s.done ? <CheckCircle2 className="h-5 w-5" /> : <Circle className="h-5 w-5" />}
                </span>
                <p className={clsx('text-sm font-medium', s.done || failed ? 'text-slate-800 dark:text-slate-100' : 'text-slate-400')}>{s.label}</p>
                <p className="text-xs text-slate-500 dark:text-slate-400">{s.at ? formatDateTime(s.at) : s.done ? 'time not recorded' : 'not yet'}</p>
                {s.error && <p className="mt-0.5 text-xs text-red-600 dark:text-red-400">{s.error}</p>}
              </li>
            );
          })}
        </ol>

        {data.outbox && (
          <div>
            <p className="form-label">Send queue</p>
            <div className="grid grid-cols-2 gap-2 text-sm sm:grid-cols-4">
              {[
                ['Status', <StatusBadge key="s" status={data.outbox.status === 'done' ? 'sent' : data.outbox.status} />],
                ['Attempts', `${data.outbox.attempts}/${data.outbox.max_attempts}`],
                ['Priority', data.outbox.priority],
                ['Scheduled', formatDateTime(data.outbox.scheduled_at)],
              ].map(([l, v]) => (
                <div key={l as string} className="rounded-xl bg-slate-50 px-3 py-2 dark:bg-slate-800/60">
                  <p className="text-[11px] text-slate-500 dark:text-slate-400">{l}</p>
                  <div className="font-medium text-slate-800 dark:text-slate-100">{v}</div>
                </div>
              ))}
            </div>
            {data.outbox.last_error && <p className="mt-2 text-xs text-red-600 dark:text-red-400">Last error: {data.outbox.last_error}</p>}
          </div>
        )}

        <div>
          <p className="form-label">Interakt API calls ({data.api_calls.length})</p>
          {data.api_calls.length ? (
            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm dark:divide-slate-800 dark:border-slate-700">
              {data.api_calls.map((a) => (
                <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                  <span className="flex min-w-0 items-center gap-2">
                    {a.ok ? <Badge color="green">OK</Badge> : <Badge color="red">Failed</Badge>}
                    <code className="truncate text-xs">{a.method} {a.endpoint}</code>
                  </span>
                  <span className="text-xs text-slate-500 dark:text-slate-400">
                    {a.http_status ?? '—'} · {a.duration_ms !== null ? `${formatNumber(a.duration_ms)} ms` : '—'} · {a.mode} · {formatDateTime(a.created_at)}
                  </span>
                  {a.error && <span className="w-full text-xs text-red-600 dark:text-red-400">{a.error}</span>}
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-slate-500 dark:text-slate-400">{m.direction === 'in' ? 'Inbound messages arrive by webhook.' : 'Not sent yet.'}</p>
          )}
        </div>

        <div>
          <p className="form-label">Webhooks ({data.webhooks.length})</p>
          {data.webhooks.length ? (
            <ul className="divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm dark:divide-slate-800 dark:border-slate-700">
              {data.webhooks.map((w) => (
                <li key={w.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2">
                  <span className="flex items-center gap-2">
                    <code className="text-xs">{w.event_type}</code>
                    {w.is_simulated && <Badge color="purple">Simulated</Badge>}
                  </span>
                  <span className="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                    <StatusBadge status={w.status} /> {formatDateTime(w.received_at)}
                  </span>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-sm text-slate-500 dark:text-slate-400">No webhooks for this message yet.</p>
          )}
        </div>
      </div>
    </Card>
  );
}
