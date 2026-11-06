import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { FlaskConical, History, RefreshCw } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, EmptyState, Pagination, SearchInput, Select, StatusBadge, Tabs, type Column } from '@/components/ui';
import type { ApiError } from '@/lib/api';
import { formatDateTime, timeAgo } from '@/lib/format';
import { useDebounce } from '@/lib/hooks';
import { useRunList } from './api';
import { NODE_SPECS } from './nodeTypes';
import { RUN_STATUS_COLORS, RunDrawer, waitingText } from './RunDrawer';
import type { RunRow, WorkflowRow } from './types';

const STATUS_TABS = [
  { key: '', label: 'All' },
  { key: 'active', label: 'In progress' },
  { key: 'completed', label: 'Completed' },
  { key: 'failed', label: 'Failed' },
  { key: 'cancelled', label: 'Cancelled' },
];

/** Execution history: every run with filters, the current step, and a drawer with the step timeline + retry / cancel. */
export function RunsPanel({ workflows }: { workflows: WorkflowRow[] }) {
  const [params, setParams] = useSearchParams();
  const workflow = params.get('workflow') ?? '';
  const status = params.get('status') ?? '';
  const test = params.get('test') === '1' ? '1' : '0';
  const [q, setQ] = useState(params.get('q') ?? '');
  const dq = useDebounce(q, 300);
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(20);
  const [openRun, setOpenRun] = useState<number | null>(params.get('run') ? Number(params.get('run')) : null);

  const setParam = (k: string, v: string) => {
    const next = new URLSearchParams(params);
    if (v) next.set(k, v);
    else next.delete(k);
    setParams(next, { replace: true });
    setPage(1);
  };

  const query = { workflow_id: workflow || undefined, status: status || undefined, test, q: dq || undefined, page, per_page: perPage };
  const { data, isLoading, isFetching, error, refetch } = useRunList(query, true);
  const counts = data?.counts ?? {};
  const tabs = STATUS_TABS.map((t) => ({
    ...t,
    count: t.key === '' ? Object.values(counts).reduce((a, b) => a + (b ?? 0), 0) : t.key === 'active' ? (counts.running ?? 0) + (counts.waiting ?? 0) : counts[t.key as keyof typeof counts] ?? 0,
  }));

  const columns = useMemo<Column<RunRow>[]>(
    () => [
      {
        key: 'id',
        header: 'Run',
        render: (r) => (
          <div className="flex items-center gap-1.5">
            <span className="font-mono text-xs font-semibold text-slate-700 dark:text-slate-200">#{r.id}</span>
            {r.is_test && (
              <Badge color="purple">
                <FlaskConical className="h-3 w-3" aria-hidden /> Test
              </Badge>
            )}
          </div>
        ),
      },
      {
        key: 'contact',
        header: 'Contact',
        render: (r) => (
          <div className="min-w-0 leading-tight">
            <p className="truncate font-semibold text-slate-900 dark:text-white">{r.contact_name || (r.contact_phone ? `+${r.contact_phone}` : 'No contact')}</p>
            {r.contact_phone && <p className="text-xs text-slate-500">+{r.contact_phone}</p>}
          </div>
        ),
      },
      { key: 'workflow_name', header: 'Workflow', render: (r) => <span className="line-clamp-2 text-sm">{r.workflow_name}</span>, hidden: !!workflow },
      {
        key: 'status',
        header: 'Status',
        render: (r) => (
          <div className="space-y-0.5">
            <StatusBadge status={r.status} colors={RUN_STATUS_COLORS} />
            {waitingText(r) && <p className="text-[11px] text-slate-500 dark:text-slate-400">{waitingText(r)}</p>}
          </div>
        ),
      },
      {
        key: 'current',
        header: 'Current / last step',
        render: (r) => {
          const spec = r.current_node_type ? NODE_SPECS[r.current_node_type] : null;
          const Icon = spec?.icon;
          return (
            <div className="min-w-0">
              <p className="flex items-center gap-1.5 truncate text-sm text-slate-700 dark:text-slate-200">
                {Icon && <Icon className="h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden />}
                <span className="truncate">{r.current_node_label ?? '—'}</span>
              </p>
              {r.error && <p className="line-clamp-1 text-[11px] text-red-600 dark:text-red-300" title={r.error}>{r.error}</p>}
            </div>
          );
        },
      },
      { key: 'steps', header: 'Steps', align: 'center', render: (r) => <span className="tabular-nums text-sm">{r.steps_count}</span> },
      {
        key: 'started_at',
        header: 'Started',
        render: (r) => (
          <span className="whitespace-nowrap text-sm" title={formatDateTime(r.started_at)}>
            {timeAgo(r.started_at)}
          </span>
        ),
      },
    ],
    [workflow],
  );

  return (
    <div className="space-y-4">
      <Card className="p-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
          <Tabs tabs={tabs} value={status} onChange={(v) => setParam('status', v)} variant="pills" className="lg:flex-1" />
          <div className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,14rem)_minmax(0,14rem)_auto] sm:items-center">
            <Select
              inputSize="sm"
              aria-label="Workflow"
              value={workflow}
              onChange={(e) => setParam('workflow', e.target.value)}
              placeholder="All workflows"
              options={workflows.map((w) => ({ value: String(w.id), label: w.name }))}
            />
            <SearchInput size="sm" value={q} onChange={(v) => { setQ(v); setPage(1); }} placeholder="Contact name, phone or run #" />
            <label className="inline-flex items-center gap-2 whitespace-nowrap text-sm text-slate-600 dark:text-slate-300">
              <input type="checkbox" className="form-checkbox" checked={test === '1'} onChange={(e) => setParam('test', e.target.checked ? '1' : '')} />
              Test runs
            </label>
          </div>
        </div>
      </Card>

      {error ? (
        <Alert variant="error" title="Unable to load the execution history" action={<Button size="sm" variant="secondary" onClick={() => refetch()}>Retry</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : (
        <Card className="overflow-hidden">
          <div className="flex items-center justify-between gap-2 border-b border-slate-100 px-4 py-2.5 dark:border-slate-800">
            <p className="text-sm text-slate-600 dark:text-slate-300">
              {data ? `${data.total.toLocaleString('en-IN')} run${data.total === 1 ? '' : 's'}` : 'Loading…'}
              {test === '1' && ' · test runs'}
            </p>
            <Button size="xs" variant="ghost" icon={RefreshCw} loading={isFetching && !isLoading} onClick={() => refetch()}>
              Refresh
            </Button>
          </div>
          {/* phones: stacked cards */}
          <ul className="divide-y divide-slate-100 sm:hidden dark:divide-slate-800">
            {isLoading
              ? [0, 1, 2, 3].map((i) => (
                  <li key={i} className="p-4">
                    <div className="skeleton h-12 w-full" />
                  </li>
                ))
              : (data?.rows ?? []).map((r) => (
                  <li key={r.id}>
                    <button type="button" onClick={() => setOpenRun(r.id)} className="w-full space-y-1.5 p-4 text-left hover:bg-slate-50 dark:hover:bg-slate-800/50">
                      <div className="flex items-center justify-between gap-2">
                        <span className="min-w-0 truncate font-semibold text-slate-900 dark:text-white">{r.contact_name || (r.contact_phone ? `+${r.contact_phone}` : 'No contact')}</span>
                        <StatusBadge status={r.status} colors={RUN_STATUS_COLORS} />
                      </div>
                      <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                        #{r.id} · {r.workflow_name} · {timeAgo(r.started_at)}
                        {r.is_test && ' · test'}
                      </p>
                      <p className="truncate text-xs text-slate-600 dark:text-slate-300">{waitingText(r) ?? r.current_node_label ?? ''}</p>
                      {r.error && <p className="line-clamp-2 text-xs text-red-600 dark:text-red-300">{r.error}</p>}
                    </button>
                  </li>
                ))}
            {!isLoading && data && data.rows.length === 0 && (
              <li>
                <EmptyState icon={History} title="No runs found" description="Change the filters or wait for the next trigger." />
              </li>
            )}
          </ul>
          <div className="hidden sm:block">
          <DataTable
            columns={columns}
            rows={data?.rows ?? []}
            rowKey={(r) => r.id}
            loading={isLoading}
            onRowClick={(r) => setOpenRun(r.id)}
            empty={
              <EmptyState
                icon={History}
                title={status || workflow || dq ? 'No runs match these filters' : 'No runs yet'}
                description={status || workflow || dq ? 'Clear a filter to see more.' : 'Runs appear here as soon as an active workflow is triggered by its event.'}
                action={status || workflow || dq ? <Button size="sm" variant="secondary" onClick={() => { setQ(''); setParams(new URLSearchParams({ tab: 'runs' }), { replace: true }); }}>Clear filters</Button> : undefined}
              />
            }
          />
          </div>
          {data && data.total > 0 && (
            <div className="border-t border-slate-100 px-4 py-3 dark:border-slate-800">
              <Pagination page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={(n) => { setPerPage(n); setPage(1); }} perPageOptions={[10, 20, 50, 100]} />
            </div>
          )}
        </Card>
      )}
      <RunDrawer runId={openRun} onClose={() => setOpenRun(null)} />
    </div>
  );
}
