import { useMemo } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  BarChart3, CalendarClock, CheckCircle2, Copy, ExternalLink, Eye, FileEdit, FolderTree, ListOrdered, Newspaper, Plus, Search, Send, Star, StarOff, TrendingUp, Undo2,
} from 'lucide-react';
import {
  Button, Card, CardBody, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, Stagger, StatCard, StatusBadge, Tabs, useToast,
} from '@/components/ui';
import { BarChart } from '@/components/charts';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import { formatDate, formatDateTime, formatNumber, timeAgo } from '@/lib/format';
import { useApi } from '@/lib/queries';
import type { Row } from '@/lib/types';
import { mediaSrc } from './content/media';
import type { BlogSummary } from './content/types';

type Tab = 'posts' | 'categories';
const TYPE_LABEL: Record<string, string> = { news: 'News', blog: 'Article', press: 'Press release', achievement: 'Achievement' };

/** Blog / News: KPIs, publishing insights, posts list (full-page editor) and categories (?tab=posts|categories). */
export default function BlogPage() {
  const [params, setParams] = useSearchParams();
  const tab: Tab = params.get('tab') === 'categories' ? 'categories' : 'posts';
  const navigate = useNavigate();
  const toast = useToast();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canPublish = can('blog', 'publish');
  const summary = useApi<BlogSummary>(['blog-summary'], 'blog/summary');
  const s = summary.data;

  const setTab = (t: string, extra: Record<string, string> = {}) => {
    const next = new URLSearchParams();
    if (t !== 'posts') next.set('tab', t);
    Object.entries(extra).forEach(([k, v]) => next.set(k, v));
    setParams(next, { replace: true });
  };

  const refresh = () =>
    Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'blog_posts'] }), qc.invalidateQueries({ queryKey: ['blog-summary'] }), qc.invalidateQueries({ queryKey: ['crud', 'blog_categories'] })]);
  const act = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      toast.success(res.message);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const monthTrend = s ? (s.last_month ? Math.round(((s.this_month - s.last_month) / s.last_month) * 100) : null) : null;
  const chart = useMemo(() => s?.monthly ?? [], [s]);

  return (
    <>
      <PageHeader
        title="Blog / News"
        description="Publish news, articles, press releases and achievements on the website — with categories, scheduling and SEO."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Blog / News' }]}
        actions={
          <>
            <Button variant="secondary" icon={ExternalLink} href={appUrl('blog')} target="_blank">
              View blog
            </Button>
            {can('blog', 'create') && (
              <Button icon={Plus} to="/cms/blog/new">
                New post
              </Button>
            )}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={60}>
        {[
          <StatCard key="t" label="Total posts" icon={Newspaper} tone="navy" loading={summary.isLoading} value={s?.total ?? 0} hint={s ? `${s.categories_count} active categories` : undefined} />,
          <StatCard
            key="p"
            label="Published"
            icon={CheckCircle2}
            tone="green"
            loading={summary.isLoading}
            value={s?.published ?? 0}
            trend={s ? { value: `${s.this_month} this month`, dir: monthTrend === null ? 'flat' : monthTrend >= 0 ? 'up' : 'down', label: monthTrend === null ? '' : `vs ${s.last_month} last month` } : undefined}
          />,
          <StatCard key="d" label="Drafts & scheduled" icon={CalendarClock} tone="amber" loading={summary.isLoading} value={(s?.draft ?? 0) + (s?.scheduled ?? 0)} hint={s ? `${s.draft} drafts · ${s.scheduled} scheduled` : undefined} />,
          <StatCard key="v" label="Total views" icon={Eye} tone="blue" loading={summary.isLoading} value={s?.views ?? 0} hint={s ? (s.seo_missing ? `${s.seo_missing} live posts need SEO` : 'SEO complete on live posts') : undefined} />,
        ]}
      </Stagger>

      <Tabs
        className="mb-5"
        value={tab}
        onChange={(k) => setTab(k)}
        tabs={[
          { key: 'posts', label: 'Posts', icon: Newspaper, count: s?.total },
          { key: 'categories', label: 'Categories', icon: FolderTree, count: s?.categories_count },
        ]}
      />

      {tab === 'posts' ? (
        <>
          <Reveal className="mb-6 grid grid-cols-1 items-start gap-6 xl:grid-cols-3">
            <Card className="min-w-0 xl:col-span-2">
              <CardHeader title="Publishing activity" subtitle="Posts published per month (last 12 months)" icon={BarChart3} />
              <CardBody>
                {summary.isLoading ? (
                  <Skeleton className="h-[270px] w-full" />
                ) : chart.some((m) => m.posts > 0) ? (
                  <BarChart labels={chart.map((m) => m.label)} series={[{ label: 'Posts published', data: chart.map((m) => m.posts), color: '#183C7A' }]} height={270} legend={false} />
                ) : (
                  <EmptyState icon={BarChart3} title="Nothing published yet" description="Published posts will appear here month by month." className="!py-10" />
                )}
              </CardBody>
            </Card>
            <Card>
              <CardHeader title="Most read" subtitle="Top posts by views" icon={TrendingUp} />
              <CardBody className="!pt-1">
                {summary.isLoading ? (
                  <div className="space-y-3">
                    {Array.from({ length: 5 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}
                  </div>
                ) : s && s.top_posts.length ? (
                  <ol className="divide-y divide-slate-100 dark:divide-slate-800">
                    {s.top_posts.map((p, i) => (
                      <li key={p.id}>
                        <button type="button" onClick={() => navigate(`/cms/blog/${p.id}`)} className="group flex w-full items-center gap-3 py-2.5 text-left">
                          <span className="w-4 shrink-0 text-center text-xs font-bold text-slate-400">{i + 1}</span>
                          {p.thumbnail ? <img src={mediaSrc(p.thumbnail)} alt="" className="h-9 w-12 shrink-0 rounded-md object-cover" loading="lazy" /> : <span className="h-9 w-12 shrink-0 rounded-md bg-slate-100 dark:bg-slate-800" />}
                          <span className="min-w-0 flex-1">
                            <span className="line-clamp-1 text-sm font-medium text-slate-800 group-hover:text-brand-700 dark:text-slate-100 dark:group-hover:text-brand-300">{p.title}</span>
                            <span className="block text-xs text-slate-500">{p.category_name ?? 'Uncategorised'} · {formatDate(p.published_at)}</span>
                          </span>
                          <span className="shrink-0 text-xs font-semibold tabular-nums text-slate-600 dark:text-slate-300">{formatNumber(p.views)}</span>
                        </button>
                      </li>
                    ))}
                  </ol>
                ) : (
                  <p className="py-8 text-center text-sm text-slate-500">No published posts yet.</p>
                )}
                {s && s.upcoming.length > 0 && (
                  <div className="mt-3 rounded-xl bg-amber-50/70 p-3 dark:bg-amber-500/10">
                    <p className="mb-1.5 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-amber-800 dark:text-amber-300">
                      <CalendarClock className="h-3.5 w-3.5" /> Scheduled
                    </p>
                    {s.upcoming.map((u) => (
                      <button key={u.id} type="button" onClick={() => navigate(`/cms/blog/${u.id}`)} className="flex w-full items-center justify-between gap-2 py-1 text-left text-xs">
                        <span className="truncate font-medium text-slate-700 hover:text-brand-700 dark:text-slate-200">{u.title}</span>
                        <span className="shrink-0 text-slate-500">{formatDateTime(u.published_at)}</span>
                      </button>
                    ))}
                  </div>
                )}
              </CardBody>
            </Card>
          </Reveal>

          <CrudTable
            key="posts"
            module="blog_posts"
            urlState
            addLabel="New post"
            onCreate={() => navigate('/cms/blog/new')}
            onEdit={(r) => navigate(`/cms/blog/${r.id}`)}
            onView={(r) => navigate(`/cms/blog/${r.id}`)}
            hideColumns={['thumbnail']}
            emptyTitle="No posts yet"
            emptyText="Write the first news story or blog article for the website."
            renderers={{
              title: (r: Row) => (
                <button type="button" onClick={() => navigate(`/cms/blog/${r.id}`)} className="group flex min-w-[15rem] max-w-sm items-center gap-3 text-left">
                  {r.thumbnail ? (
                    <img src={mediaSrc(String(r.thumbnail))} alt="" loading="lazy" className="h-11 w-16 shrink-0 rounded-lg object-cover ring-1 ring-slate-200 dark:ring-slate-700" />
                  ) : (
                    <span className="flex h-11 w-16 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-400 dark:bg-slate-800"><Newspaper className="h-4 w-4" /></span>
                  )}
                  <span className="min-w-0">
                    <span className="flex items-center gap-1.5">
                      {!!Number(r.is_featured) && <Star className="h-3.5 w-3.5 shrink-0 fill-amber-400 text-amber-400" aria-label="Featured" />}
                      <span className="line-clamp-2 font-semibold leading-snug text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300">{String(r.title)}</span>
                    </span>
                    <span className="block truncate text-xs text-slate-500 dark:text-slate-400">
                      {r.category_name ? String(r.category_name) : 'Uncategorised'} · {TYPE_LABEL[String(r.type)] ?? String(r.type)}
                    </span>
                  </span>
                </button>
              ),
              status: (r: Row) => (
                <div className="leading-tight">
                  <StatusBadge status={String(r.status)} />
                  {r.status === 'scheduled' && r.published_at && <div className="mt-1 whitespace-nowrap text-[11px] text-slate-500">Goes live {formatDateTime(r.published_at)}</div>}
                  {r.status === 'draft' && r.updated_at && <div className="mt-1 text-[11px] text-slate-500">Edited {timeAgo(r.updated_at)}</div>}
                </div>
              ),
              published_at: (r: Row) => (r.published_at && r.status !== 'draft' ? <span className="whitespace-nowrap">{formatDate(r.published_at)}</span> : <span className="text-slate-400">—</span>),
              seo_score: (r: Row) => (
                <span className={clsx('inline-flex items-center gap-1 text-xs font-semibold', Number(r.seo_score) >= 2 ? 'text-emerald-600' : Number(r.seo_score) === 1 ? 'text-amber-600' : 'text-slate-400')}>
                  <Search className="h-3.5 w-3.5" /> {Number(r.seo_score) >= 2 ? 'Complete' : Number(r.seo_score) === 1 ? 'Partial' : 'Missing'}
                </span>
              ),
            }}
            rowMenu={(r: Row) => [
              { label: r.status === 'published' ? 'View on website' : 'Preview', icon: ExternalLink, href: appUrl(`blog/${r.slug}`), target: '_blank' },
              canPublish && r.status !== 'published' && { label: 'Publish now', icon: Send, onClick: () => void act(() => api.post(`blog/posts/${r.id}/publish`)) },
              canPublish && r.status !== 'draft' && { label: 'Unpublish (move to draft)', icon: Undo2, onClick: () => void act(() => api.post(`blog/posts/${r.id}/unpublish`)) },
              can('blog', 'edit') && { label: Number(r.is_featured) ? 'Remove from featured' : 'Mark as featured', icon: Number(r.is_featured) ? StarOff : Star, onClick: () => void act(() => api.post(`blog/posts/${r.id}/feature`)) },
              can('blog', 'create') && {
                label: 'Duplicate as draft',
                icon: Copy,
                onClick: () =>
                  void act(async () => {
                    const res = await api.post<{ id: number }>(`blog/posts/${r.id}/duplicate`);
                    navigate(`/cms/blog/${res.data.id}`);
                    return res;
                  }),
              },
              { label: 'Edit SEO', icon: Search, onClick: () => navigate(`/cms/blog/${r.id}#seo`) },
            ]}
            bulkActions={[
              ...(canPublish
                ? [
                    { label: 'Publish', icon: Send, onClick: (ids: number[], clear: () => void) => void act(async () => { const res = await api.post('crud/blog_posts/bulk', { action: 'publish', ids }); clear(); return res; }) },
                    { label: 'Move to draft', icon: FileEdit, onClick: (ids: number[], clear: () => void) => void act(async () => { const res = await api.post('crud/blog_posts/bulk', { action: 'draft', ids }); clear(); return res; }) },
                  ]
                : []),
              ...(can('blog', 'edit')
                ? [
                    { label: 'Feature', icon: Star, onClick: (ids: number[], clear: () => void) => void act(async () => { const res = await api.post('crud/blog_posts/bulk', { action: 'feature', ids }); clear(); return res; }) },
                    { label: 'Remove from featured', icon: StarOff, onClick: (ids: number[], clear: () => void) => void act(async () => { const res = await api.post('crud/blog_posts/bulk', { action: 'unfeature', ids }); clear(); return res; }) },
                  ]
                : []),
            ]}
          />
        </>
      ) : (
        <>
          <Reveal className="mb-6">
            <Card>
              <CardHeader
                title="Posts by category"
                subtitle="Published vs. draft / scheduled posts in each category"
                icon={FolderTree}
                actions={
                  <div className="hidden items-center gap-4 text-xs text-slate-500 sm:flex">
                    <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-accent-500" /> Published</span>
                    <span className="inline-flex items-center gap-1.5"><span className="h-2 w-2 rounded-full bg-amber-300" /> Draft / scheduled</span>
                  </div>
                }
              />
              <CardBody>
                {summary.isLoading ? (
                  <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-14 w-full" />)}</div>
                ) : s && s.by_category.length ? (
                  <ul className="grid gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-6">
                    {s.by_category.map((c) => {
                      const max = Math.max(...s.by_category.map((x) => x.posts), 1);
                      return (
                        <li key={c.name}>
                          <div className="mb-1.5 flex items-baseline justify-between gap-2 text-sm">
                            <span className="truncate font-medium text-slate-700 dark:text-slate-200">{c.name}</span>
                            <span className="shrink-0 text-xs tabular-nums text-slate-500"><strong className="text-slate-800 dark:text-slate-100">{c.published}</strong> / {c.posts}</span>
                          </div>
                          <div className="flex h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="img" aria-label={`${c.name}: ${c.published} published of ${c.posts}`}>
                            <div className="h-full bg-accent-500" style={{ width: `${(c.published / max) * 100}%` }} />
                            <div className="h-full bg-amber-300 dark:bg-amber-500/60" style={{ width: `${((c.posts - c.published) / max) * 100}%` }} />
                          </div>
                        </li>
                      );
                    })}
                  </ul>
                ) : (
                  <p className="py-4 text-center text-sm text-slate-500">No posts yet — categories fill up as you publish.</p>
                )}
              </CardBody>
            </Card>
          </Reveal>
          <CrudTable
            key="categories"
            module="blog_categories"
            urlState
            addLabel="Add category"
            emptyTitle="No categories yet"
            emptyText="Create categories such as Admissions, Campus Life or Placements to organise posts."
            rowMenu={(r: Row) => [{ label: `View posts (${formatNumber(r.posts_count)})`, icon: ListOrdered, onClick: () => setTab('posts', { 'f.category_id': String(r.id) }) }]}
            onSaved={() => void qc.invalidateQueries({ queryKey: ['blog-summary'] })}
          />
        </>
      )}
    </>
  );
}
