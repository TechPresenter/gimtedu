import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { ScrollText } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, DescriptionList, Drawer, EmptyState, Input, Pagination, SearchInput, Select, Skeleton, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, formatNumber } from '@/lib/format';
import type { ApiLog, ApiLogDetail } from '../lib';
import { JsonView } from '../ui';
import { clean, FilterBar, useDebounced, usePaged } from './common';

/** Interakt API request history (live calls and test-mode simulations). */
export default function ApiLogs() {
  const [f, setF] = useState({ ok: '', mode: '', endpoint: '', from: '', to: '' });
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [open, setOpen] = useState<number | null>(null);
  const endpoint = useDebounced(f.endpoint);
  const { data, isLoading, error, refetch } = usePaged<ApiLog, { summary: { total: number; failed: number; avg_ms: number; max_ms: number } }>(
    'api-logs',
    'whatsapp/logs/api',
    clean({ ...f, endpoint, page, per_page: perPage }),
  );
  const set = (k: keyof typeof f, v: string) => {
    setF((x) => ({ ...x, [k]: v }));
    setPage(1);
  };
  const s = data?.summary;

  const columns: Column<ApiLog>[] = [
    { key: 'created_at', header: 'Time', render: (r) => <span className="whitespace-nowrap">{formatDateTime(r.created_at)}</span> },
    {
      key: 'endpoint',
      header: 'Request',
      render: (r) => (
        <span className="flex min-w-0 items-center gap-2">
          <Badge color={r.method === 'GET' ? 'blue' : 'navy'}>{r.method}</Badge>
          <code className="block max-w-[18rem] truncate text-[12px] text-slate-700 dark:text-slate-200" title={r.endpoint}>{r.endpoint}</code>
        </span>
      ),
    },
    { key: 'ok', header: 'Result', render: (r) => (r.ok ? <Badge color="green" dot>OK</Badge> : <Badge color="red" dot>Failed</Badge>) },
    { key: 'http_status', header: 'HTTP', align: 'right', render: (r) => <span className="tabular-nums">{r.http_status ?? '—'}</span> },
    { key: 'duration_ms', header: 'Duration', align: 'right', render: (r) => <span className="tabular-nums">{r.duration_ms !== null ? `${formatNumber(r.duration_ms)} ms` : '—'}</span> },
    { key: 'mode', header: 'Mode', render: (r) => <Badge color={r.mode === 'live' ? 'green' : 'amber'}>{r.mode === 'live' ? 'Live' : 'Test'}</Badge> },
    {
      key: 'message_id',
      header: 'Message',
      render: (r) =>
        r.message_id ? (
          <Link to={`/whatsapp/api?tab=track&m=${r.message_id}`} onClick={(e) => e.stopPropagation()} className="text-brand-700 hover:underline dark:text-brand-300">
            #{r.message_id}
          </Link>
        ) : (
          <span className="text-slate-400">—</span>
        ),
    },
    { key: 'error', header: 'Error', render: (r) => (r.error ? <span className="block max-w-[16rem] truncate text-xs text-red-600 dark:text-red-400" title={r.error}>{r.error}</span> : <span className="text-slate-400">—</span>) },
  ];

  return (
    <Card>
      {s && (
        <div className="grid grid-cols-2 gap-px border-b border-slate-100 bg-slate-100 sm:grid-cols-4 dark:border-slate-800 dark:bg-slate-800">
          {[
            ['Calls (24 h)', formatNumber(s.total)],
            ['Failed (24 h)', formatNumber(s.failed)],
            ['Avg duration', `${formatNumber(s.avg_ms)} ms`],
            ['Slowest', `${formatNumber(s.max_ms)} ms`],
          ].map(([l, v]) => (
            <div key={l} className="bg-white px-4 py-3 dark:bg-slate-900">
              <p className="text-[11px] font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{l}</p>
              <p className="font-display text-lg font-bold tabular-nums text-slate-900 dark:text-white">{v}</p>
            </div>
          ))}
        </div>
      )}
      <FilterBar>
        <SearchInput size="sm" value={f.endpoint} onChange={(v) => set('endpoint', v)} placeholder="Endpoint contains…" className="sm:w-64" />
        <Select inputSize="sm" aria-label="Result" className="sm:w-36" value={f.ok} onChange={(e) => set('ok', e.target.value)} placeholder="All results" options={{ '1': 'OK', '0': 'Failed' }} />
        <Select inputSize="sm" aria-label="Mode" className="sm:w-36" value={f.mode} onChange={(e) => set('mode', e.target.value)} placeholder="Live + test" options={{ live: 'Live', test: 'Test (simulated)' }} />
        <Input type="date" inputSize="sm" aria-label="From date" className="sm:w-40" value={f.from} onChange={(e) => set('from', e.target.value)} />
        <Input type="date" inputSize="sm" aria-label="To date" className="sm:w-40" value={f.to} onChange={(e) => set('to', e.target.value)} />
      </FilterBar>
      {error ? (
        <div className="p-4">
          <Alert variant="error" title="Could not load API requests" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>{(error as ApiError).message}</Alert>
        </div>
      ) : (
        <DataTable<ApiLog>
          columns={columns}
          rows={data?.rows ?? []}
          loading={isLoading}
          rowKey={(r) => r.id}
          onRowClick={(r) => setOpen(r.id)}
          dense
          empty={<EmptyState icon={ScrollText} title="No API requests match" description="Every call to Interakt (sends, template sync, contact sync) is logged here without credentials." />}
        />
      )}
      {data && data.pagination.total > 0 && (
        <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
          <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={perPage} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} />
        </div>
      )}
      <ApiLogDrawer id={open} onClose={() => setOpen(null)} />
    </Card>
  );
}

function ApiLogDrawer({ id, onClose }: { id: number | null; onClose: () => void }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['wa', 'admin', 'api-log', id],
    queryFn: ({ signal }) => api.get<ApiLogDetail>(`whatsapp/logs/api/${id}`, undefined, { signal }),
    enabled: !!id,
  });
  return (
    <Drawer open={!!id} onClose={onClose} title={`API request #${id ?? ''}`} description={data ? `${data.method} ${data.endpoint}` : undefined} width="max-w-2xl">
      {isLoading ? (
        <Skeleton className="h-80 w-full" />
      ) : error ? (
        <Alert variant="error">{(error as ApiError).message}</Alert>
      ) : data ? (
        <div className="space-y-4">
          <DescriptionList
            items={[
              { label: 'Result', value: data.ok ? <Badge color="green">OK</Badge> : <Badge color="red">Failed</Badge> },
              { label: 'HTTP status', value: data.http_status ?? '—' },
              { label: 'Duration', value: data.duration_ms !== null ? `${formatNumber(data.duration_ms)} ms` : '—' },
              { label: 'Mode', value: data.mode === 'live' ? 'Live (sent to Interakt)' : 'Test (simulated)' },
              { label: 'Time', value: formatDateTime(data.created_at) },
              { label: 'Message', value: data.message_id ? <Link className="text-brand-700 hover:underline dark:text-brand-300" to={`/whatsapp/api?tab=track&m=${data.message_id}`} onClick={onClose}>#{data.message_id} — track</Link> : '—' },
              ...(data.error ? [{ label: 'Error', value: <span className="text-red-600 dark:text-red-400">{data.error}</span>, full: true }] : []),
            ]}
          />
          <div>
            <p className="form-label">Request (credentials are never stored)</p>
            <JsonView value={data.request ?? null} />
          </div>
          <div>
            <p className="form-label">Response</p>
            <JsonView value={data.response ?? null} />
          </div>
        </div>
      ) : null}
    </Drawer>
  );
}
