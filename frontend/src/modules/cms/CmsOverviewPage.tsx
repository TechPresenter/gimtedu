import { Link } from 'react-router-dom';
import clsx from 'clsx';
import {
  Activity, ArrowRight, BookOpenText, ExternalLink, FileText, FolderOpen, Globe, Images, LayoutTemplate, ListTree, Megaphone, MenuSquare, Newspaper, PenLine, Plus, SearchCheck,
  Upload, type LucideIcon,
} from 'lucide-react';
import { Alert, Button, Card, CardHeader, EmptyState, PageHeader, Reveal, Skeleton, Stagger, StatCard, StatusBadge, Timeline, toneClasses, type Tone } from '@/components/ui';
import { BarChart, DoughnutChart } from '@/components/charts';
import { useApi } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { formatNumber, humanFileSize, labelize, timeAgo } from '@/lib/format';

interface Overview {
  pages: { total: number; published: number; draft: number; system_pages: number; with_blocks: number; scheduled: number };
  posts: { total: number; published: number; draft: number; scheduled: number };
  media: { total: number; bytes: number; images: number };
  banners: { total: number; live: number; scheduled: number; expired: number; hero_live: number };
  menus: { locations: number; configured: number; items: number };
  sections: { total: number; enabled: number };
  announcements_live: number;
  seo: { score: number; coverage: number; total: number; custom: number; issues: Record<string, number>; issue_labels: Record<string, string>; sitemap_urls: number; noindex: number };
  recent: { type: string; id: number; title: string; status: string; updated_at: string; to: string }[];
  activity: { id: number; action: string; module: string; description: string; created_at: string; user_name: string | null }[];
  weekly: { label: string; cms: number; blog: number; media: number; seo: number }[];
  site_url: string;
  can: { edit: boolean; create: boolean; seo: boolean; blog: boolean; media: boolean };
}

const typeIcon: Record<string, { icon: LucideIcon; tone: Tone; label: string }> = {
  page: { icon: FileText, tone: 'navy', label: 'Page' },
  post: { icon: Newspaper, tone: 'blue', label: 'Blog post' },
  banner: { icon: Images, tone: 'orange', label: 'Banner' },
  section: { icon: LayoutTemplate, tone: 'purple', label: 'Home section' },
};
const actionTone: Record<string, Tone> = { create: 'green', update: 'blue', delete: 'red', publish: 'purple', unpublish: 'amber', import: 'cyan', export: 'slate' };

/** Website CMS dashboard: content status, SEO health, recent changes and shortcuts. */
export default function CmsOverviewPage() {
  const { can } = useAuth();
  const { data: d, isLoading, error } = useApi<Overview>(['cms', 'overview'], 'cms/overview');
  const issues = d ? Object.entries(d.seo.issues).filter(([k, v]) => v > 0 && !['auto_description', 'noindex'].includes(k)).sort((a, b) => b[1] - a[1]) : [];
  const quick: { label: string; icon: LucideIcon; tone: Tone; to: string; show: boolean }[] = [
    { label: 'New page', icon: Plus, tone: 'navy', to: '/cms/pages?add=1', show: can('cms', 'create') },
    { label: 'Edit home page', icon: LayoutTemplate, tone: 'orange', to: '/cms/homepage', show: can('cms', 'view') },
    { label: 'Edit menus', icon: ListTree, tone: 'blue', to: '/cms/menus', show: can('cms', 'view') },
    { label: 'Hero slides', icon: Images, tone: 'purple', to: '/cms/banners', show: can('cms', 'view') },
    { label: 'Announcement', icon: Megaphone, tone: 'amber', to: '/cms/announcements', show: can('cms', 'view') },
    { label: 'Write a post', icon: PenLine, tone: 'green', to: '/cms/blog', show: can('blog', 'view') },
    { label: 'Upload media', icon: Upload, tone: 'cyan', to: '/cms/media', show: can('media', 'view') },
    { label: 'SEO audit', icon: SearchCheck, tone: 'pink', to: '/cms/seo', show: can('seo', 'view') },
  ];

  return (
    <>
      <PageHeader
        title="Website CMS"
        description="Everything that appears on the public website — pages, menus, home page, banners, announcements and SEO."
        breadcrumbs={[{ label: 'Website CMS' }]}
        actions={
          <>
            <Button variant="secondary" icon={ExternalLink} href={d?.site_url ?? '/'} target="_blank">View website</Button>
            {can('cms', 'create') && <Button icon={Plus} to="/cms/pages?add=1">New page</Button>}
          </>
        }
      />
      {error && <Alert variant="error" className="mb-6" title="Unable to load the CMS overview">{(error as Error).message}</Alert>}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6" step={50}>
        {[
          <StatCard key="pp" label="Published pages" value={d?.pages.published ?? 0} icon={FileText} tone="navy" loading={isLoading} hint={d ? `${d.pages.draft} drafts · ${d.pages.with_blocks} use the builder` : undefined} to="/cms/pages?f.status=published" />,
          <StatCard key="pd" label="Draft pages" value={d?.pages.draft ?? 0} icon={PenLine} tone="amber" loading={isLoading} hint="Not visible to visitors" to="/cms/pages?f.status=draft" />,
          <StatCard key="po" label="Blog & news posts" value={d?.posts.published ?? 0} icon={BookOpenText} tone="blue" loading={isLoading} hint={d ? `${d.posts.draft} drafts · ${d.posts.scheduled} scheduled` : undefined} to={can('blog', 'view') ? '/cms/blog' : undefined} />,
          <StatCard key="me" label="Media files" value={d?.media.total ?? 0} icon={FolderOpen} tone="cyan" loading={isLoading} hint={d ? `${humanFileSize(d.media.bytes)} · ${formatNumber(d.media.images)} images` : undefined} to={can('media', 'view') ? '/cms/media' : undefined} />,
          <StatCard key="bn" label="Live banners" value={d?.banners.live ?? 0} icon={Images} tone="orange" loading={isLoading} hint={d ? `${d.banners.hero_live} hero slides · ${d.banners.scheduled} scheduled` : undefined} to="/cms/banners" />,
          <StatCard key="mn" label="Menu links" value={d?.menus.items ?? 0} icon={MenuSquare} tone="purple" loading={isLoading} hint={d ? `${d.menus.configured} of ${d.menus.locations} locations set up` : undefined} to="/cms/menus" />,
        ]}
      </Stagger>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal>
          <Card className="h-full">
            <CardHeader title="SEO health" subtitle="Search titles, descriptions and share images" icon={SearchCheck}
              actions={can('seo', 'view') && <Button size="sm" variant="ghost" iconRight={ArrowRight} to="/cms/seo">Open</Button>} />
            <div className="card-body">
              {isLoading || !d ? <Skeleton className="h-56 w-full" /> : (
                <>
                  <div className="flex items-center gap-5">
                    <ScoreRing value={d.seo.score} />
                    <div className="min-w-0 space-y-1 text-sm">
                      <p className="text-slate-600 dark:text-slate-300"><span className="font-semibold text-slate-900 dark:text-white">{d.seo.coverage}%</span> of public URLs have custom SEO</p>
                      <p className="text-slate-600 dark:text-slate-300"><span className="font-semibold text-slate-900 dark:text-white">{formatNumber(d.seo.sitemap_urls)}</span> URLs in the sitemap</p>
                      {d.seo.noindex > 0 && <p className="text-amber-600">{d.seo.noindex} URL{d.seo.noindex === 1 ? '' : 's'} hidden from search</p>}
                    </div>
                  </div>
                  <ul className="mt-5 space-y-2">
                    {issues.slice(0, 4).map(([k, v]) => (
                      <li key={k}>
                        <Link to={`/cms/seo?tab=overview`} className="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-2 text-sm transition hover:bg-slate-100 dark:bg-slate-800/50 dark:hover:bg-slate-800">
                          <span className="text-slate-700 dark:text-slate-200">{d.seo.issue_labels[k]}</span>
                          <span className="font-semibold tabular-nums text-amber-600">{v}</span>
                        </Link>
                      </li>
                    ))}
                    {!issues.length && <li className="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">No SEO issues on public URLs.</li>}
                  </ul>
                </>
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={70}>
          <Card className="h-full">
            <CardHeader title="Content status" subtitle="Pages and posts by status" icon={Globe} />
            <div className="card-body">
              {isLoading || !d ? <Skeleton className="h-56 w-full" /> : (
                <DoughnutChart height={170} valueFormat="number" centerValue={formatNumber(d.pages.total + d.posts.total)} centerLabel="items"
                  labels={['Live pages', 'Draft pages', 'Live posts', 'Draft posts', 'Scheduled']}
                  data={[d.pages.published, d.pages.draft, d.posts.published, d.posts.draft, d.posts.scheduled]}
                  colors={['#1D4ED8', '#F59E0B', '#22943F', '#94A3B8', '#8B5CF6']} />
              )}
            </div>
          </Card>
        </Reveal>
        <Reveal delay={140}>
          <Card className="h-full">
            <CardHeader title="Content updates" subtitle="Changes per week across the website modules" icon={Activity} />
            <div className="card-body">
              {isLoading || !d ? <Skeleton className="h-56 w-full" /> : (
                <BarChart height={220} stacked labels={d.weekly.map((w) => w.label)}
                  series={[
                    { label: 'Pages & CMS', data: d.weekly.map((w) => w.cms), color: '#1D4ED8' },
                    { label: 'Blog', data: d.weekly.map((w) => w.blog), color: '#22943F' },
                    { label: 'Media', data: d.weekly.map((w) => w.media), color: '#06B6D4' },
                    { label: 'SEO', data: d.weekly.map((w) => w.seo), color: '#D03E16' },
                  ]} />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <div className="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <Reveal className="xl:col-span-2">
          <Card className="h-full">
            <CardHeader title="Recently updated content" subtitle="Pages, posts, banners and home page sections" icon={FileText} />
            {isLoading || !d ? <div className="space-y-3 p-5">{Array.from({ length: 6 }).map((_, i) => <Skeleton key={i} className="h-10 w-full" />)}</div> : d.recent.length === 0 ? (
              <EmptyState icon={FileText} title="No content yet" description="Create the first page of the website." action={can('cms', 'create') && <Button icon={Plus} to="/cms/pages?add=1">New page</Button>} />
            ) : (
              <ul className="divide-y divide-slate-100 dark:divide-slate-800">
                {d.recent.map((r) => {
                  const t = typeIcon[r.type] ?? typeIcon.page;
                  return (
                    <li key={`${r.type}-${r.id}`}>
                      <Link to={r.to} className="flex items-center gap-3 px-5 py-3 transition hover:bg-slate-50 dark:hover:bg-slate-800/40">
                        <span className={clsx('kpi-icon !h-9 !w-9 shrink-0', toneClasses[t.tone].icon)}><t.icon className="h-4 w-4" /></span>
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-semibold text-slate-900 dark:text-white">{r.title}</span>
                          <span className="text-xs text-slate-500">{t.label} · {timeAgo(r.updated_at)}</span>
                        </span>
                        <StatusBadge status={r.status} />
                      </Link>
                    </li>
                  );
                })}
              </ul>
            )}
          </Card>
        </Reveal>
        <Reveal delay={80}>
          <Card className="h-full">
            <CardHeader title="Recent activity" subtitle="Website changes by your team" icon={Activity} />
            <div className="card-body">
              {isLoading || !d ? <Skeleton className="h-64 w-full" /> : (
                <Timeline empty="No website changes recorded yet."
                  items={d.activity.map((a) => ({ title: a.description || `${labelize(a.action)} ${a.module}`, description: a.user_name ?? 'System', time: timeAgo(a.created_at), tone: actionTone[a.action] ?? 'slate' }))} />
              )}
            </div>
          </Card>
        </Reveal>
      </div>

      <Reveal>
        <Card>
          <CardHeader title="Quick actions" icon={Plus} />
          <div className="grid grid-cols-2 gap-3 p-5 pt-0 sm:grid-cols-4 xl:grid-cols-8">
            {quick.filter((q) => q.show).map((q) => (
              <Link key={q.label} to={q.to} className="group flex flex-col items-center gap-2 rounded-2xl border border-slate-200 p-4 text-center text-sm font-medium text-slate-700 transition hover:-translate-y-0.5 hover:border-brand-200 hover:shadow-soft dark:border-slate-800 dark:text-slate-200">
                <span className={clsx('kpi-icon transition group-hover:scale-105', toneClasses[q.tone].icon)}><q.icon className="h-5 w-5" /></span>
                {q.label}
              </Link>
            ))}
          </div>
        </Card>
      </Reveal>
    </>
  );
}

/** Circular score indicator (0–100). */
function ScoreRing({ value }: { value: number }) {
  const r = 38;
  const c = 2 * Math.PI * r;
  const color = value >= 90 ? '#10B981' : value >= 70 ? '#F59E0B' : '#EF4444';
  return (
    <div className="relative h-24 w-24 shrink-0">
      <svg viewBox="0 0 96 96" className="h-24 w-24 -rotate-90" aria-hidden>
        <circle cx="48" cy="48" r={r} fill="none" strokeWidth="9" className="stroke-slate-100 dark:stroke-slate-800" />
        <circle cx="48" cy="48" r={r} fill="none" strokeWidth="9" stroke={color} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - value / 100)} className="transition-[stroke-dashoffset] duration-700" />
      </svg>
      <div className="absolute inset-0 flex flex-col items-center justify-center" role="img" aria-label={`SEO score ${value} out of 100`}>
        <span className="font-display text-2xl font-bold text-slate-900 dark:text-white">{value}</span>
        <span className="text-[10px] uppercase tracking-wider text-slate-500">/ 100</span>
      </div>
    </div>
  );
}
