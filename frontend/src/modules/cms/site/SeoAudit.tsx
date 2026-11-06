import { useEffect, useMemo, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  CircleAlert, CircleCheck, Clock, Download, ExternalLink, FileWarning, Gauge, History, Info, Link2Off, Play, Radar, RefreshCcw, SearchCheck, Square, Trash2, TriangleAlert,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, Pagination, ProgressBar, Reveal, SearchInput, Select, Skeleton, Stagger, StatCard, useConfirm, useToast,
  type Column, type SortState,
} from '@/components/ui';
import { api, ApiError, downloadFile } from '@/lib/api';
import { useDebounce } from '@/lib/hooks';
import { formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';

type Severity = 'error' | 'warning' | 'notice';
interface AuditSummary {
  issues: Record<string, number>;
  status: Record<'2xx' | '3xx' | '4xx' | '5xx' | 'failed', number>;
  pages: number;
  files: number;
  skipped: number;
  sitemap_urls: number;
  response: { avg: number; median: number; p95: number; max: number };
  slowest: { path: string; ms: number; title: string | null }[];
  broken: { path: string; status: number; sources: string[]; count: number }[];
  duplicates: { title: { value: string; paths: string[]; count: number }[]; description: { value: string; paths: string[]; count: number }[] };
  limit_reached: boolean;
}
interface Audit {
  id: number;
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
  mode: 'inline' | 'worker';
  base_url: string;
  max_pages: number;
  pages_found: number;
  pages_crawled: number;
  errors_count: number;
  warnings_count: number;
  summary: AuditSummary | null;
  message: string | null;
  started_at: string | null;
  finished_at: string | null;
  started_by_name: string | null;
  queued: number;
  stale: boolean;
}
interface HistoryRow { id: number; status: Audit['status']; pages_crawled: number; errors_count: number; warnings_count: number; started_at: string; finished_at: string | null; started_by_name: string | null }
interface AuditHome {
  current: Audit | null;
  history: HistoryRow[];
  issues: Record<string, { label: string; severity: Severity }>;
  mode: 'inline' | 'worker';
  can: { run: boolean };
  site_url: string;
}
interface PageRow {
  id: number;
  url: string;
  path: string;
  kind: 'page' | 'file' | 'seo';
  in_sitemap: number;
  depth: number;
  found_on: string | null;
  state: 'queued' | 'done' | 'skipped';
  status_code: number | null;
  redirect_to: string | null;
  response_ms: number | null;
  title: string | null;
  h1: string | null;
  images_no_alt: number | null;
  issues: string[];
  error: string | null;
}

const SEV: Record<Severity, { color: BadgeColor; label: string }> = { error: { color: 'red', label: 'Errors' }, warning: { color: 'amber', label: 'Warnings' }, notice: { color: 'blue', label: 'Notices' } };
const running = (a?: Audit | null) => !!a && (a.status === 'running' || a.status === 'queued');
const statusColor = (code: number | null, state: string): BadgeColor => (state !== 'done' ? 'slate' : !code ? 'red' : code >= 500 ? 'red' : code >= 400 ? 'red' : code >= 300 ? 'amber' : 'green');

/**
 * CMS → SEO → Audit: crawls the website's own URLs (robots.txt, sitemaps, every sitemap URL and the internal links
 * found on them, capped) and reports status codes, broken internal links, missing / duplicate titles and descriptions,
 * missing H1 / alt text, noindex pages in the sitemap and slow pages; CSV export. On the single-threaded development
 * server the crawl runs in a background PHP process; elsewhere this page drives it in short steps.
 */
export function SeoAudit() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const home = useQuery({ queryKey: ['seo-audit', 'home'], queryFn: () => api.get<AuditHome>('integrations/seo-audit') });
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const auditId = selectedId ?? home.data?.current?.id ?? null;
  const audit = useQuery({
    queryKey: ['seo-audit', 'audit', auditId],
    queryFn: () => api.get<Audit>(`integrations/seo-audit/${auditId}`),
    enabled: !!auditId,
    refetchInterval: (q) => (running(q.state.data as Audit | undefined) ? 2000 : false),
  });
  const a = audit.data ?? (auditId === home.data?.current?.id ? home.data?.current ?? null : null);
  const [maxPages, setMaxPages] = useState('250');
  const [starting, setStarting] = useState(false);
  const stepping = useRef(false);
  const issues = home.data?.issues ?? {};
  const canRun = home.data?.can.run ?? false;

  // Browser-driven crawl: one step request at a time while the audit runs in "inline" mode.
  useEffect(() => {
    if (!a || !running(a) || !canRun || stepping.current || (a.mode === 'worker' && !a.stale)) return;
    stepping.current = true;
    api.post<Audit>(`integrations/seo-audit/${a.id}/step`)
      .then((r) => qc.setQueryData(['seo-audit', 'audit', a.id], r.data))
      .catch((e: ApiError) => toast.error(e.message))
      .finally(() => {
        stepping.current = false;
      });
  }, [a, canRun, qc, toast]);

  // Finished → refresh history + pages
  const wasRunning = useRef(false);
  useEffect(() => {
    if (running(a)) wasRunning.current = true;
    else if (wasRunning.current && a) {
      wasRunning.current = false;
      void qc.invalidateQueries({ queryKey: ['seo-audit'] });
      if (a.status === 'completed') toast.success(`SEO audit finished: ${formatNumber(a.pages_crawled)} URLs checked.`);
    }
  }, [a, qc, toast]);

  const start = async () => {
    setStarting(true);
    try {
      const res = await api.post<Audit>('integrations/seo-audit', { max_pages: Number(maxPages) });
      setSelectedId(null);
      qc.setQueryData(['seo-audit', 'audit', res.data.id], res.data);
      await qc.invalidateQueries({ queryKey: ['seo-audit', 'home'] });
      toast.success(res.message);
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setStarting(false);
    }
  };
  const cancel = async () => {
    if (!a) return;
    try {
      const res = await api.post<Audit>(`integrations/seo-audit/${a.id}/cancel`);
      qc.setQueryData(['seo-audit', 'audit', a.id], res.data);
      void qc.invalidateQueries({ queryKey: ['seo-audit', 'home'] });
      toast.success(res.message);
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const remove = async (id: number) => {
    if (!(await confirm({ title: `Delete audit #${id}?`, message: 'Its crawled URLs and report are removed.', confirmText: 'Delete', danger: true }))) return;
    try {
      const res = await api.del(`integrations/seo-audit/${id}`);
      toast.success(res.message);
      if (selectedId === id) setSelectedId(null);
      void qc.invalidateQueries({ queryKey: ['seo-audit'] });
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  if (home.error) return <Alert variant="error" title="Unable to load the SEO audit">{(home.error as ApiError).message}</Alert>;
  const s = a?.summary ?? null;
  const progress = a ? Math.min(100, Math.round((a.pages_crawled / Math.max(1, a.pages_crawled + a.queued)) * 100)) : 0;

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader icon={Radar} title="Site audit" subtitle="Crawls the website like a search engine: robots.txt, the sitemaps, every sitemap URL and the internal links found on them."
          actions={a?.status === 'completed' && <Button size="sm" variant="secondary" icon={Download} onClick={() => downloadFile(`integrations/seo-audit/${a.id}/export`)}>Export CSV</Button>} />
        <div className="card-body space-y-4">
          {home.isLoading ? <Skeleton className="h-16 w-full" /> : running(a) && a ? (
            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-3">
                <span className="relative inline-flex h-3 w-3"><span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-400 opacity-60 motion-reduce:hidden" /><span className="relative inline-flex h-3 w-3 rounded-full bg-brand-600" /></span>
                <p className="text-sm font-semibold text-slate-900 dark:text-white">Audit #{a.id} is crawling… {formatNumber(a.pages_crawled)} checked · {formatNumber(a.queued)} queued</p>
                {a.stale && <Badge color="amber">Paused — resuming</Badge>}
                {canRun && <Button size="sm" variant="ghost" icon={Square} onClick={() => void cancel()} className="ml-auto">Stop</Button>}
              </div>
              <ProgressBar value={progress} tone="navy" label={`Audit progress ${progress}%`} />
              <p className="text-xs text-slate-500">{a.mode === 'worker' ? 'Running in a background process — you can leave this page.' : 'Keep this tab open while the audit runs; it continues where it stopped if you come back.'}</p>
            </div>
          ) : (
            <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
              <div className="sm:w-56">
                <label htmlFor="audit-max" className="form-label">URLs to check</label>
                <Select id="audit-max" value={maxPages} onChange={(e) => setMaxPages(e.target.value)} disabled={!canRun}
                  options={[{ value: '100', label: 'Up to 100 URLs (quick)' }, { value: '250', label: 'Up to 250 URLs' }, { value: '500', label: 'Up to 500 URLs' }, { value: '1000', label: 'Up to 1,000 URLs' }]} />
              </div>
              {canRun ? <Button icon={a ? RefreshCcw : Play} loading={starting} onClick={() => void start()}>{a ? 'Run a new audit' : 'Run the first audit'}</Button>
                : <p className="text-sm text-slate-500">Ask an administrator with SEO edit permission to run an audit.</p>}
              {a && <p className="text-xs text-slate-500 sm:ml-auto">Audit #{a.id} · {a.status === 'completed' ? `finished ${timeAgo(a.finished_at)}` : a.status}{a.started_by_name ? ` · by ${a.started_by_name}` : ''}</p>}
            </div>
          )}
          {a && a.status !== 'completed' && !running(a) && (
            <Alert variant={a.status === 'failed' ? 'error' : 'info'}>{a.status === 'cancelled' ? 'This audit was stopped before it finished.' : a.message ?? 'The audit did not finish.'}</Alert>
          )}
          {a?.message && a.status === 'completed' && <Alert variant="info">{a.message}</Alert>}
        </div>
      </Card>

      {!a && !home.isLoading && (
        <Card><EmptyState icon={SearchCheck} title="No audit yet" description="Run an audit to find broken links, missing titles and descriptions, duplicate content, missing alt text, noindex pages in the sitemap and slow pages." /></Card>
      )}

      {a && s && (
        <>
          <Stagger className="grid grid-cols-2 gap-3 lg:grid-cols-5" step={50}>
            {[
              <StatCard key="u" label="URLs checked" value={a.pages_crawled} icon={Radar} tone="navy" hint={`${formatNumber(s.pages)} pages · ${formatNumber(s.files)} files`} />,
              <StatCard key="e" label="URLs with errors" value={a.errors_count} icon={CircleAlert} tone={a.errors_count ? 'red' : 'green'} hint="Broken, 4xx/5xx, missing titles…" />,
              <StatCard key="w" label="URLs with warnings" value={a.warnings_count} icon={TriangleAlert} tone={a.warnings_count ? 'amber' : 'green'} hint="Duplicates, alt text, slow…" />,
              <StatCard key="b" label="Broken internal links" value={s.broken.length} icon={Link2Off} tone={s.broken.length ? 'red' : 'green'} hint={s.broken.length ? 'Targets answering 4xx / 5xx' : 'None found'} />,
              <StatCard key="t" label="Median response" value={`${formatNumber(s.response.median)} ms`} icon={Gauge} tone={s.response.median > 1500 ? 'amber' : 'cyan'} hint={`p95 ${formatNumber(s.response.p95)} ms · max ${formatNumber(s.response.max)} ms`} />,
            ]}
          </Stagger>
          {s.limit_reached && <Alert variant="info">The URL limit of {formatNumber(a.max_pages)} was reached — run the audit with a higher limit to check every link.</Alert>}
          <IssuesAndPages audit={a} summary={s} issues={issues} />
        </>
      )}

      {(home.data?.history.length ?? 0) > 0 && (
        <Reveal>
          <Card>
            <CardHeader icon={History} title="Audit history" subtitle="The 10 most recent audits are kept." />
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {home.data!.history.map((h) => (
                <li key={h.id} className={clsx('flex flex-wrap items-center gap-3 px-5 py-3 text-sm', h.id === a?.id && 'bg-brand-50/60 dark:bg-brand-500/5')}>
                  <span className="font-semibold text-slate-900 dark:text-white">#{h.id}</span>
                  <Badge color={h.status === 'completed' ? 'green' : h.status === 'failed' ? 'red' : h.status === 'cancelled' ? 'slate' : 'blue'}>{h.status}</Badge>
                  <span className="text-slate-600 dark:text-slate-300">{formatNumber(h.pages_crawled)} URLs · {h.errors_count} with errors · {h.warnings_count} with warnings</span>
                  <span className="text-xs text-slate-500" title={formatDateTime(h.started_at)}><Clock className="mr-1 inline h-3 w-3" />{timeAgo(h.started_at)}{h.started_by_name ? ` · ${h.started_by_name}` : ''}</span>
                  <span className="ml-auto flex gap-1.5">
                    {h.id !== a?.id && <Button size="xs" variant="secondary" onClick={() => setSelectedId(h.id)}>View</Button>}
                    {canRun && h.status !== 'running' && h.status !== 'queued' && (
                      <button type="button" className="btn-icon !h-8 !w-8 !rounded-lg hover:!bg-red-50 hover:!text-red-600" onClick={() => void remove(h.id)} aria-label={`Delete audit ${h.id}`} title="Delete">
                        <Trash2 className="h-4 w-4" aria-hidden />
                      </button>
                    )}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        </Reveal>
      )}
    </div>
  );
}

function IssuesAndPages({ audit, summary: s, issues }: { audit: Audit; summary: AuditSummary; issues: AuditHome['issues'] }) {
  const [issue, setIssue] = useState('');
  const [status, setStatus] = useState('');
  const [kind, setKind] = useState('');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [sort, setSort] = useState<SortState>({ key: 'issues', dir: 'desc' });
  const dq = useDebounce(q, 300);
  useEffect(() => setPage(1), [issue, status, kind, dq, perPage, audit.id]);
  const query = { issue: issue || undefined, status: status || undefined, kind: kind || undefined, q: dq || undefined, sort: sort.key, dir: sort.dir, page, per_page: perPage };
  const pages = useQuery({
    queryKey: ['seo-audit', 'pages', audit.id, query],
    queryFn: () => api.get<{ rows: PageRow[]; total: number; page: number; pages: number; per_page: number }>(`integrations/seo-audit/${audit.id}/pages`, query),
    placeholderData: (p) => p,
  });
  const grouped = useMemo(() => {
    const out: Record<Severity, { code: string; label: string; count: number }[]> = { error: [], warning: [], notice: [] };
    Object.entries(s.issues).forEach(([code, count]) => {
      const def = issues[code];
      if (def && count) out[def.severity].push({ code, label: def.label, count });
    });
    (Object.keys(out) as Severity[]).forEach((k) => out[k].sort((x, y) => y.count - x.count));
    return out;
  }, [s.issues, issues]);
  const pick = (code: string) => {
    setIssue(code === issue ? '' : code);
    document.getElementById('audit-pages')?.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
  };

  const columns: Column<PageRow>[] = [
    {
      key: 'path', header: 'URL', sortable: true, render: (r) => (
        <div className="max-w-[340px]">
          <a href={r.url} target="_blank" rel="noreferrer" className="flex items-center gap-1 font-mono text-xs font-semibold text-slate-800 hover:text-brand-700 dark:text-slate-100 dark:hover:text-brand-300">
            <span className="truncate" title={r.path}>{r.path}</span><ExternalLink className="h-3 w-3 shrink-0" aria-hidden />
          </a>
          <p className="truncate text-[11px] text-slate-500" title={r.title ?? r.error ?? ''}>
            {r.state === 'skipped' ? r.error : r.redirect_to ? `→ ${r.redirect_to}` : r.title ?? (r.kind === 'file' ? 'File' : r.error ?? '—')}
            {r.in_sitemap ? ' · in sitemap' : ''}
          </p>
        </div>
      ),
    },
    { key: 'status', header: 'Status', sortable: true, align: 'center', render: (r) => <Badge color={statusColor(r.status_code, r.state)}>{r.state === 'done' ? r.status_code || 'Failed' : r.state}</Badge> },
    { key: 'time', header: 'Time', sortable: true, align: 'right', render: (r) => r.response_ms != null ? <span className={clsx('tabular-nums text-sm', r.response_ms > 1500 && 'font-semibold text-amber-600')}>{formatNumber(r.response_ms)} ms</span> : <span className="text-xs text-slate-400">—</span> },
    {
      key: 'issues', header: 'Issues', sortable: true, render: (r) => r.issues.length ? (
        <div className="flex w-[260px] flex-wrap gap-1">
          {r.issues.map((c) => <Badge key={c} color={SEV[issues[c]?.severity ?? 'notice'].color}>{issues[c]?.label ?? c}</Badge>)}
        </div>
      ) : <span className="inline-flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400"><CircleCheck className="h-3.5 w-3.5" />OK</span>,
    },
  ];

  return (
    <>
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-1">
          <Card className="h-full">
            <CardHeader icon={FileWarning} title="Issues" subtitle="Number of URLs affected — select one to filter the list" />
            <div className="card-body space-y-4">
              {(Object.keys(grouped) as Severity[]).map((sev) => (
                <div key={sev}>
                  <p className="mb-1.5 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-500"><Badge color={SEV[sev].color}>{SEV[sev].label}</Badge></p>
                  {grouped[sev].length === 0 ? <p className="text-xs text-slate-400">None</p> : (
                    <ul className="space-y-0.5">
                      {grouped[sev].map((i) => (
                        <li key={i.code}>
                          <button type="button" onClick={() => pick(i.code)} aria-pressed={issue === i.code}
                            className={clsx('flex w-full items-center justify-between gap-3 rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-slate-50 dark:hover:bg-slate-800/50', issue === i.code && 'bg-brand-50 dark:bg-brand-500/10')}>
                            <span className="text-slate-700 dark:text-slate-200">{i.label}</span>
                            <span className="font-semibold tabular-nums text-slate-900 dark:text-white">{formatNumber(i.count)}</span>
                          </button>
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              ))}
              <div className="flex flex-wrap gap-1.5 border-t border-slate-100 pt-3 dark:border-slate-800">
                {(['2xx', '3xx', '4xx', '5xx', 'failed'] as const).map((k) => (
                  <button key={k} type="button" onClick={() => setStatus(status === k ? '' : k)} aria-pressed={status === k}
                    className={clsx('rounded-full px-2.5 py-1 text-xs font-semibold transition', status === k ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300')}>
                    {k} <span className="opacity-70">{formatNumber(s.status[k] ?? 0)}</span>
                  </button>
                ))}
                {s.skipped > 0 && <span className="rounded-full bg-slate-50 px-2.5 py-1 text-xs text-slate-500 dark:bg-slate-800/50">{formatNumber(s.skipped)} skipped (robots.txt)</span>}
              </div>
            </div>
          </Card>
        </Reveal>
        <Reveal delay={60} className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader icon={Link2Off} title="Broken internal links" subtitle="Pages on this website that link to URLs answering 4xx / 5xx" />
            {s.broken.length === 0 ? <EmptyState icon={CircleCheck} title="No broken internal links" description="Every internal link that was checked works." /> : (
              <ul className="max-h-[360px] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                {s.broken.map((b) => (
                  <li key={b.path} className="px-5 py-3">
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge color="red">{b.status || 'failed'}</Badge>
                      <code className="break-all text-xs font-semibold text-slate-800 dark:text-slate-100">{b.path}</code>
                      <span className="text-xs text-slate-500">linked from {b.count} page{b.count === 1 ? '' : 's'}</span>
                      <Button size="xs" variant="secondary" to={`/redirects?tab=test&url=${encodeURIComponent(b.path)}`} className="ml-auto">Redirect / test</Button>
                    </div>
                    <p className="mt-1 truncate font-mono text-[11px] text-slate-500" title={b.sources.join(', ')}>{b.sources.join(' · ')}</p>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </Reveal>
      </div>

      {(s.duplicates.title.length > 0 || s.duplicates.description.length > 0 || s.slowest.length > 0) && (
        <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
          <Reveal>
            <Card className="h-full">
              <CardHeader icon={TriangleAlert} title="Duplicate titles & descriptions" subtitle="Each indexable page needs its own title and description" />
              {s.duplicates.title.length + s.duplicates.description.length === 0 ? <EmptyState icon={CircleCheck} title="No duplicates" /> : (
                <ul className="max-h-[320px] divide-y divide-slate-100 overflow-y-auto dark:divide-slate-800">
                  {[...s.duplicates.title.map((d) => ({ ...d, kind: 'Title' })), ...s.duplicates.description.map((d) => ({ ...d, kind: 'Description' }))].map((d, i) => (
                    <li key={i} className="px-5 py-3">
                      <p className="flex items-start gap-2 text-sm"><Badge color="amber">{d.kind}</Badge><span className="line-clamp-2 text-slate-800 dark:text-slate-100">{d.value}</span></p>
                      <p className="mt-1 truncate font-mono text-[11px] text-slate-500" title={d.paths.join(', ')}>{d.count} pages: {d.paths.join(' · ')}</p>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </Reveal>
          <Reveal delay={60}>
            <Card className="h-full">
              <CardHeader icon={Gauge} title="Slowest pages" subtitle={`Server response · average ${formatNumber(s.response.avg)} ms`} />
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {s.slowest.map((p) => (
                  <li key={p.path} className="flex items-center gap-3 px-5 py-2.5 text-sm">
                    <span className="min-w-0 flex-1"><span className="block truncate font-mono text-xs font-semibold text-slate-800 dark:text-slate-100">{p.path}</span><span className="block truncate text-[11px] text-slate-500">{p.title}</span></span>
                    <span className={clsx('shrink-0 tabular-nums', p.ms > 1500 ? 'font-semibold text-amber-600' : 'text-slate-600 dark:text-slate-300')}>{formatNumber(p.ms)} ms</span>
                  </li>
                ))}
              </ul>
            </Card>
          </Reveal>
        </div>
      )}

      <Card id="audit-pages" className="scroll-mt-24 overflow-hidden">
        <div className="flex flex-col gap-3 border-b border-slate-100 p-4 lg:flex-row lg:items-center dark:border-slate-800">
          <SearchInput value={q} onChange={setQ} placeholder="Search URLs or titles…" className="w-full lg:max-w-xs" />
          <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
            <Select inputSize="sm" value={issue} onChange={(e) => setIssue(e.target.value)} aria-label="Issue" className="w-full sm:w-auto"
              options={[{ value: '', label: 'All issues' }, ...Object.entries(issues).filter(([c]) => s.issues[c]).map(([value, d]) => ({ value, label: `${d.label} (${s.issues[value]})` }))]} />
            <Select inputSize="sm" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status" className="w-full sm:w-auto"
              options={[{ value: '', label: 'Any status' }, { value: '2xx', label: '200 OK' }, { value: '3xx', label: 'Redirects' }, { value: '4xx', label: '4xx errors' }, { value: '5xx', label: '5xx errors' }, { value: 'failed', label: 'Failed' }, { value: 'skipped', label: 'Skipped' }]} />
            <Select inputSize="sm" value={kind} onChange={(e) => setKind(e.target.value)} aria-label="Kind" className="w-full sm:w-auto"
              options={[{ value: '', label: 'Pages & files' }, { value: 'page', label: 'Pages' }, { value: 'file', label: 'Files' }, { value: 'seo', label: 'robots.txt & sitemaps' }]} />
          </div>
        </div>
        {pages.error ? <div className="p-5"><Alert variant="error">{(pages.error as ApiError).message}</Alert></div> : (
          <DataTable<PageRow>
            columns={columns}
            rows={pages.data?.rows ?? []}
            rowKey={(r) => r.id}
            loading={pages.isLoading || pages.isFetching}
            sort={sort}
            onSort={setSort}
            caption="Crawled URLs"
            empty={<EmptyState icon={Info} title="No URLs match these filters" description="Try clearing the filters." />}
          />
        )}
        {pages.data && pages.data.total > 0 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={pages.data.page} pages={pages.data.pages} total={pages.data.total} perPage={pages.data.per_page} onPage={setPage} onPerPage={setPerPage} />}
      </Card>
    </>
  );
}
