import { useQueryClient } from '@tanstack/react-query';
import { ExternalLink, KeyRound, Shuffle, TriangleAlert } from 'lucide-react';
import { Alert, Badge, Card, CardHeader, Reveal, Skeleton } from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { useApi } from '@/lib/queries';
import { appUrl } from '@/lib/config';
import { formatNumber } from '@/lib/format';
import type { Row } from '@/lib/types';
import type { ApiError } from '@/lib/api';

interface Conflicts {
  clusters: { cluster: string; urls: string[]; keywords: { id: number; keyword: string; target_url: string; is_primary: boolean }[] }[];
  urls: { url: string; keywords: { id: number; keyword: string; cluster: string | null }[] }[];
  count: number;
}

/** Target URL as a link: same-site paths open on this website, absolute URLs as given. */
function urlHref(u: string) {
  return /^https?:\/\//i.test(u) ? u : appUrl(u.replace(/^\//, ''));
}
function shortUrl(u: string) {
  return u.replace(/^https?:\/\/[^/]+/i, '') || '/';
}

export default function KeywordsTab() {
  const qc = useQueryClient();
  const conflicts = useApi<Conflicts>(['seo', 'keyword-conflicts'], 'seo-ai/keywords/conflicts');
  const c = conflicts.data;
  const refresh = () => void qc.invalidateQueries({ queryKey: ['seo', 'keyword-conflicts'] });

  return (
    <div className="space-y-6">
      {conflicts.error ? <Alert variant="error">{(conflicts.error as ApiError).message}</Alert> : conflicts.isLoading ? <Skeleton className="h-16 w-full" /> : c && c.count > 0 ? (
        <Reveal>
          <Card>
            <CardHeader icon={Shuffle} title={`Keyword cannibalisation: ${c.count} conflict${c.count === 1 ? '' : 's'}`}
              subtitle="Several pages competing for one cluster split their ranking signals. Map each cluster to one page, and give each page one primary keyword." />
            <div className="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
              {c.clusters.map((g) => (
                <div key={`c-${g.cluster}`} className="rounded-xl border border-amber-200 bg-amber-50/60 p-3.5 dark:border-amber-500/30 dark:bg-amber-500/10">
                  <p className="flex items-center gap-1.5 text-sm font-semibold text-amber-900 dark:text-amber-200"><TriangleAlert className="h-4 w-4" aria-hidden />Cluster “{g.cluster}” targets {g.urls.length} URLs</p>
                  <ul className="mt-2 space-y-1 text-xs text-slate-700 dark:text-slate-200">
                    {g.keywords.map((k) => (
                      <li key={k.id} className="flex flex-wrap items-center gap-x-2">
                        <span className="font-medium">{k.keyword}</span>{k.is_primary && <Badge color="navy" className="!text-[10px]">primary</Badge>}
                        <a href={urlHref(k.target_url)} target="_blank" rel="noreferrer noopener" className="inline-flex items-center gap-0.5 font-mono text-[11px] text-brand-700 hover:underline dark:text-brand-300">{shortUrl(k.target_url)}<ExternalLink className="h-3 w-3" aria-hidden /></a>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
              {c.urls.map((g) => (
                <div key={`u-${g.url}`} className="rounded-xl border border-amber-200 bg-amber-50/60 p-3.5 dark:border-amber-500/30 dark:bg-amber-500/10">
                  <p className="flex flex-wrap items-center gap-1.5 text-sm font-semibold text-amber-900 dark:text-amber-200"><TriangleAlert className="h-4 w-4" aria-hidden />{g.keywords.length} primary keywords on <span className="font-mono text-xs">{shortUrl(g.url)}</span></p>
                  <ul className="mt-2 space-y-1 text-xs text-slate-700 dark:text-slate-200">
                    {g.keywords.map((k) => <li key={k.id}><span className="font-medium">{k.keyword}</span>{k.cluster && <span className="text-slate-500"> · {k.cluster}</span>}</li>)}
                  </ul>
                </div>
              ))}
            </div>
          </Card>
        </Reveal>
      ) : null}
      <CrudTable
        module="seo_keywords"
        title="Keyword master database"
        description="One primary keyword cluster per landing page. Import the keyword sheet (CSV / Excel) — “not measured” and “unknown” are kept as empty numbers, never guessed."
        addLabel="Add keyword"
        emptyTitle="No keywords yet"
        emptyText="Add keywords one by one or import your keyword sheet (keyword, category, intent, volume, difficulty, competition, target_url, priority, current_position, status)."
        onSaved={refresh}
        onDeleted={refresh}
        renderers={{
          keyword: (r: Row) => (
            <div className="min-w-[200px] max-w-[300px]">
              <p className="flex items-start gap-1.5 font-semibold text-slate-900 dark:text-white">
                <KeyRound className="mt-1 h-3.5 w-3.5 shrink-0 text-slate-400" aria-hidden /><span className="min-w-0">{r.keyword}</span>
              </p>
              {(r.conflicts as string[] | undefined)?.length ? <Badge color="amber" className="!text-[10px]">{(r.conflicts as string[]).includes('url_multi_primary') ? 'URL has 2+ primaries' : 'Cluster on 2+ URLs'}</Badge> : null}
              <p className="truncate pl-5 text-xs text-slate-500">{[r.category, r.cluster && r.cluster !== r.category ? `cluster ${r.cluster}` : null].filter(Boolean).join(' · ') || 'No category'}</p>
            </div>
          ),
          target_url: (r: Row) => r.target_url ? (
            <a href={urlHref(String(r.target_url))} target="_blank" rel="noreferrer noopener" className="inline-flex max-w-[240px] items-center gap-1 font-mono text-xs text-brand-700 hover:underline dark:text-brand-300">
              <span className="truncate">{shortUrl(String(r.target_url))}</span><ExternalLink className="h-3 w-3 shrink-0" aria-hidden />
            </a>
          ) : <Badge color="amber">No page</Badge>,
          monthly_volume: (r: Row) => r.monthly_volume !== null && r.monthly_volume !== undefined
            ? <span className="tabular-nums">{formatNumber(Number(r.monthly_volume))}</span>
            : <span className="whitespace-nowrap text-xs text-slate-400" title={String(r.volume_source ?? '')}>not measured</span>,
          current_position: (r: Row) => r.current_position !== null && r.current_position !== undefined
            ? <span className={Number(r.current_position) <= 10 ? 'font-semibold text-emerald-600 tabular-nums dark:text-emerald-400' : 'tabular-nums'}>{Number(r.current_position).toFixed(1)}</span>
            : <span className="whitespace-nowrap text-xs text-slate-400">unknown</span>,
        }}
        header={({ summary }) => {
          const s = summary as Record<string, number> | null | undefined;
          if (!s || !s.total) return null;
          const chips: [string, number, string][] = [
            ['Keywords', s.total, 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200'],
            ['Primary', s.primary_kw, 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200'],
            ['High priority', s.high, 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300'],
            ['Volume measured', s.measured, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
            ['In the top 10', s.top10, 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300'],
            ['Pages to create', s.planned, 'bg-purple-50 text-purple-700 dark:bg-purple-500/15 dark:text-purple-300'],
            ['Conflicts', s.conflicts, s.conflicts ? 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-slate-100 text-slate-500 dark:bg-slate-800'],
          ];
          return (
            <div className="flex flex-wrap gap-2 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
              {chips.map(([label, n, cls]) => <span key={label} className={`rounded-full px-2.5 py-1 text-xs font-semibold ${cls}`}>{label} <span className="tabular-nums">{formatNumber(n ?? 0)}</span></span>)}
            </div>
          );
        }}
      />
    </div>
  );
}
