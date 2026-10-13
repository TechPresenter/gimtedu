import { useState } from 'react';
import { Link } from 'react-router-dom';
import { FileText, Globe, GlobeLock, Pencil, Plus, Quote, Star, StarOff, Trophy } from 'lucide-react';
import { Alert, Avatar, Badge, Button, Card, CardHeader, EmptyState, IconButton, PageHeader, Reveal, Skeleton, Stagger, StatTile, useToast } from '@/components/ui';
import { CrudFormModal } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useApi, useCrudMeta } from '@/lib/queries';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';
import type { Row } from '@/lib/types';
import { storyRenderers } from './alumni/renderers';
import { LinkedCrudTable, statsKey, useRefreshModules } from './alumni/shared';

interface StoryStats {
  kpis: { total: number; published: number; drafts: number; on_website: number; featured: number };
  featured: Row[];
  max_featured: number;
}

export default function AlumniStoriesPage() {
  const { can } = useAuth();
  const toast = useToast();
  const refresh = useRefreshModules();
  const { data: meta } = useCrudMeta('alumni_success_stories');
  const { data, isLoading, error } = useApi<StoryStats>(statsKey('alumni_success_stories'), 'alumni/stories/stats');
  const [formId, setFormId] = useState<number | null | 'new'>(null);
  const k = data?.kpis;
  const canPublish = can('alumni', 'publish');
  const canEdit = can('alumni', 'edit');

  const publish = async (row: Row, on: boolean) => {
    try {
      const res = await api.post(`alumni/stories/${row.id}/publish`, { publish: on });
      toast.success(res.message);
      await refresh('alumni_success_stories');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const feature = async (row: Row, on: boolean) => {
    try {
      const res = await api.post(`alumni/stories/${row.id}/feature`, { featured: on });
      toast.success(res.message);
      await refresh('alumni_success_stories');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  return (
    <>
      <PageHeader
        title="Success Stories"
        description="Celebrate alumni achievements — feature them in the admin and publish them to the website."
        breadcrumbs={[{ label: 'Alumni', to: '/alumni' }, { label: 'Success stories' }]}
        actions={can('alumni', 'create') && <Button icon={Plus} onClick={() => setFormId('new')}>Add story</Button>}
      />
      {error ? <Alert variant="error" title="Unable to load story statistics" className="mb-6">{(error as Error).message}</Alert> : null}

      <Stagger className="mb-6 grid grid-cols-2 gap-3 xl:grid-cols-4" step={50}>
        {[
          <StatTile key="p" label="Published stories" icon={Trophy} tone="green" loading={isLoading} value={k?.published ?? 0} sub={k ? `${k.total} stories in total` : undefined} />,
          <StatTile key="w" label="On the website" icon={Globe} tone="blue" loading={isLoading} value={k?.on_website ?? 0} sub="Visible to visitors" />,
          <StatTile key="f" label="Featured" icon={Star} tone="amber" loading={isLoading} value={k ? `${k.featured} / ${data?.max_featured ?? 6}` : 0} sub="Highlighted first" />,
          <StatTile key="d" label="Drafts" icon={FileText} tone="slate" loading={isLoading} value={k?.drafts ?? 0} sub="Not yet published" />,
        ]}
      </Stagger>

      <Reveal>
        <Card className="mb-6">
          <CardHeader title="Featured stories" subtitle="Shown first on the website and in alumni newsletters" icon={Star} />
          <div className="card-body !pt-0">
            {isLoading || !data ? (
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">{[0, 1, 2].map((i) => <Skeleton key={i} className="h-72" />)}</div>
            ) : data.featured.length === 0 ? (
              <EmptyState icon={Star} title="No featured stories" description="Feature up to six stories from the table below to highlight them." className="py-8" />
            ) : (
              <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                {data.featured.map((s) => (
                  <article key={String(s.id)} className="group flex flex-col overflow-hidden rounded-2xl border border-slate-200/80 bg-white transition hover:-translate-y-0.5 hover:shadow-soft dark:border-slate-800 dark:bg-slate-900">
                    <div className="relative h-36 overflow-hidden bg-brand-900">
                      {s.image ? <img src={appUrl(String(s.image))} alt="" loading="lazy" className="h-full w-full object-cover transition duration-500 group-hover:scale-105" /> : <Trophy className="absolute inset-0 m-auto h-10 w-10 text-white/30" />}
                      <div className="absolute inset-0 bg-gradient-to-t from-brand-950/70 to-transparent" />
                      <div className="absolute right-3 top-3 flex gap-1.5">
                        {Number(s.show_on_website) === 1 && s.status === 'published' ? <Badge color="green"><Globe className="h-3 w-3" /> On website</Badge> : <Badge color="amber">{s.status === 'published' ? 'Not on website' : 'Draft'}</Badge>}
                      </div>
                      <h3 className="absolute bottom-3 left-4 right-4 line-clamp-2 font-display text-base font-bold text-white">{String(s.title)}</h3>
                    </div>
                    <div className="flex flex-1 flex-col p-4">
                      {s.excerpt && (
                        <p className="relative mb-4 line-clamp-3 pl-6 text-sm italic text-slate-600 dark:text-slate-300">
                          <Quote className="absolute left-0 top-0.5 h-4 w-4 text-accent-500" aria-hidden />
                          {String(s.excerpt)}
                        </p>
                      )}
                      <div className="mt-auto flex items-center gap-3">
                        <Avatar name={String(s.alumni_name)} src={s.alumni_photo as string | null} size="sm" />
                        <div className="min-w-0 flex-1">
                          <Link to={`/alumni/${s.alumni_id}`} className="block truncate text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white">{String(s.alumni_name)}</Link>
                          <p className="truncate text-xs text-slate-500">{[s.designation, s.company].filter(Boolean).join(' · ')}{s.program_short ? ` · ${s.program_short} ${s.batch_year}` : ''}</p>
                        </div>
                        {canEdit && <IconButton size="sm" icon={Pencil} label="Edit story" onClick={() => setFormId(Number(s.id))} />}
                        {canEdit && <IconButton size="sm" icon={StarOff} label="Remove from featured" onClick={() => feature(s, false)} />}
                      </div>
                    </div>
                  </article>
                ))}
              </div>
            )}
          </div>
        </Card>
      </Reveal>

      <LinkedCrudTable
        module="alumni_success_stories"
        title="All success stories"
        addLabel="Add story"
        emptyTitle="No success stories yet"
        emptyText="Write about alumni achievements to inspire students and showcase GIMT on the website."
        renderers={storyRenderers}
        rowMenu={(r) => [
          canPublish && !(Number(r.show_on_website) === 1 && r.status === 'published') && { label: 'Publish to website', icon: Globe, onClick: () => publish(r, true) },
          canPublish && Number(r.show_on_website) === 1 && r.status === 'published' && { label: 'Remove from website', icon: GlobeLock, onClick: () => publish(r, false) },
          canEdit && !Number(r.is_featured) && { label: 'Feature story', icon: Star, onClick: () => feature(r, true) },
          canEdit && !!Number(r.is_featured) && { label: 'Remove from featured', icon: StarOff, onClick: () => feature(r, false) },
        ]}
      />

      <CrudFormModal open={formId !== null} onClose={() => setFormId(null)} meta={meta} module="alumni_success_stories" id={formId === 'new' ? null : formId} />
    </>
  );
}
