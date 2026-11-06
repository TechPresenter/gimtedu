import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Bug, ChevronRight, Globe, Monitor, Server, Trash2 } from 'lucide-react';
import { Alert, Badge, Button, Drawer, Dropdown, EmptyState, Pagination, SearchInput, Select, Skeleton, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';

interface LogRow {
  id: number;
  level: string;
  message: string;
  channel: 'client' | 'server';
  created_at: string;
  context: { type?: string; source?: string | null; line?: number | null; column?: number | null; stack?: string | null; page?: string | null; browser?: string; url?: string; file?: string } | null;
}
interface LogPayload {
  rows: LogRow[];
  total: number;
  page: number;
  pages: number;
  per_page: number;
  stats: { client_24h: number; client_7d: number; client_total: number; server_7d: number };
  can_clear: boolean;
}

/** Error log (GET /api/integrations/errors): website JavaScript errors reported by the public pages + server errors. */
export function ErrorLogDrawer({ open, onClose }: { open: boolean; onClose: () => void }) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [channel, setChannel] = useState<'client' | 'server' | 'all'>('client');
  const [days, setDays] = useState('7');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [expanded, setExpanded] = useState<number | null>(null);
  const dq = useDebounce(q, 300);
  useEffect(() => setPage(1), [channel, days, dq, perPage]);
  const query = { channel, days: days || undefined, q: dq || undefined, page, per_page: perPage };
  const { data, isLoading, isFetching, error } = useQuery({
    queryKey: ['integrations', 'errors', query],
    queryFn: () => api.get<LogPayload>('integrations/errors', query),
    enabled: open,
    placeholderData: (p) => p,
  });

  const clear = async (olderThan: number) => {
    const what = channel === 'client' ? 'website JavaScript errors' : channel === 'server' ? 'server errors' : 'log entries';
    if (!(await confirm({ title: olderThan ? `Remove ${what} older than ${olderThan} days?` : `Remove all ${what}?`, message: 'This cannot be undone.', confirmText: 'Remove', danger: true }))) return;
    try {
      const res = await api.post('integrations/errors/clear', { channel, older_than_days: olderThan || undefined });
      toast.success(res.message);
      void qc.invalidateQueries({ queryKey: ['integrations'] });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <Drawer open={open} onClose={onClose} width="max-w-3xl" title="Error log" description="JavaScript errors reported by the public website (rate-limited, no personal data) and server-side errors.">
      <div className="space-y-4">
        {data && (
          <div className="grid grid-cols-3 gap-2 text-center">
            <div className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/50"><p className="text-[11px] text-slate-500">Website · 24 h</p><p className="font-display text-lg font-bold tabular-nums">{formatNumber(data.stats.client_24h)}</p></div>
            <div className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/50"><p className="text-[11px] text-slate-500">Website · 7 days</p><p className="font-display text-lg font-bold tabular-nums">{formatNumber(data.stats.client_7d)}</p></div>
            <div className="rounded-xl bg-slate-50 px-2 py-2 dark:bg-slate-800/50"><p className="text-[11px] text-slate-500">Server · 7 days</p><p className="font-display text-lg font-bold tabular-nums">{formatNumber(data.stats.server_7d)}</p></div>
          </div>
        )}
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <SearchInput value={q} onChange={setQ} placeholder="Search messages, pages, files…" className="min-w-0 sm:flex-1" size="sm" />
          <Select inputSize="sm" className="sm:!w-52" value={channel} onChange={(e) => setChannel(e.target.value as 'client' | 'server' | 'all')} aria-label="Source"
            options={[{ value: 'client', label: 'Website (JavaScript)' }, { value: 'server', label: 'Server' }, { value: 'all', label: 'All sources' }]} />
          <Select inputSize="sm" className="sm:!w-36" value={days} onChange={(e) => setDays(e.target.value)} aria-label="Period"
            options={[{ value: '1', label: 'Last 24 hours' }, { value: '7', label: 'Last 7 days' }, { value: '30', label: 'Last 30 days' }, { value: '', label: 'All time' }]} />
          {data?.can_clear && (
            <Dropdown trigger={<><Trash2 className="h-4 w-4" />Clear</>} items={[
              { label: 'Older than 30 days', onClick: () => void clear(30) },
              { label: 'Older than 7 days', onClick: () => void clear(7) },
              { label: 'Everything in this source', onClick: () => void clear(0), danger: true },
            ]} />
          )}
        </div>
        {error && <Alert variant="error">{(error as ApiError).message}</Alert>}
        {isLoading ? (
          <div className="space-y-2">{Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-16 w-full rounded-xl" />)}</div>
        ) : !data?.rows.length ? (
          <EmptyState icon={Bug} title="No errors in this period" description={channel === 'client' ? 'The website reporter has not received any JavaScript errors. Errors appear here within seconds.' : 'Nothing was logged.'} />
        ) : (
          <ul className={isFetching ? 'space-y-2 opacity-70 transition-opacity' : 'space-y-2'} aria-label="Log entries">
            {data.rows.map((r) => {
              const c = r.context ?? {};
              const isOpen = expanded === r.id;
              return (
                <li key={r.id} className="rounded-xl border border-slate-200 dark:border-slate-700">
                  <button type="button" onClick={() => setExpanded(isOpen ? null : r.id)} aria-expanded={isOpen} className="flex w-full items-start gap-3 px-3.5 py-3 text-left">
                    <ChevronRight className={`mt-0.5 h-4 w-4 shrink-0 text-slate-400 transition-transform ${isOpen ? 'rotate-90' : ''}`} aria-hidden />
                    <span className="min-w-0 flex-1">
                      <span className="flex flex-wrap items-center gap-1.5">
                        <Badge color={r.level === 'warning' ? 'amber' : r.level === 'info' ? 'blue' : 'red'}>{r.level}</Badge>
                        <Badge color={r.channel === 'client' ? 'purple' : 'slate'}>{r.channel === 'client' ? (c.type ?? 'website') : 'server'}</Badge>
                        <span className="text-xs text-slate-500" title={formatDateTime(r.created_at)}>{timeAgo(r.created_at)}</span>
                      </span>
                      <span className="mt-1 block break-words text-sm font-medium text-slate-800 dark:text-slate-100">{r.message.replace(/^Client (error|rejection|resource): /, '')}</span>
                      <span className="mt-0.5 flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-slate-500">
                        {(c.page || c.url) && <span className="inline-flex items-center gap-1"><Globe className="h-3 w-3" />{c.page ?? c.url}</span>}
                        {c.browser && <span className="inline-flex items-center gap-1"><Monitor className="h-3 w-3" />{c.browser}</span>}
                        {(c.source || c.file) && <span className="inline-flex max-w-full items-center gap-1 truncate"><Server className="h-3 w-3 shrink-0" /><span className="truncate">{c.source ?? c.file}{c.line ? `:${c.line}${c.column ? `:${c.column}` : ''}` : ''}</span></span>}
                      </span>
                    </span>
                  </button>
                  {isOpen && (
                    <div className="border-t border-slate-100 px-3.5 py-3 dark:border-slate-800">
                      {c.stack ? <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-900 p-3 text-[11px] leading-relaxed text-slate-100">{c.stack}</pre>
                        : <pre className="max-h-64 overflow-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-3 text-[11px] text-slate-700 dark:bg-slate-800 dark:text-slate-200">{JSON.stringify(c, null, 2)}</pre>}
                    </div>
                  )}
                </li>
              );
            })}
          </ul>
        )}
        {data && data.total > 0 && <Pagination page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={setPerPage} />}
        <div className="flex justify-end"><Button variant="secondary" onClick={onClose}>Close</Button></div>
      </div>
    </Drawer>
  );
}
