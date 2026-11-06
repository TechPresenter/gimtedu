import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { Ban, ListRestart, RefreshCw, RotateCw, Route } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, EmptyState, IconButton, Pagination, SearchInput, Select, StatusBadge, useConfirm, useToast, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, formatNumber, labelize, timeAgo } from '@/lib/format';
import { useWaRefresh, waRunWorker } from '../../shared';
import type { QueueRow } from '../lib';
import { clean, FilterBar, useDebounced, usePaged } from './common';

const STATUSES = [
  { key: 'failed', label: 'Failed' },
  { key: 'pending', label: 'Pending' },
  { key: 'stuck', label: 'Stuck' },
  { key: 'processing', label: 'Processing' },
  { key: 'cancelled', label: 'Cancelled' },
  { key: 'done', label: 'Sent' },
];

/** Retry queue: failed / pending / stuck outbox rows with retry, cancel and bulk actions. */
export default function RetryQueue() {
  const { can } = useAuth();
  const toast = useToast();
  const confirm = useConfirm();
  const refresh = useWaRefresh();
  const [status, setStatus] = useState('failed');
  const [sender, setSender] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [selected, setSelected] = useState<Set<string | number>>(new Set());
  const [busy, setBusy] = useState<string | null>(null);
  const q = useDebounced(search);
  const { data, isLoading, error, refetch } = usePaged<QueueRow, { counts: Record<string, number> }>('queue', 'whatsapp/logs/queue', clean({ status, sender_type: sender, q, page, per_page: perPage }));
  const canEdit = can('whatsapp_settings', 'edit');
  const canRun = can('whatsapp_settings', 'manage') || can('whatsapp', 'manage');

  const act = async (kind: 'retry' | 'cancel', ids: number[]) => {
    if (!ids.length) return;
    if (kind === 'cancel') {
      const ok = await confirm({ title: `Cancel ${ids.length} message(s)?`, message: 'Cancelled messages are never sent. This cannot be undone.', confirmText: 'Cancel messages', danger: true });
      if (!ok) return;
    } else if (ids.length > 1) {
      const ok = await confirm({
        title: `Send ${ids.length} failed messages again?`,
        message: 'They go back into the send queue. Messages whose 24-hour window closed, whose template is no longer approved or whose contact opted out are skipped.',
        confirmText: 'Retry messages',
      });
      if (!ok) return;
    }
    setBusy(kind);
    try {
      const r = await api.post<{ requeued?: number; refused?: number }>(`whatsapp/logs/queue/${kind}`, { ids });
      if (kind === 'retry' && (r.data?.refused ?? 0) > 0) toast.warning(r.message);
      else toast.success(r.message);
      setSelected(new Set());
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };
  const runWorker = async () => {
    setBusy('run');
    try {
      const r = await waRunWorker();
      toast.success(r.message || 'Worker run complete.');
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const retryable = (r: QueueRow) => r.retryable;
  const selectable = canEdit && ['failed', 'pending', 'stuck'].includes(status);

  const columns: Column<QueueRow>[] = [
    {
      key: 'contact',
      header: 'Recipient',
      render: (r) => (
        <span className="block min-w-0">
          <span className="block truncate font-medium text-slate-800 dark:text-slate-100">{r.contact_name || r.phone}</span>
          <span className="block text-xs text-slate-500 dark:text-slate-400">{r.contact_name ? r.phone : `Contact #${r.contact_id}`}</span>
        </span>
      ),
    },
    {
      key: 'message',
      header: 'Message',
      render: (r) => (
        <span className="block min-w-0">
          <span className="block truncate text-sm">{r.template_name ?? labelize(r.type)}</span>
          <span className="text-xs text-slate-500 dark:text-slate-400">
            #{r.message_id} · {labelize(r.sender_type)}
            {r.campaign_id && r.sender_type !== 'campaign' ? ' · campaign' : ''}
          </span>
        </span>
      ),
    },
    {
      key: 'status',
      header: 'Queue',
      render: (r) => (
        <span className="block">
          <StatusBadge status={r.status === 'done' ? 'sent' : r.status} />
          {r.status === 'failed' && r.outbox_status === 'done' && <span className="mt-0.5 block text-[11px] text-slate-500 dark:text-slate-400">reported by Interakt</span>}
        </span>
      ),
    },
    { key: 'attempts', header: 'Attempts', align: 'right', render: (r) => (r.outbox_id ? <span className="tabular-nums">{r.attempts}/{r.max_attempts}</span> : <span className="text-slate-400">—</span>) },
    {
      key: 'scheduled_at',
      header: r0(status),
      render: (r) => (
        <span className="whitespace-nowrap text-sm" title={formatDateTime(r.status === 'pending' ? r.scheduled_at : r.updated_at ?? r.created_at)}>
          {status === 'pending' ? formatDateTime(r.scheduled_at) : timeAgo(r.updated_at ?? r.created_at)}
        </span>
      ),
    },
    {
      key: 'last_error',
      header: 'Last error',
      render: (r) =>
        r.last_error ? (
          <span className="block max-w-[18rem] truncate text-xs text-red-600 dark:text-red-400" title={r.last_error}>
            {r.error_code ? `[${r.error_code}] ` : ''}
            {r.last_error}
          </span>
        ) : (
          <span className="text-slate-400">—</span>
        ),
    },
  ];

  const counts = data?.counts ?? {};
  return (
    <Card>
      <div className="flex flex-col gap-3 border-b border-slate-100 p-3 lg:flex-row lg:items-center lg:justify-between dark:border-slate-800">
        <div className="-mx-1 flex gap-1 overflow-x-auto px-1 scrollbar-none">
          {STATUSES.map((s) => (
            <button
              key={s.key}
              type="button"
              onClick={() => {
                setStatus(s.key);
                setPage(1);
                setSelected(new Set());
              }}
              className={clsx(
                'inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                status === s.key ? 'bg-brand-900 text-white dark:bg-brand-700' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300 dark:hover:bg-slate-700',
              )}
              aria-pressed={status === s.key}
            >
              {s.label}
              <span className={clsx('rounded-full px-1.5 text-[11px] tabular-nums', status === s.key ? 'bg-white/20' : 'bg-white dark:bg-slate-900', s.key === 'failed' && (counts.failed ?? 0) > 0 && status !== s.key && 'text-red-600')}>
                {formatNumber(counts[s.key] ?? 0)}
              </span>
            </button>
          ))}
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {status === 'pending' && (counts.due ?? 0) > 0 && <span className="text-xs text-slate-500 dark:text-slate-400">{formatNumber(counts.due)} due now</span>}
          {canRun && (
            <Button size="sm" variant="secondary" icon={RefreshCw} loading={busy === 'run'} onClick={runWorker}>
              Run worker
            </Button>
          )}
          {canEdit && status === 'failed' && (counts.failed ?? 0) > 0 && (
            <Button size="sm" icon={RotateCw} loading={busy === 'retry' && selected.size === 0} onClick={() => act('retry', (data?.rows ?? []).filter(retryable).map((r) => r.id))}>
              Retry this page
            </Button>
          )}
        </div>
      </div>
      <FilterBar>
        <SearchInput size="sm" value={search} onChange={(v) => { setSearch(v); setPage(1); }} placeholder="Phone, name or template…" className="sm:w-64" />
        <Select inputSize="sm" aria-label="Sender" className="sm:w-44" value={sender} onChange={(e) => { setSender(e.target.value); setPage(1); }} placeholder="All senders" options={{ agent: 'Agent', bot: 'Bot', automation: 'Automation', event: 'Event', campaign: 'Campaign', followup: 'Follow-up', system: 'System' }} />
        {selected.size > 0 && (
          <div className="flex flex-wrap items-center gap-2 sm:ml-auto">
            <Badge color="navy">{selected.size} selected</Badge>
            {status !== 'pending' && (
              <Button size="sm" icon={RotateCw} loading={busy === 'retry'} onClick={() => act('retry', [...selected].map(Number))}>
                Retry selected
              </Button>
            )}
            {status === 'pending' && (
              <Button size="sm" variant="danger" icon={Ban} loading={busy === 'cancel'} onClick={() => act('cancel', [...selected].map(Number))}>
                Cancel selected
              </Button>
            )}
          </div>
        )}
      </FilterBar>
      {error ? (
        <div className="p-4">
          <Alert variant="error" title="Could not load the queue" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
        </div>
      ) : (
        <DataTable<QueueRow>
          columns={columns}
          rows={data?.rows ?? []}
          loading={isLoading}
          rowKey={(r) => r.id}
          selectable={selectable}
          selected={selected}
          onSelectedChange={setSelected}
          dense
          actions={(r) => (
            <div className="flex items-center justify-end gap-1">
              <Link to={`/whatsapp/api?tab=track&m=${r.message_id}`} className="rounded-lg p-1.5 text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 dark:hover:bg-slate-800" aria-label={`Track message ${r.message_id}`}>
                <Route className="h-4 w-4" />
              </Link>
              {canEdit && retryable(r) && <IconButton icon={RotateCw} label="Retry" size="sm" onClick={() => act('retry', [r.id])} />}
              {canEdit && r.status === 'pending' && <IconButton icon={Ban} label="Cancel" size="sm" tone="danger" onClick={() => act('cancel', [r.id])} />}
            </div>
          )}
          empty={
            <EmptyState
              icon={ListRestart}
              title={status === 'failed' ? 'No failed messages' : status === 'stuck' ? 'Nothing is stuck' : `No ${STATUSES.find((s) => s.key === status)?.label.toLowerCase() ?? status} messages`}
              description={status === 'failed' ? 'Messages that fail after all retries, or that Interakt reports as failed, land here so you can send them again.' : 'The queue is processed by the background worker every minute.'}
            />
          }
        />
      )}
      {data && data.pagination.total > 0 && (
        <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />
        </div>
      )}
    </Card>
  );
}

function r0(status: string) {
  return status === 'pending' ? 'Scheduled' : 'Updated';
}
