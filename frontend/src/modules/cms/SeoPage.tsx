import { useEffect, useMemo, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  BookOpenText, CalendarRange, ChartPie, CircleCheck, ExternalLink, FileCode2, FileText, Globe, GraduationCap, Map as MapIcon, Megaphone, Pencil, Plus, Route as RouteIcon,
  Download, RotateCcw, Save, SearchCheck, TriangleAlert,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, Field, Input, Modal, PageHeader, Pagination, Reveal, SearchInput, Select, Skeleton, Stagger, StatCard, Tabs, Textarea,
  useConfirm, useToast, type Column, type SortState,
} from '@/components/ui';
import { DoughnutChart } from '@/components/charts';
import { api, ApiError, downloadFile } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { useApi } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { formatNumber, labelize } from '@/lib/format';
import { SeoEditor, type SeoTarget } from './site/SeoEditor';

interface Entry {
  key: string;
  type: string;
  entity_id: number | null;
  route: string | null;
  title: string;
  url: string;
  status: string;
  is_public: boolean;
  has_seo: boolean;
  seo_id: number | null;
  meta_title: string;
  effective_title: string;
  title_length: number;
  meta_description: string;
  effective_description: string;
  description_length: number;
  og_image: string;
  effective_og_image: string;
  robots: string;
  include_in_sitemap: boolean;
  issues: string[];
  score: number;
  updated_at: string | null;
}
interface Overview {
  score: number;
  total: number;
  custom: number;
  coverage: number;
  by_type: { type: string; label: string; total: number; public: number; custom: number; issues: number; score: number | null }[];
  issues: Record<string, number>;
  issue_labels: Record<string, string>;
  worst: Entry[];
  sitemap: { urls: number; pending: number };
  robots_custom: boolean;
  noindex: number;
  can: { edit: boolean };
  site_url: string;
}

const TABS = [
  { key: 'overview', label: 'Overview', icon: ChartPie },
  { key: 'routes', label: 'Routes', icon: RouteIcon },
  { key: 'pages', label: 'Pages', icon: FileText },
  { key: 'programs', label: 'Programs', icon: GraduationCap },
  { key: 'posts', label: 'Posts', icon: BookOpenText },
  { key: 'events', label: 'Events', icon: CalendarRange },
  { key: 'notices', label: 'Notices', icon: Megaphone },
  { key: 'robots', label: 'Robots & sitemap', icon: FileCode2 },
];
const TAB_TYPE: Record<string, string> = { routes: 'route', pages: 'page', programs: 'program', posts: 'post', events: 'event', notices: 'notice' };
const TYPE_TAB: Record<string, string> = Object.fromEntries(Object.entries(TAB_TYPE).map(([k, v]) => [v, k]));

function scoreTone(s: number) {
  return s >= 90 ? 'text-emerald-600 dark:text-emerald-400' : s >= 70 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400';
}
function ScorePill({ score }: { score: number }) {
  return (
    <span className={clsx('inline-flex min-w-[2.75rem] justify-center rounded-full px-2 py-0.5 text-xs font-bold tabular-nums',
      score >= 90 ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : score >= 70 ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300' : 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300')}>
      {score}
    </span>
  );
}
function LengthChip({ n, min, max }: { n: number; min: number; max: number }) {
  return <span className={clsx('ml-1.5 rounded px-1 text-[10px] font-semibold tabular-nums', n > max ? 'bg-red-50 text-red-600 dark:bg-red-500/15' : n < min ? 'bg-amber-50 text-amber-700 dark:bg-amber-500/15' : 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15')}>{n}</span>;
}

export default function SeoPage() {
  const [params, setParams] = useSearchParams();
  const tab = TABS.some((t) => t.key === params.get('tab')) ? (params.get('tab') as string) : 'overview';
  const [seo, setSeo] = useState<SeoTarget | null>(null);
  const overview = useApi<Overview>(['seo', 'overview'], 'seo/overview');
  const setTab = (k: string, extra: Record<string, string> = {}) => setParams({ ...(k === 'overview' ? {} : { tab: k }), ...extra });
  const counts = useMemo(() => Object.fromEntries((overview.data?.by_type ?? []).map((t) => [TYPE_TAB[t.type], t.total])), [overview.data]);

  return (
    <>
      <PageHeader
        title="SEO Management"
        description="Search titles, descriptions, social cards and structured data for every public URL, plus robots.txt and the sitemap."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'SEO Management' }]}
        actions={<Button variant="secondary" icon={MapIcon} onClick={() => setTab('robots')}>Sitemap & robots.txt</Button>}
      />
      <Tabs className="mb-6" value={tab} onChange={(k) => setTab(k)} tabs={TABS.map((t) => ({ ...t, count: counts[t.key] }))} />

      {tab === 'overview' && <OverviewTab q={overview} onOpen={(e) => setSeo(targetOf(e))} onTab={setTab} />}
      {TAB_TYPE[tab] && <EntriesTab key={tab} type={TAB_TYPE[tab]} label={TABS.find((t) => t.key === tab)!.label} onEdit={(e) => setSeo(targetOf(e))} onAddRoute={(route) => setSeo({ type: 'route', route, title: route })} canEdit={overview.data?.can.edit ?? false} />}
      {tab === 'robots' && <RobotsTab />}

      <SeoEditor target={seo} onClose={() => setSeo(null)} />
    </>
  );
}

function targetOf(e: Entry): SeoTarget {
  return { type: e.type, id: e.entity_id, route: e.route, title: e.title };
}

function OverviewTab({ q, onOpen, onTab }: { q: ReturnType<typeof useApi<Overview>>; onOpen: (e: Entry) => void; onTab: (k: string, extra?: Record<string, string>) => void }) {
  const d = q.data;
  if (q.error) return <Alert variant="error" title="Unable to load the SEO dashboard">{(q.error as ApiError).message}</Alert>;
  const issueTotal = d ? Object.entries(d.issues).filter(([k]) => !['noindex', 'auto_description'].includes(k)).reduce((n, [, v]) => n + v, 0) : 0;
  return (
    <>
      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={50}>
        {[
          <StatCard key="s" label="SEO health score" value={d ? `${d.score}/100` : '—'} icon={SearchCheck} tone={!d ? 'blue' : d.score >= 90 ? 'green' : d.score >= 70 ? 'amber' : 'red'} loading={q.isLoading} hint="Average across public URLs" />,
          <StatCard key="c" label="Custom SEO coverage" value={d ? `${d.coverage}%` : '—'} icon={CircleCheck} tone="navy" loading={q.isLoading} hint={d ? `${formatNumber(d.custom)} of ${formatNumber(d.total)} public URLs` : undefined} />,
          <StatCard key="i" label="Issues to fix" value={issueTotal} icon={TriangleAlert} tone="orange" loading={q.isLoading} hint="Titles, descriptions, share images" />,
          <StatCard key="m" label="Sitemap URLs" value={d?.sitemap.urls ?? 0} icon={MapIcon} tone="purple" loading={q.isLoading} hint={d?.sitemap.pending ? `${d.sitemap.pending} waiting for website templates` : 'In /sitemap.xml'} />,
        ]}
      </Stagger>
      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Coverage by content type" subtitle="Public URLs, custom SEO records and average score" icon={Globe} />
            {q.isLoading || !d ? <div className="space-y-3 p-5">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div> : (
              <div className="divide-y divide-slate-100 dark:divide-slate-800">
                {d.by_type.map((t) => (
                  <button key={t.type} type="button" onClick={() => onTab(TYPE_TAB[t.type])} className="grid w-full grid-cols-[minmax(0,1fr)_auto] items-center gap-4 px-5 py-3 text-left transition hover:bg-slate-50 sm:grid-cols-[180px_minmax(0,1fr)_auto] dark:hover:bg-slate-800/40">
                    <span>
                      <span className="block text-sm font-semibold text-slate-900 dark:text-white">{t.label}</span>
                      <span className="text-xs text-slate-500">{formatNumber(t.public)} public · {formatNumber(t.custom)} custom</span>
                    </span>
                    <span className="hidden sm:block">
                      <span className="block h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
                        <span className={clsx('block h-full rounded-full', (t.score ?? 0) >= 90 ? 'bg-emerald-500' : (t.score ?? 0) >= 70 ? 'bg-amber-500' : 'bg-red-500')} style={{ width: `${t.score ?? 0}%` }} />
                      </span>
                      <span className="mt-1 block text-[11px] text-slate-500">{t.issues ? `${t.issues} URL${t.issues === 1 ? '' : 's'} with issues` : 'No issues'}</span>
                    </span>
                    <span className={clsx('font-display text-xl font-bold tabular-nums', t.score === null ? 'text-slate-300' : scoreTone(t.score))}>{t.score ?? '—'}</span>
                  </button>
                ))}
              </div>
            )}
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Issue breakdown" subtitle="Public URLs affected" icon={TriangleAlert} />
            <div className="card-body">
              {q.isLoading || !d ? <Skeleton className="h-48 w-full" /> : (
                <>
                  <DoughnutChart height={150} valueFormat="number" centerValue={issueTotal} centerLabel="issues" legendList={false}
                    labels={['missing_title', 'title_long', 'description_long', 'missing_og_image'].map((k) => d.issue_labels[k])}
                    data={['missing_title', 'title_long', 'description_long', 'missing_og_image'].map((k) => d.issues[k] ?? 0)} />
                  <ul className="mt-4 space-y-1.5 text-sm">
                    {Object.entries(d.issue_labels).map(([k, label]) => (
                      <li key={k} className="flex items-center justify-between gap-3">
                        <span className="text-slate-600 dark:text-slate-300">{label}</span>
                        <span className={clsx('font-semibold tabular-nums', d.issues[k] ? 'text-slate-900 dark:text-white' : 'text-slate-400')}>{formatNumber(d.issues[k] ?? 0)}</span>
                      </li>
                    ))}
                  </ul>
                </>
              )}
            </div>
          </Card>
        </Reveal>
      </div>
      <Reveal>
        <Card>
          <CardHeader title="Needs attention" subtitle="Public URLs with the lowest scores" icon={SearchCheck} />
          {q.isLoading || !d ? <div className="p-5"><Skeleton className="h-40 w-full" /></div> : d.worst.length === 0 ? (
            <EmptyState icon={CircleCheck} title="Everything looks great" description="Every public URL has a good title, description and share image." />
          ) : (
            <ul className="divide-y divide-slate-100 dark:divide-slate-800">
              {d.worst.map((e) => (
                <li key={e.key} className="flex flex-wrap items-center gap-3 px-5 py-3">
                  <ScorePill score={e.score} />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{e.title}</span>
                    <span className="block truncate text-xs text-slate-500">{labelize(e.type)} · {e.url}</span>
                  </span>
                  <span className="flex flex-wrap gap-1">{e.issues.filter((i) => i !== 'auto_description').map((i) => <Badge key={i} color={i === 'noindex' ? 'slate' : 'amber'}>{d.issue_labels[i]}</Badge>)}</span>
                  <Button size="sm" variant="secondary" icon={Pencil} onClick={() => onOpen(e)}>Fix</Button>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </Reveal>
    </>
  );
}

function EntriesTab({ type, label, onEdit, onAddRoute, canEdit }: { type: string; label: string; onEdit: (e: Entry) => void; onAddRoute: (route: string) => void; canEdit: boolean }) {
  const [q, setQ] = useState('');
  const [issue, setIssue] = useState('');
  const [status, setStatus] = useState('');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useState(25);
  const [sort, setSort] = useState<SortState>({ key: 'score', dir: 'asc' });
  const [addOpen, setAddOpen] = useState(false);
  const [route, setRoute] = useState('/');
  const dq = useDebounce(q, 300);
  useEffect(() => setPage(1), [dq, issue, status, perPage]);
  const query = { type, q: dq || undefined, issue: issue || undefined, status: status || undefined, page, per_page: perPage, sort: sort.key, dir: sort.dir };
  const { data, isLoading, isFetching, error } = useQuery({
    queryKey: ['seo', 'entries', query],
    queryFn: () => api.get<{ rows: Entry[]; total: number; page: number; pages: number; per_page: number; issue_labels: Record<string, string> }>('seo/entries', query),
    placeholderData: (p) => p,
  });
  const labels = data?.issue_labels ?? {};
  const columns: Column<Entry>[] = [
    {
      key: 'title', header: label.replace(/s$/, ''), sortable: true, render: (e) => (
        <div className="w-[220px]">
          <button type="button" onClick={() => onEdit(e)} className="block w-full truncate text-left font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300" title={e.title}>{e.title}</button>
          <a href={appUrl(e.url)} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-xs text-slate-500 hover:text-brand-700"><span className="truncate">{e.url}</span><ExternalLink className="h-3 w-3 shrink-0" /></a>
        </div>
      ),
    },
    {
      key: 'title_length', header: 'SEO title', sortable: true, render: (e) => (
        <div className="w-[230px]">
          <p className={clsx('truncate text-sm', e.meta_title ? 'text-slate-800 dark:text-slate-100' : 'text-slate-500 dark:text-slate-400')} title={e.effective_title}>{e.effective_title}</p>
          <p className="text-[11px] text-slate-400">{e.meta_title ? 'Custom' : 'Automatic'}<LengthChip n={e.title_length} min={30} max={60} /></p>
        </div>
      ),
    },
    {
      key: 'description_length', header: 'Description', sortable: true, render: (e) => (
        <div className="w-[260px]">
          <p className={clsx('line-clamp-2 text-xs', e.meta_description ? 'text-slate-700 dark:text-slate-200' : 'text-slate-500 dark:text-slate-400')} title={e.effective_description}>{e.effective_description || 'No description'}</p>
          {e.description_length > 0 && <p className="text-[11px] text-slate-400">{e.meta_description ? 'Custom' : 'From content'}<LengthChip n={e.description_length} min={70} max={160} /></p>}
        </div>
      ),
    },
    {
      key: 'og', header: 'Image', render: (e) => e.effective_og_image ? <img src={appUrl(e.effective_og_image)} alt="" loading="lazy" className="h-9 w-16 rounded-md object-cover ring-1 ring-slate-200 dark:ring-slate-700" /> : <Badge color="amber">Missing</Badge>,
    },
    {
      key: 'issues', header: 'Issues', render: (e) => {
        const list = e.issues.filter((i) => i !== 'auto_description');
        return <div className="flex w-[190px] flex-wrap gap-1">{list.length ? list.map((i) => <Badge key={i} color={i === 'noindex' ? 'red' : 'amber'}>{labels[i] ?? i}</Badge>) : <span className="text-xs text-emerald-600 dark:text-emerald-400">None</span>}</div>;
      },
    },
    { key: 'score', header: 'Score', sortable: true, align: 'center', render: (e) => <ScorePill score={e.score} /> },
  ];
  return (
    <Card className="overflow-hidden">
      <div className="flex flex-col gap-3 border-b border-slate-100 p-4 dark:border-slate-800 lg:flex-row lg:items-center">
        <SearchInput value={q} onChange={setQ} placeholder={`Search ${label.toLowerCase()}…`} className="w-full lg:max-w-xs" />
        <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
          <Select inputSize="sm" value={issue} onChange={(e) => setIssue(e.target.value)} aria-label="Issue" className="w-full sm:w-auto"
            options={[{ value: '', label: 'All URLs' }, { value: 'any', label: 'With issues' }, { value: 'ok', label: 'No issues' }, ...Object.entries(labels).map(([value, l]) => ({ value, label: l }))]} />
          <Select inputSize="sm" value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status" className="w-full sm:w-auto"
            options={[{ value: '', label: 'Any status' }, { value: 'public', label: 'Public' }, { value: 'hidden', label: 'Draft / hidden' }, { value: 'custom', label: 'Custom SEO' }, { value: 'default', label: 'Automatic SEO' }]} />
          {type === 'route' && canEdit && <Button size="sm" icon={Plus} onClick={() => setAddOpen(true)}>Add route</Button>}
        </div>
      </div>
      {error ? <div className="p-5"><Alert variant="error">{(error as ApiError).message}</Alert></div> : (
        <DataTable<Entry>
          columns={columns}
          rows={data?.rows ?? []}
          rowKey={(e) => e.key}
          loading={isLoading || isFetching}
          sort={sort}
          onSort={setSort}
          caption={`${label} SEO coverage`}
          empty={<EmptyState icon={SearchCheck} title={q || issue || status ? 'No URLs match these filters' : `No ${label.toLowerCase()} yet`} description={q || issue || status ? 'Try clearing the filters.' : 'Published content appears here automatically.'} />}
          actions={(e) => <Button size="xs" variant="secondary" icon={Pencil} onClick={() => onEdit(e)}>{canEdit ? 'Edit SEO' : 'View'}</Button>}
        />
      )}
      {data && data.total > 0 && <Pagination className="border-t border-slate-100 dark:border-slate-800" page={data.page} pages={data.pages} total={data.total} perPage={data.per_page} onPage={setPage} onPerPage={setPerPage} />}
      <Modal open={addOpen} onClose={() => setAddOpen(false)} size="sm" title="Add SEO for a route" description="Any public path, e.g. /scholarships or /admissions-open."
        footer={<><Button variant="secondary" onClick={() => setAddOpen(false)}>Cancel</Button><Button onClick={() => { setAddOpen(false); onAddRoute(route.startsWith('/') ? route : `/${route}`); }} disabled={!/^\/[A-Za-z0-9\-_/.]*$/.test(route.startsWith('/') ? route : `/${route}`)}>Continue</Button></>}>
        <Field label="Path" htmlFor="seo-route" hint="Starts with /. Letters, numbers, dashes and slashes.">
          <Input id="seo-route" value={route} onChange={(e) => setRoute(e.target.value.trim())} prefix={<Globe className="h-4 w-4" />} />
        </Field>
      </Modal>
    </Card>
  );
}

interface Served { sitemap: boolean; robots: boolean }
interface RobotsPayload { content: string; is_custom: boolean; default: string; robots_url: string; sitemap_url: string; served: Served; can: { edit: boolean } }
interface SitemapPayload { urls: { loc: string; path: string; type: string; title: string; lastmod: string | null; changefreq: string; priority: number; pending: boolean }[]; counts: Record<string, number>; live: number; pending: number; sitemap_url: string; served: Served }

function RobotsTab() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const robots = useApi<RobotsPayload>(['seo', 'robots'], 'seo/robots');
  const sitemap = useApi<SitemapPayload>(['seo', 'sitemap'], 'seo/sitemap');
  const [content, setContent] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);
  const [filter, setFilter] = useState('all');
  useEffect(() => {
    if (robots.data) setContent(robots.data.content);
  }, [robots.data]);
  const canEdit = robots.data?.can.edit ?? false;
  const dirty = !!robots.data && content !== robots.data.content;
  const save = async (reset = false) => {
    if (reset && !(await confirm({ title: 'Reset robots.txt?', message: 'The default rules (allow everything except /admin, /api and /storage, plus the sitemap line) will be used.', confirmText: 'Reset', danger: true }))) return;
    setSaving(true);
    setError(null);
    try {
      const res = await api.put<{ content: string; is_custom: boolean }>('seo/robots', reset ? { reset: true } : { content });
      qc.setQueryData(['seo', 'robots'], { ...robots.data, ...res.data });
      void qc.invalidateQueries({ queryKey: ['seo', 'overview'] });
      toast.success(res.message);
    } catch (e) {
      const err = e as ApiError;
      setError(err.errors?.content ?? err.message);
    } finally {
      setSaving(false);
    }
  };
  const urls = (sitemap.data?.urls ?? []).filter((u) => filter === 'all' || (filter === 'pending' ? u.pending : u.type === filter && !u.pending));
  const lines = content.split('\n').length;
  return (
    <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,460px)_minmax(0,1fr)]">
      <Card className="h-fit">
        <CardHeader icon={FileCode2} title="robots.txt" subtitle={robots.data?.is_custom ? 'Custom rules' : 'Default rules'}
          actions={robots.data && (
            <>
              <Button size="sm" variant="ghost" icon={Download} onClick={() => downloadFile('seo/robots-txt')}>Download</Button>
              {robots.data.served.robots && <Button size="sm" variant="ghost" icon={ExternalLink} href={robots.data.robots_url} target="_blank">Open</Button>}
            </>
          )} />
        <div className="card-body space-y-3">
          {robots.error ? <Alert variant="error">{(robots.error as ApiError).message}</Alert> : robots.isLoading ? <Skeleton className="h-64 w-full" /> : (
            <>
              <Field label="Rules" htmlFor="robots-txt" error={error} hint="One directive per line: User-agent, Allow, Disallow, Sitemap, Crawl-delay. Lines starting with # are comments.">
                <Textarea id="robots-txt" rows={Math.min(18, Math.max(10, lines + 1))} value={content} disabled={!canEdit} invalid={!!error} className="font-mono text-xs leading-relaxed" spellCheck={false}
                  onChange={(e) => { setContent(e.target.value); setError(null); }} />
              </Field>
              {canEdit && (
                <div className="flex flex-wrap items-center justify-end gap-2">
                  {robots.data?.is_custom && <Button variant="ghost" icon={RotateCcw} onClick={() => save(true)} className="mr-auto">Reset to default</Button>}
                  {dirty && <Button variant="secondary" onClick={() => setContent(robots.data?.content ?? '')}>Discard</Button>}
                  <Button icon={Save} onClick={() => save(false)} loading={saving} disabled={!dirty}>Save robots.txt</Button>
                </div>
              )}
              {content.includes('Disallow: /\n') || /Disallow:\s*\/\s*$/m.test(content) ? <Alert variant="warning">“Disallow: /” blocks search engines from the whole website.</Alert> : null}
              {robots.data && !robots.data.served.robots && <Alert variant="info">The website does not serve /robots.txt yet (its robots.php is missing) — these rules are saved and used as soon as it does. Use Download to publish the file manually.</Alert>}
            </>
          )}
        </div>
      </Card>
      <Card className="overflow-hidden">
        <CardHeader icon={MapIcon} title="Sitemap preview" subtitle={sitemap.data ? `${formatNumber(sitemap.data.live)} URLs in sitemap.xml${sitemap.data.pending ? ` · ${sitemap.data.pending} waiting for website templates` : ''}` : 'URLs that /sitemap.xml lists'}
          actions={sitemap.data && (
            <>
              <Button size="sm" variant="ghost" icon={Download} onClick={() => downloadFile('seo/sitemap-xml')}>Download</Button>
              {sitemap.data.served.sitemap && <Button size="sm" variant="ghost" icon={ExternalLink} href={sitemap.data.sitemap_url} target="_blank">sitemap.xml</Button>}
            </>
          )} />
        {sitemap.data && !sitemap.data.served.sitemap && (
          <div className="px-4 pt-3"><Alert variant="warning">The website does not serve /sitemap.xml yet (its sitemap.php is missing). Download the generated file to submit it to Google Search Console, or publish it with the website.</Alert></div>
        )}
        <div className="flex flex-wrap gap-1.5 border-b border-slate-100 px-4 py-3 dark:border-slate-800">
          {['all', 'route', 'page', 'program', 'post', 'event', 'notice', 'gallery', 'pending'].map((t) => (
            <button key={t} type="button" onClick={() => setFilter(t)} className={clsx('rounded-full px-3 py-1 text-xs font-semibold transition', filter === t ? 'bg-brand-700 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 dark:bg-slate-800 dark:text-slate-300')}>
              {t === 'all' ? 'All' : t === 'pending' ? 'Pending' : labelize(t)}
              <span className="ml-1 opacity-70">{t === 'all' ? sitemap.data?.live ?? 0 : t === 'pending' ? sitemap.data?.pending ?? 0 : sitemap.data?.counts[t] ?? 0}</span>
            </button>
          ))}
        </div>
        {sitemap.error ? <div className="p-5"><Alert variant="error">{(sitemap.error as ApiError).message}</Alert></div> : sitemap.isLoading ? <div className="p-5"><Skeleton className="h-72 w-full" /></div> : (
          <div className="max-h-[560px] overflow-auto">
            <table className="data-table">
              <caption className="sr-only">Sitemap URLs</caption>
              <thead><tr><th>URL</th><th>Type</th><th>Priority · frequency</th><th className="hidden lg:table-cell">Last modified</th></tr></thead>
              <tbody>
                {urls.map((u) => (
                  <tr key={u.path} className={clsx(u.pending && 'opacity-60')}>
                    <td className="max-w-[360px]">
                      <a href={u.loc} target="_blank" rel="noreferrer" className="block truncate text-sm font-medium text-slate-800 hover:text-brand-700 dark:text-slate-100">{u.path}</a>
                      <span className="block truncate text-xs text-slate-500">{u.title}{u.pending && ' · template pending'}</span>
                    </td>
                    <td><Badge color={u.pending ? 'amber' : 'blue'}>{labelize(u.type)}</Badge></td>
                    <td className="whitespace-nowrap"><span className="font-semibold tabular-nums">{u.priority.toFixed(1)}</span> <span className="text-xs capitalize text-slate-500">· {u.changefreq}</span></td>
                    <td className="hidden whitespace-nowrap text-xs lg:table-cell">{u.lastmod ?? '—'}</td>
                  </tr>
                ))}
                {!urls.length && <tr><td colSpan={4}><EmptyState icon={MapIcon} title="No URLs in this group" /></td></tr>}
              </tbody>
            </table>
          </div>
        )}
        <p className="border-t border-slate-100 px-4 py-2.5 text-xs text-slate-500 dark:border-slate-800">URLs marked noindex or excluded in their SEO settings are left out. <Link to="/cms/seo?tab=routes" className="link">Edit route SEO</Link></p>
      </Card>
    </div>
  );
}
