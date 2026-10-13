import { useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { History, Monitor, Smartphone } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, EmptyState, Input, Pagination, SearchInput, Select, StatusBadge, type Column } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { formatDateTime, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import type { BadgeColor } from '@/lib/status';
import type { Listing, LoginActivityRow } from './types';

const STATUS_COLORS: Record<string, BadgeColor> = { success: 'green', failed: 'red', locked: 'red', blocked: 'red', logout: 'slate', activated: 'purple', reset: 'blue' };
const STATUS_OPTIONS = [
  { value: 'success', label: 'Signed in' },
  { value: 'failed', label: 'Failed' },
  { value: 'locked', label: 'Locked out' },
  { value: 'blocked', label: 'Blocked' },
  { value: 'logout', label: 'Signed out' },
  { value: 'activated', label: 'Activated' },
  { value: 'reset', label: 'Password reset' },
];
const STATUS_LABEL: Record<string, string> = Object.fromEntries(STATUS_OPTIONS.map((o) => [o.value, o.label]));

export function ActivityTab() {
  const [type, setType] = useState('all');
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const search = useDebounce(q, 300);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const params = useMemo(() => ({ type, status: status || undefined, q: search || undefined, from: from || undefined, to: to || undefined, page, per_page: perPage }), [type, status, search, from, to, page, perPage]);
  useEffect(() => setPage(1), [type, status, search, from, to]);
  const { data, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['portal-access', 'logins', params],
    queryFn: ({ signal }) => api.get<Listing<LoginActivityRow>>('portal-access/logins', params, { signal }),
    placeholderData: keepPreviousData,
  });

  const columns: Column<LoginActivityRow>[] = [
    { key: 'created_at', header: 'When', render: (r) => <span className="whitespace-nowrap" title={formatDateTime(r.created_at)}>{formatDateTime(r.created_at)}<span className="block text-[11px] text-slate-400">{timeAgo(r.created_at)}</span></span> },
    {
      key: 'person',
      header: 'Account',
      render: (r) => (
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span className="font-semibold text-slate-900 dark:text-white">{r.name || <span className="italic text-slate-400">Unknown</span>}</span>
            <Badge color={r.type === 'student' ? 'navy' : 'cyan'}>{r.type === 'student' ? 'Student' : 'Faculty'}</Badge>
          </div>
          <p className="truncate font-mono text-[11px] text-slate-500">{r.ref ?? r.identifier}</p>
        </div>
      ),
    },
    { key: 'status', header: 'Event', render: (r) => <StatusBadge status={r.status} label={STATUS_LABEL[r.status]} colors={STATUS_COLORS} /> },
    { key: 'reason', header: 'Details', render: (r) => <span className="text-slate-600 dark:text-slate-300">{r.reason ?? '—'}{r.identifier && r.ref && r.identifier.toUpperCase() !== r.ref.toUpperCase() && <span className="block text-[11px] text-slate-400">as {r.identifier}</span>}</span> },
    {
      key: 'device',
      header: 'Device / IP',
      render: (r) => (
        <span className="inline-flex items-start gap-1.5 whitespace-nowrap text-slate-600 dark:text-slate-300">
          {/android|iphone|ios|ipad/i.test(r.device) ? <Smartphone className="mt-0.5 h-3.5 w-3.5 text-slate-400" /> : <Monitor className="mt-0.5 h-3.5 w-3.5 text-slate-400" />}
          <span>
            {r.device}
            <span className="block font-mono text-[11px] text-slate-400">{r.ip_address}</span>
          </span>
        </span>
      ),
    },
  ];

  return (
    <Card>
      <div className="grid gap-2 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.4fr)_repeat(4,minmax(0,1fr))] dark:border-slate-800">
        <SearchInput value={q} onChange={setQ} placeholder="Name, Student / Employee ID, IP…" size="sm" />
        <Select inputSize="sm" aria-label="Account type" value={type} onChange={(e) => setType(e.target.value)} options={[{ value: 'all', label: 'Students & faculty' }, { value: 'student', label: 'Students' }, { value: 'faculty', label: 'Faculty' }]} />
        <Select inputSize="sm" aria-label="Event" value={status} onChange={(e) => setStatus(e.target.value)} options={STATUS_OPTIONS} placeholder="All events" />
        <Input inputSize="sm" type="date" aria-label="From date" value={from} max={to || undefined} onChange={(e) => setFrom(e.target.value)} />
        <Input inputSize="sm" type="date" aria-label="To date" value={to} min={from || undefined} onChange={(e) => setTo(e.target.value)} />
      </div>
      {error ? (
        <div className="p-4">
          <Alert variant="error" action={<Button size="xs" variant="secondary" onClick={() => void refetch()}>Retry</Button>}>
            {error instanceof ApiError ? error.message : 'Unable to load the login activity.'}
          </Alert>
        </div>
      ) : (
        <DataTable
          columns={columns}
          rows={data?.rows ?? []}
          rowKey={(r) => r.key}
          loading={isLoading || isFetching}
          dense
          empty={<EmptyState icon={History} title="No sign-in activity found" description="Adjust the filters or date range." action={<Button size="sm" variant="secondary" onClick={() => { setQ(''); setType('all'); setStatus(''); setFrom(''); setTo(''); }}>Clear filters</Button>} />}
        />
      )}
      {data && data.pagination.total > 0 && (
        <Pagination page={data.pagination.page} pages={data.pagination.pages} total={data.pagination.total} perPage={data.pagination.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} className="border-t border-slate-100 dark:border-slate-800" />
      )}
    </Card>
  );
}
