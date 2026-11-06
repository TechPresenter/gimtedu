import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Bot, CornerDownRight, ExternalLink, EyeOff, Link2Off, Plus, SearchX, Trash2 } from 'lucide-react';
import { Alert, Badge, Button, Card, DataTable, Dropdown, EmptyState, Pagination, SearchInput, Select, useConfirm, useToast, type Column, type SortState } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import type { NotFoundRow, RedirectDraft } from './types';

interface Props {
  canEdit: boolean;
  onCreate: (draft: Partial<RedirectDraft>) => void;
  onTest: (url: string) => void;
}

/** URLs that answered 404 (seo_404_log): who hit them, from where, a suggested target and one-click redirects. */
export function NotFoundReport({ canEdit, onCreate, onTest }: Props) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const [q, setQ] = useState('');
  const [who, setWho] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [sort, setSort] = useState<SortState>({ key: 'last_seen', dir: 'desc' });
  const dq = useDebounce(q, 300);
  useEffect(() => setPage(1), [dq, who, perPage]);
  const query = { q: dq || undefined, who: who || undefined, page, per_page: perPage, sort: sort.key, dir: sort.dir };
  const { data, isLoading, isFetching, error } = useQuery({
    queryKey: ['redirects', '404s', query],
    queryFn: () => api.get<{ rows: NotFoundRow[]; total: number; page: number; pages: number; per_page: number }>('redirects/404s', query),
    placeholderData: (p) => p,
  });
  const refresh = () => qc.invalidateQueries({ queryKey: ['redirects'] });

  const ignore = async (r: NotFoundRow) => {
    try {
      const res = await api.del(`redirects/404s/${r.id}`);
      toast.success(res.message);
      void refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const clear = async (days: number) => {
    if (!(await confirm({ title: days ? `Remove 404s not seen for ${days} days?` : 'Clear the whole 404 report?', message: 'Redirect rules are not affected. New 404s are recorded again as they happen.', confirmText: 'Remove', danger: true }))) return;
    try {
      const res = await api.post('redirects/404s/clear', { older_than_days: days || undefined });
      toast.success(res.message);
      void refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const columns: Column<NotFoundRow>[] = [
    {
      key: 'path', header: 'Missing URL', sortable: true, render: (r) => (
        <div className="max-w-[340px]">
          <a href={r.url} target="_blank" rel="noreferrer" className="flex items-center gap-1 font-mono text-xs font-semibold text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">
            <span className="truncate" title={r.path}>{r.path}</span><ExternalLink className="h-3 w-3 shrink-0" aria-hidden />
          </a>
          {r.last_referrer && (
            <p className="mt-0.5 flex items-center gap-1 truncate text-[11px] text-slate-500" title={r.last_referrer}>
              {r.internal_referrer ? <Link2Off className="h-3 w-3 shrink-0 text-red-500" aria-label="Broken link on this website" /> : <CornerDownRight className="h-3 w-3 shrink-0" aria-hidden />}
              <span className="truncate">{r.internal_referrer ? 'Linked from ' : 'From '}{r.last_referrer}</span>
            </p>
          )}
        </div>
      ),
    },
    {
      key: 'hits', header: 'Hits', sortable: true, align: 'right', render: (r) => (
        <div className="text-right">
          <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(r.hits)}</span>
          {r.bot_hits > 0 && <p className="flex items-center justify-end gap-1 text-[11px] text-slate-500"><Bot className="h-3 w-3" aria-hidden />{formatNumber(r.bot_hits)} crawler</p>}
        </div>
      ),
    },
    { key: 'last_seen', header: 'Last seen', sortable: true, render: (r) => <span className="whitespace-nowrap text-sm" title={formatDateTime(r.last_seen_at)}>{timeAgo(r.last_seen_at)}</span> },
    { key: 'first_seen', header: 'First seen', sortable: true, render: (r) => <span className="whitespace-nowrap text-xs text-slate-500">{formatDateTime(r.first_seen_at)}</span> },
    {
      key: 'suggestion', header: 'Suggested target', render: (r) => r.suggestion ? (
        <button type="button" disabled={!canEdit} onClick={() => onCreate({ from_path: r.path, to_url: r.suggestion ?? '', source: '404', notes: 'From the 404 report' })}
          className="inline-flex max-w-[220px] items-center gap-1 rounded-lg bg-emerald-50 px-2 py-1 text-left font-mono text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-100 disabled:cursor-default dark:bg-emerald-500/10 dark:text-emerald-300">
          <span className="truncate">→ {r.suggestion}</span>
        </button>
      ) : <span className="text-xs text-slate-400">—</span>,
    },
  ];

  return (
    <Card className="overflow-hidden">
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center dark:border-slate-800">
        <SearchInput value={q} onChange={setQ} placeholder="Search missing URLs or referrers…" className="w-full lg:max-w-xs" />
        <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
          <Select inputSize="sm" value={who} onChange={(e) => setWho(e.target.value)} aria-label="Who hit the URL" className="w-full sm:w-auto"
            options={[{ value: '', label: 'All hits' }, { value: 'humans', label: 'Visitors' }, { value: 'bots', label: 'Search engine crawlers' }, { value: 'internal', label: 'Broken links on this website' }]} />
          {canEdit && (
            <Dropdown trigger={<><Trash2 className="h-4 w-4" />Clean up</>} items={[
              { label: 'Remove 404s not seen for 30 days', onClick: () => void clear(30) },
              { label: 'Remove 404s not seen for 90 days', onClick: () => void clear(90) },
              { label: 'Clear the whole report', onClick: () => void clear(0), danger: true },
            ]} />
          )}
        </div>
      </div>
      {error ? <div className="p-5"><Alert variant="error">{(error as ApiError).message}</Alert></div> : (
        <DataTable<NotFoundRow>
          columns={columns}
          rows={data?.rows ?? []}
          rowKey={(r) => r.id}
          loading={isLoading || isFetching}
          sort={sort}
          onSort={setSort}
          caption="URLs that answered 404"
          empty={<EmptyState icon={SearchX} title={q || who ? 'No 404s match these filters' : 'No 404s recorded'} description={q || who ? 'Try clearing the filters.' : 'Missing URLs that visitors or search engines open appear here, ready to be redirected.'} />}
          actions={(r) => (
            <div className="flex items-center justify-end gap-1.5">
              <Button size="xs" variant="secondary" onClick={() => onTest(r.path)}>Test</Button>
              {canEdit && <Button size="xs" icon={Plus} onClick={() => onCreate({ from_path: r.path, to_url: r.suggestion ?? '', source: '404', notes: 'From the 404 report' })}>Redirect</Button>}
              {canEdit && (
                <button type="button" onClick={() => void ignore(r)} className="btn-icon !h-8 !w-8 !rounded-lg" aria-label={`Ignore ${r.path}`} title="Ignore (remove from the report)">
                  <EyeOff className="h-4 w-4" aria-hidden />
                </button>
              )}
            </div>
          )}
        />
      )}
      {data && data.total > 0 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={setPerPage} />}
      <p className="flex items-center gap-1.5 border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800">
        <Badge color="slate">Privacy</Badge> Only the path, hit counts and the last referring page are stored — no IP addresses or visitor data.
      </p>
    </Card>
  );
}
