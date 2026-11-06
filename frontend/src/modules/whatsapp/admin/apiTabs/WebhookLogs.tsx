import { useState } from 'react';
import clsx from 'clsx';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { Lock, RotateCw, ShieldAlert, ShieldCheck, ShieldQuestion, Webhook } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, Drawer, EmptyState, Input, Pagination, SearchInput, Select, Skeleton, StatusBadge, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatDateTime, formatNumber, formatTime, humanFileSize } from '@/lib/format';
import { useWaRefresh } from '../../shared';
import type { WebhookLog, WebhookLogDetail } from '../lib';
import { JsonView } from '../ui';
import { clean, FilterBar, useDebounced, usePaged } from './common';

const STATUS_COLORS = { received: 'amber', processed: 'green', ignored: 'slate', failed: 'red' } as const;

function Signature({ v }: { v: boolean | null }) {
  if (v === null) return <span className="inline-flex items-center gap-1 text-xs text-slate-500"><ShieldQuestion className="h-3.5 w-3.5" /> No secret</span>;
  return v ? (
    <span className="inline-flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"><ShieldCheck className="h-3.5 w-3.5" /> Valid</span>
  ) : (
    <span className="inline-flex items-center gap-1 text-xs text-red-600 dark:text-red-400"><ShieldAlert className="h-3.5 w-3.5" /> Invalid</span>
  );
}

/** Re-run a stored webhook (server re-checks that it is signed or simulated). */
function useReprocess() {
  const toast = useToast();
  const refresh = useWaRefresh();
  const [busy, setBusy] = useState<number | null>(null);
  const run = async (id: number, after?: () => void) => {
    setBusy(id);
    try {
      const r = await api.post(`whatsapp/logs/webhooks/${id}/reprocess`);
      toast.success(r.message);
      await refresh('admin');
      after?.();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  return { busy, run };
}

/** Why a row cannot be re-run, as muted text (clamped in the table) with the full reason on hover. */
function NotReplayable({ reason, className, clamp }: { reason: string | null; className?: string; clamp?: boolean }) {
  const text = reason ?? 'This webhook cannot be reprocessed.';
  return (
    <span className={clsx('inline-flex items-start gap-1 text-left text-xs leading-snug text-slate-500 dark:text-slate-400', className)} title={text}>
      <Lock className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden />
      <span className="sr-only">Cannot reprocess: </span>
      <span className={clsx(clamp && 'line-clamp-2')}>{text}</span>
    </span>
  );
}

/** Webhook event logs: filters, payload viewer, reprocess (signed or simulated rows only). */
export default function WebhookLogs() {
  const { can } = useAuth();
  const canEdit = can('whatsapp_settings', 'edit');
  const [f, setF] = useState({ type: '', status: '', simulated: '', from: '', to: '', q: '' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [open, setOpen] = useState<number | null>(null);
  const q = useDebounced(f.q);
  const query = clean({ ...f, q, page, per_page: perPage });
  const { data, isLoading, error, refetch } = usePaged<WebhookLog, { types: string[]; counts: Record<string, number> }>('webhooks', 'whatsapp/logs/webhooks', query);
  const reprocess = useReprocess();
  const set = (k: keyof typeof f, v: string) => {
    setF((x) => ({ ...x, [k]: v }));
    setPage(1);
  };

  const columns: Column<WebhookLog>[] = [
    { key: 'id', header: '#', render: (r) => <span className="tabular-nums text-slate-500">{r.id}</span>, width: '4.5rem' },
    {
      key: 'received_at',
      header: 'Received',
      render: (r) => (
        <span className="block whitespace-nowrap" title={formatDateTime(r.received_at)}>
          {formatDate(r.received_at)}
          <span className="block text-xs text-slate-500 dark:text-slate-400">{formatTime(String(r.received_at ?? '').slice(11, 19))}</span>
        </span>
      ),
    },
    {
      key: 'event_type',
      header: 'Event',
      render: (r) => (
        <span className="flex flex-wrap items-center gap-1.5">
          <code className="rounded bg-slate-100 px-1.5 py-0.5 text-[12px] dark:bg-slate-800">{r.event_type ?? 'unknown'}</code>
          {r.is_simulated && <Badge color="purple">Simulated</Badge>}
        </span>
      ),
    },
    { key: 'status', header: 'Status', render: (r) => <StatusBadge status={r.status} colors={STATUS_COLORS} /> },
    { key: 'signature', header: 'Signature', render: (r) => <Signature v={r.signature_valid} /> },
    {
      key: 'interakt_message_id',
      header: 'Message ID · note',
      render: (r) => (
        <span className="block max-w-[13rem]">
          {r.interakt_message_id ? <code className="block truncate text-[12px] text-slate-600 dark:text-slate-300" title={r.interakt_message_id}>{r.interakt_message_id}</code> : <span className="text-slate-400">—</span>}
          {r.error && <span className="block truncate text-xs text-slate-500 dark:text-slate-400" title={r.error}>{r.error}</span>}
        </span>
      ),
    },
    ...(canEdit
      ? [
          {
            key: 'reprocess',
            header: <span className="sr-only">Reprocess</span>,
            align: 'right',
            render: (r: WebhookLog) =>
              r.can_reprocess ? (
                <Button
                  size="xs"
                  variant="ghost"
                  icon={RotateCw}
                  title={r.reprocess_reason ?? undefined}
                  loading={reprocess.busy === r.id}
                  disabled={reprocess.busy !== null && reprocess.busy !== r.id}
                  onClick={(e) => {
                    e.stopPropagation();
                    void reprocess.run(r.id, () => void refetch());
                  }}
                >
                  Reprocess
                </Button>
              ) : r.can_reprocess === false ? (
                <NotReplayable reason={r.reprocess_reason} className="w-[11rem]" clamp />
              ) : (
                <span className="text-xs text-slate-400" title={r.reprocess_reason ?? undefined}>
                  —
                </span>
              ),
          } satisfies Column<WebhookLog>,
        ]
      : []),
  ];

  return (
    <Card>
      <FilterBar>
        <SearchInput size="sm" value={f.q} onChange={(v) => set('q', v)} placeholder="Message id or error…" className="sm:min-w-[12rem] sm:flex-1" />
        <Select inputSize="sm" aria-label="Event type" className="sm:w-48" value={f.type} onChange={(e) => set('type', e.target.value)} placeholder="All event types" options={(data?.types ?? []).map((t) => ({ value: t, label: t }))} />
        <Select inputSize="sm" aria-label="Status" className="sm:w-36" value={f.status} onChange={(e) => set('status', e.target.value)} placeholder="All statuses" options={{ received: 'Received', processed: 'Processed', ignored: 'Ignored', failed: 'Failed' }} />
        <Select inputSize="sm" aria-label="Source" className="sm:w-44" value={f.simulated} onChange={(e) => set('simulated', e.target.value)} placeholder="Real + simulated" options={{ '0': 'From Interakt', '1': 'Simulated' }} />
        <Input type="date" inputSize="sm" aria-label="From date" className="sm:w-[9.5rem]" value={f.from} onChange={(e) => set('from', e.target.value)} />
        <Input type="date" inputSize="sm" aria-label="To date" className="sm:w-[9.5rem]" value={f.to} onChange={(e) => set('to', e.target.value)} />
      </FilterBar>
      {data && (
        <div className="flex flex-wrap gap-x-4 gap-y-1 px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">
          <span className="font-medium text-slate-600 dark:text-slate-300">Last 24 h:</span>
          {(['processed', 'received', 'ignored', 'failed'] as const).map((s) => (
            <span key={s}>
              {s} <span className="font-semibold tabular-nums text-slate-700 dark:text-slate-200">{formatNumber(data.counts[s] ?? 0)}</span>
            </span>
          ))}
        </div>
      )}
      {error ? (
        <div className="p-4">
          <Alert variant="error" title="Could not load webhook logs" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
        </div>
      ) : (
        <DataTable<WebhookLog>
          columns={columns}
          rows={data?.rows ?? []}
          loading={isLoading}
          rowKey={(r) => r.id}
          onRowClick={(r) => setOpen(r.id)}
          dense
          empty={<EmptyState icon={Webhook} title="No webhooks match" description="Interakt posts delivery updates and incoming messages here once the webhook is set up in Integrations." action={<Button size="sm" to="/whatsapp/integrations">Set up webhook</Button>} />}
        />
      )}
      {data && data.pagination.total > 0 && (
        <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />
        </div>
      )}
      <WebhookDrawer id={open} onClose={() => setOpen(null)} />
    </Card>
  );
}

function WebhookDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { can } = useAuth();
  const reprocess = useReprocess();
  const { data, isLoading, error, refetch } = useQuery({
    queryKey: ['wa', 'admin', 'webhook', id],
    queryFn: ({ signal }) => api.get<WebhookLogDetail>(`whatsapp/logs/webhooks/${id}`, undefined, { signal }),
    enabled: !!id,
  });
  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      title={`Webhook #${id ?? ''}`}
      description={data ? `${data.event_type ?? 'unknown'} · ${formatDateTime(data.received_at)}` : undefined}
      width="max-w-2xl"
      footer={
        data && can('whatsapp_settings', 'edit') ? (
          data.can_reprocess ? (
            <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              {data.reprocess_reason && <span className="text-xs text-slate-500 dark:text-slate-400">{data.reprocess_reason}</span>}
              <Button icon={RotateCw} loading={reprocess.busy === data.id} onClick={() => void reprocess.run(data.id, () => void refetch())} className="sm:ml-auto">
                Reprocess
              </Button>
            </div>
          ) : (
            <NotReplayable reason={data.reprocess_reason} className="max-w-full" />
          )
        ) : undefined
      }
    >
      {isLoading ? (
        <Skeleton className="h-80 w-full" />
      ) : error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : data ? (
        <div className="space-y-4">
          <DescriptionList
            items={[
              { label: 'Status', value: <StatusBadge status={data.status} colors={STATUS_COLORS} /> },
              { label: 'Signature', value: <Signature v={data.signature_valid} /> },
              { label: 'Processed', value: data.processed_at ? formatDateTime(data.processed_at) : '—' },
              { label: 'Source', value: data.is_simulated ? 'Simulated (GIMT test tool)' : 'Interakt' },
              { label: 'Interakt message id', value: data.interakt_message_id ? <code className="break-all text-xs">{data.interakt_message_id}</code> : '—', full: true },
              ...(data.error ? [{ label: 'Note / error', value: data.error, full: true }] : []),
              ...(data.message
                ? [{
                    label: 'GIMT message',
                    value: (
                      <span className="flex flex-wrap items-center gap-2">
                        #{data.message.id} · <StatusBadge status={data.message.status} />
                        <Link className="text-brand-700 hover:underline dark:text-brand-300" to={`/whatsapp/api?tab=track&m=${data.message.id}`} onClick={onClose}>Track</Link>
                        <Link className="text-brand-700 hover:underline dark:text-brand-300" to={`/whatsapp/inbox?c=${data.message.conversation_id}`}>Open chat</Link>
                      </span>
                    ),
                    full: true,
                  }]
                : []),
            ]}
          />
          <div>
            <p className="form-label">Headers</p>
            <JsonView value={data.headers} />
          </div>
          <div>
            <p className="form-label">Payload {typeof data.payload === 'string' ? `(${humanFileSize(data.payload.length)})` : ''}</p>
            <JsonView value={data.payload} />
          </div>
          {!data.can_reprocess && (
            <Alert variant="warning" title="Cannot be reprocessed">
              {data.reprocess_reason ?? 'This webhook cannot be reprocessed.'} Only webhooks whose origin GIMT can verify (an Interakt signature that matches the webhook secret, or GIMT&apos;s own simulator) are re-run, so forged STOP / START keywords, messages or template changes are never replayed.
            </Alert>
          )}
        </div>
      ) : null}
    </Drawer>
  );
}
