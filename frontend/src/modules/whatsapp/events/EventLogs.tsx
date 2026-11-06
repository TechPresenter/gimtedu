import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Eye, ListFilter, MessageCircle, RotateCcw, ScrollText } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, EmptyState, IconButton, Input, Modal, Pagination, SearchInput, Select, Skeleton, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { useDebounce } from '@/lib/hooks';
import { formatDateTime, labelize, timeAgo } from '@/lib/format';
import { formatWaPhone, StatusTicks, useWaRefresh, WaText } from '../shared';
import { eventOptionsList, logDisplayStatus, prettyJson, useEventLogs } from './api';
import { useQuery } from '@tanstack/react-query';
import type { WaEvent, WaEventLog, WaEventLogDetail } from './types';

const STATUS_OPTIONS = [
  { value: '', label: 'All outcomes' },
  { value: 'sent', label: 'Message queued' },
  { value: 'skipped', label: 'Skipped' },
  { value: 'failed', label: 'Failed / refused' },
  { value: 'duplicate', label: 'Duplicate / already sent' },
  { value: 'received', label: 'Received (not processed)' },
];

/** Who sent the earlier message a "duplicate_recent" send pointed at (wa_messages.sender_type). */
const SENDER_LABEL: Record<string, string> = { event: 'the Event Manager', automation: 'a workflow', followup: 'a follow-up', campaign: 'a campaign', agent: 'an agent', bot: 'the bot', system: 'the system' };

/** "3 min earlier" — how long before the log row the earlier message was queued (server times, same zone). */
function minutesBefore(earlier: string, at: string): string {
  const diff = Math.round((Date.parse(at.replace(' ', 'T')) - Date.parse(earlier.replace(' ', 'T'))) / 60000);
  if (!Number.isFinite(diff) || diff < 1) return 'moments earlier';
  return diff < 60 ? `${diff} min earlier` : `${Math.round(diff / 60)} h earlier`;
}

/** Where to open an earlier message: its chat in the inbox, else the message tracker (admins), else nowhere. */
function earlierMessageHref(can: ReturnType<typeof useAuth>['can'], id: number, conversationId?: number | null): { to: string; label: string } | null {
  if (conversationId && can('whatsapp', 'view')) return { to: `/whatsapp/inbox?c=${conversationId}`, label: 'Open chat' };
  if (can('whatsapp_settings', 'view')) return { to: `/whatsapp/api?tab=track&m=${id}`, label: 'Track message' };
  return null;
}

interface Props {
  events?: WaEvent[];
  sources: Record<string, string>;
  /** Fixed event code (drawer tab) — hides the event filter */
  code?: string;
  compact?: boolean;
}

/** Execution log of the event engine with filters, payload viewer and retry. */
export function EventLogs({ events, sources, code, compact }: Props) {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useWaRefresh();
  const [q, setQ] = useState('');
  const [filters, setFilters] = useState({ code: code ?? '', status: '', source: '', from: '', to: '' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(compact ? 10 : 25);
  const [viewId, setViewId] = useState<number | null>(null);
  const [retrying, setRetrying] = useState<number | null>(null);
  const [bulk, setBulk] = useState(false);
  const term = useDebounce(q, 300);
  const query = { ...filters, code: code ?? filters.code, q: term, page, per_page: perPage };
  const { data, isLoading, isFetching, error, refetch } = useEventLogs(query);
  const set = (k: keyof typeof filters, v: string) => {
    setFilters((f) => ({ ...f, [k]: v }));
    setPage(1);
  };

  const retry = async (l: Pick<WaEventLog, 'id'>) => {
    setRetrying(l.id);
    try {
      const r = await api.post<{ log_id: number | null; job_id?: number | null }>(`whatsapp/events/logs/${l.id}/retry`);
      toast.success(r.message || 'Event dispatched again.');
      await refresh('events');
      // a bulk-event summary is re-queued as a background job (no new log row to open)
      setViewId(r.data.log_id ?? null);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setRetrying(null);
    }
  };

  const retryAll = async () => {
    setBulk(true);
    try {
      const r = await api.post<{ retried: number }>('whatsapp/events/logs/retry-failed');
      toast.success(r.message);
      await refresh('events');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBulk(false);
    }
  };

  const canRetry = can('whatsapp_automation', 'edit');
  const retryable = (l: WaEventLog) => ['failed', 'skipped'].includes(logDisplayStatus(l).key) && l.source !== 'test';

  const columns: Column<WaEventLog>[] = [
    {
      key: 'created_at',
      header: 'When',
      render: (l) => (
        <div className="whitespace-nowrap">
          <div className="text-slate-800 dark:text-slate-100">{timeAgo(l.created_at)}</div>
          <div className="text-[11px] text-slate-400">#{l.id} · {formatDateTime(l.created_at)}</div>
        </div>
      ),
    },
    ...(!code
      ? [{
          key: 'event',
          header: 'Event',
          render: (l: WaEventLog) => (
            <div className="min-w-[12rem] max-w-[18rem]">
              <div className="truncate font-medium text-slate-900 dark:text-white">{l.event_name ?? labelize(l.event_code.replace(/^(GIMT|WA)_/, '').toLowerCase())}</div>
              <div className="truncate font-mono text-[11px] text-slate-500">{l.event_code}</div>
            </div>
          ),
        } as Column<WaEventLog>]
      : []),
    {
      key: 'contact',
      header: 'Contact',
      render: (l) =>
        l.contact_id ? (
          <div className="min-w-[9rem]">
            <Link to={`/whatsapp/contacts/${l.contact_id}`} className="font-medium text-brand-700 hover:underline dark:text-brand-300" onClick={(e) => e.stopPropagation()}>
              {l.contact_name || 'Contact #' + l.contact_id}
            </Link>
            {l.contact_phone && <div className="text-[11px] text-slate-500">{formatWaPhone(l.contact_phone)}</div>}
          </div>
        ) : (
          <span className="text-slate-400">—</span>
        ),
    },
    {
      key: 'source',
      header: 'Source',
      render: (l) => <Badge color={l.source === 'test' ? 'purple' : l.source === 'cron' ? 'amber' : l.source === 'api' ? 'cyan' : 'slate'}>{l.source === 'test' ? 'Test' : sources[l.source ?? ''] ?? labelize(l.source ?? 'core')}</Badge>,
    },
    {
      key: 'outcome',
      header: 'Outcome',
      render: (l) => {
        const s = logDisplayStatus(l);
        const reason = l.outcome?.reason ?? (l.status === 'failed' ? l.error : null);
        const dupId = s.key === 'duplicate_recent' ? l.outcome?.duplicate_of ?? l.outcome?.message_id ?? null : null;
        const earlier = l.outcome?.earlier_message ?? null;
        const href = dupId ? earlierMessageHref(can, dupId, earlier?.conversation_id) : null;
        return (
          <div className="min-w-[10rem] max-w-[20rem]">
            <Badge color={s.color} dot>{s.label}</Badge>
            {reason && <div className="mt-1 line-clamp-2 text-[11px] text-slate-500 dark:text-slate-400">{reason}</div>}
            {l.outcome?.template && s.key === 'sent' && <div className="mt-1 truncate text-[11px] text-slate-500">Template {l.outcome.template}{l.outcome.recipient ? ' → ' + l.outcome.recipient : ''}</div>}
            {s.key === 'duplicate_recent' && (
              <div className="mt-1 text-[11px] leading-snug text-slate-500 dark:text-slate-400">
                Not sent again: {l.outcome?.template ?? 'the same message'} went out{' '}
                {earlier ? <>{minutesBefore(earlier.created_at, l.created_at)} via {SENDER_LABEL[earlier.sender_type] ?? 'another sender'}</> : 'minutes earlier'}
                {dupId && (
                  <>
                    {' · '}
                    {href ? (
                      <Link to={href.to} className="font-medium text-brand-700 hover:underline dark:text-brand-300" onClick={(e) => e.stopPropagation()} title={href.label}>
                        message #{dupId}
                      </Link>
                    ) : (
                      <>message #{dupId}</>
                    )}
                  </>
                )}
              </div>
            )}
          </div>
        );
      },
    },
  ];

  return (
    <div className="space-y-3">
      <div className={compact ? 'grid grid-cols-1 gap-2 sm:grid-cols-2' : 'grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-[minmax(0,2fr)_minmax(0,1.6fr)_minmax(0,1.2fr)_minmax(0,1.1fr)_150px_150px]'}>
        <SearchInput value={q} onChange={(v) => { setQ(v); setPage(1); }} placeholder="Search contact, phone or dedupe key…" />
        {!code && <Select aria-label="Event" value={filters.code} onChange={(e) => set('code', e.target.value)} options={[{ value: '', label: 'All events' }, ...eventOptionsList(events)]} />}
        <Select aria-label="Outcome" value={filters.status} onChange={(e) => set('status', e.target.value)} options={STATUS_OPTIONS} />
        {!compact && <Select aria-label="Source" value={filters.source} onChange={(e) => set('source', e.target.value)} options={[{ value: '', label: 'All sources' }, { value: 'test', label: 'Test' }, ...Object.entries(sources).map(([value, label]) => ({ value, label }))]} />}
        {!compact && <Input type="date" aria-label="From date" value={filters.from} onChange={(e) => set('from', e.target.value)} />}
        {!compact && <Input type="date" aria-label="To date" value={filters.to} onChange={(e) => set('to', e.target.value)} />}
      </div>
      {!compact && canRetry && (
        <div className="flex flex-wrap items-center justify-between gap-2">
          <p className="text-xs text-slate-500 dark:text-slate-400">
            <ListFilter className="mr-1 inline h-3.5 w-3.5" aria-hidden />
            {data ? `${data.total.toLocaleString('en-IN')} log entr${data.total === 1 ? 'y' : 'ies'}` : 'Loading…'}
            {isFetching && !isLoading ? ' · refreshing…' : ''}
          </p>
          <Button size="sm" variant="secondary" icon={RotateCcw} loading={bulk} onClick={retryAll}>Retry failed (last 24 h)</Button>
        </div>
      )}
      {error ? (
        <Alert variant="error" title="Could not load the execution log" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <Card className="overflow-hidden">
          <DataTable
            columns={columns}
            rows={data?.items ?? []}
            loading={isLoading}
            dense={compact}
            skeletonRows={compact ? 4 : 8}
            onRowClick={(l) => setViewId(l.id)}
            actions={(l) => (
              <div className="flex items-center justify-end gap-1">
                <IconButton icon={Eye} label="View payload and actions" size="sm" onClick={() => setViewId(l.id)} />
                {canRetry && retryable(l) && <IconButton icon={RotateCcw} label="Retry (dispatch again)" size="sm" disabled={retrying === l.id} onClick={() => retry(l)} />}
              </div>
            )}
            empty={
              <EmptyState
                icon={ScrollText}
                title="No executions yet"
                description={code ? 'This event has not fired yet. Use the Test tab to fire it with the sample payload.' : 'Events appear here as soon as the ERP, the website or an external system fires them.'}
              />
            }
          />
          {data && data.total > 0 && (
            <div className="border-t border-slate-100 px-3 py-2 dark:border-slate-800">
              <Pagination page={data.page} pages={data.pages} total={data.total} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} perPageOptions={compact ? [10, 25, 50] : [25, 50, 100]} />
            </div>
          )}
        </Card>
      )}
      <LogDetailModal id={viewId} onClose={() => setViewId(null)} onRetry={canRetry ? retry : undefined} retrying={retrying} sources={sources} />
    </div>
  );
}

/** Payload / actions / messages of one execution. */
export function LogDetailModal({ id, onClose, onRetry, retrying, sources }: { id: number | null; onClose: () => void; onRetry?: (l: { id: number }) => void; retrying?: number | null; sources: Record<string, string> }) {
  const { can } = useAuth();
  const { data: l, isLoading, error } = useQuery({
    queryKey: ['wa', 'events', 'log', id],
    queryFn: ({ signal }) => api.get<WaEventLogDetail>(`whatsapp/events/logs/${id}`, undefined, { signal }),
    enabled: !!id,
  });
  const actions = Array.isArray(l?.actions) ? (l?.actions as Record<string, unknown>[]) : [];
  const mine = actions.find((a) => a && (a.handler === 'event_manager' || 'event_id' in a)) as Record<string, unknown> | undefined;
  // bulk-event summary rows carry the full recipient id list for a later retry: show the count, not thousands of ids
  const rawPayload = l && !Array.isArray(l.payload) ? (l.payload as Record<string, unknown>) : null;
  const bulkCount = rawPayload?.fanout ? Number(rawPayload.recipients_count ?? 0) : null;
  const shownPayload = rawPayload ? Object.fromEntries(Object.entries(rawPayload).filter(([k]) => !k.startsWith('_fanout'))) : l?.payload;
  const status = l ? logDisplayStatus({ status: l.status, outcome: mine ? { status: (mine.status as never) ?? null, reason: (mine.reason as string) ?? null, message_id: null, template: null, recipient: null, scheduled_at: (mine.scheduled_at as string) ?? null, stage: null, send_status: (mine.send_status as string) ?? null } : null }) : null;
  // "Already sent recently": the repeat guard kept an earlier identical message instead of queueing this one
  const dup = status?.key === 'duplicate_recent' ? l?.duplicate_message ?? null : null;
  const dupId = status?.key === 'duplicate_recent' ? Number(mine?.duplicate_of ?? mine?.message_id ?? 0) || null : null;
  const dupHref = dupId ? earlierMessageHref(can, dupId, dup?.conversation_id) : null;
  return (
    <Modal
      open={!!id}
      onClose={onClose}
      size="xl"
      title={l ? l.event_name || l.event_code : 'Execution'}
      description={l ? <span className="font-mono text-xs">{l.event_code} · log #{l.id}</span> : undefined}
      footer={
        <>
          {onRetry && l && status && ['failed', 'skipped'].includes(status.key) && l.source !== 'test' && (
            <Button variant="secondary" icon={RotateCcw} loading={retrying === l.id} onClick={() => onRetry(l)}>Retry</Button>
          )}
          <Button onClick={onClose}>Close</Button>
        </>
      }
    >
      {isLoading ? (
        <div className="space-y-3">
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-40 w-full" />
        </div>
      ) : error ? (
        <Alert variant="error" title="Could not load this execution">{(error as ApiError).message}</Alert>
      ) : l ? (
        <div className="space-y-5">
          <DescriptionList
            columns={2}
            items={[
              { label: 'Outcome', value: status ? <Badge color={status.color} dot>{status.label}</Badge> : '—' },
              { label: 'Source', value: l.source === 'test' ? 'Test' : sources[l.source ?? ''] ?? labelize(l.source ?? 'core') },
              { label: 'Received', value: formatDateTime(l.created_at) },
              { label: 'Processed', value: l.processed_at ? formatDateTime(l.processed_at) : '—' },
              { label: 'Contact', value: l.contact_id ? <Link className="text-brand-700 hover:underline dark:text-brand-300" to={`/whatsapp/contacts/${l.contact_id}`}>{l.contact_name || '#' + l.contact_id}{l.contact_phone ? ' · ' + formatWaPhone(l.contact_phone) : ''}</Link> : '—' },
              { label: 'Dedupe key', value: <span className="break-all font-mono text-xs">{l.dedupe_key || '—'}</span> },
              ...(bulkCount !== null ? [{ label: 'Bulk event', value: `${bulkCount.toLocaleString('en-IN')} recipient(s) logged once`, full: true }] : []),
              ...(mine?.reason ? [{ label: 'Reason', value: String(mine.reason), full: true }] : []),
              ...(status?.key === 'duplicate_recent' && mine?.note ? [{ label: 'Why no new message', value: String(mine.note), full: true }] : []),
              ...(l.error ? [{ label: 'Error', value: <span className="text-red-600 dark:text-red-400">{l.error}</span>, full: true }] : []),
            ]}
          />
          {status?.key === 'duplicate_recent' && (
            <section>
              <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                <h4 className="text-xs font-semibold uppercase tracking-wide text-slate-500">Already sent recently</h4>
                {dupHref && <Button size="xs" variant="secondary" icon={MessageCircle} to={dupHref.to}>{dupHref.label}</Button>}
              </div>
              {dup ? (
                <div className="rounded-xl border border-cyan-200 bg-cyan-50/60 p-3 dark:border-cyan-500/30 dark:bg-cyan-500/10">
                  <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                    <span className="font-medium text-slate-700 dark:text-slate-200">
                      #{dup.id} · {dup.template_name ?? dup.type} · via {SENDER_LABEL[dup.sender_type] ?? 'another sender'} · {formatDateTime(dup.created_at)}
                    </span>
                    <span className="inline-flex items-center gap-1 text-slate-500 dark:text-slate-400"><StatusTicks status={dup.status} /> {labelize(dup.status)}</span>
                  </div>
                  <div className="rounded-lg bg-white px-3 py-2 text-sm shadow-sm dark:bg-slate-900">
                    <WaText text={dup.body} />
                  </div>
                  {!mine?.note && <p className="mt-1.5 text-xs text-slate-600 dark:text-slate-300">This event did not queue a second copy: the contact already has this message.</p>}
                </div>
              ) : (
                <p className="rounded-xl border border-dashed border-slate-200 px-3 py-3 text-xs text-slate-500 dark:border-slate-700 dark:text-slate-400">
                  The same message{dupId ? ` (#${dupId})` : ''} reached this contact minutes earlier, so no new message was queued.{dupId ? ' It is no longer available.' : ''}
                </p>
              )}
            </section>
          )}
          {l.messages.length > 0 && (
            <section>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Messages queued</h4>
              <ul className="space-y-2">
                {l.messages.map((m) => (
                  <li key={m.id} className="rounded-xl border border-slate-200 bg-[#f0f2f5] p-3 dark:border-slate-700 dark:bg-slate-800/60">
                    <div className="mb-1.5 flex flex-wrap items-center justify-between gap-2 text-xs">
                      <span className="font-medium text-slate-700 dark:text-slate-200">#{m.id} · {m.template_name ?? m.type}</span>
                      <span className="inline-flex items-center gap-1 text-slate-500"><StatusTicks status={m.status} /> {labelize(m.status)}</span>
                    </div>
                    <div className="rounded-lg bg-white px-3 py-2 text-sm shadow-sm dark:bg-slate-900">
                      <WaText text={m.body} />
                    </div>
                    {m.error && <p className="mt-1.5 text-xs text-red-600 dark:text-red-400">{m.error}</p>}
                  </li>
                ))}
              </ul>
            </section>
          )}
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Payload</h4>
              <pre className="max-h-80 overflow-auto rounded-xl bg-slate-950 p-3 text-[12px] leading-relaxed text-slate-100 dark:ring-1 dark:ring-slate-700">{prettyJson(shownPayload)}</pre>
            </section>
            <section>
              <h4 className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Actions</h4>
              <pre className="max-h-80 overflow-auto rounded-xl bg-slate-950 p-3 text-[12px] leading-relaxed text-slate-100 dark:ring-1 dark:ring-slate-700">{actions.length ? prettyJson(actions) : '// no listener acted on this event'}</pre>
            </section>
          </div>
        </div>
      ) : null}
    </Modal>
  );
}
