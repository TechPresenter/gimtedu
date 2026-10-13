import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  CalendarDays, EyeOff, FilterX, FolderOpen, GalleryHorizontalEnd, ImageOff, Images, LayoutGrid, MoreHorizontal, Pencil, Plus, Rows3, Send, Trash2, Video, X,
} from 'lucide-react';
import {
  Alert, Button, Card, Dropdown, EmptyState, PageHeader, Pagination, SearchInput, Select, Stagger, StatCard, StatusBadge, Tabs, useConfirm, useToast,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, labelize } from '@/lib/format';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { useApi, useCrudList } from '@/lib/queries';
import type { Row } from '@/lib/types';
import { ContentFormModal } from './content/ContentFormModal';
import { MediaField } from './content/MediaPicker';
import { mediaSrc } from './content/media';
import type { GalleryStats } from './content/types';

const CATEGORIES = [
  { value: 'campus', label: 'Campus' },
  { value: 'events', label: 'Events' },
  { value: 'cultural', label: 'Cultural' },
  { value: 'sports', label: 'Sports' },
  { value: 'convocation', label: 'Convocation' },
  { value: 'labs', label: 'Labs & Facilities' },
  { value: 'placements', label: 'Placements' },
  { value: 'academics', label: 'Academics' },
];
const catLabel = (v: unknown) => CATEGORIES.find((c) => c.value === v)?.label ?? labelize(String(v ?? 'campus'));

/** Gallery: albums grid / table, create & edit (cover from the media library), publish toggle; items managed per album. */
export default function GalleryPage() {
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { can } = useAuth();
  const [view, setView] = useLocalStorage<'grid' | 'table'>('gimt.gallery.view', 'grid');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [page, setPage] = useState(1);
  const [form, setForm] = useState<{ open: boolean; id: number | null }>({ open: false, id: null });

  const stats = useApi<GalleryStats>(['gallery-stats'], 'gallery/stats');
  const st = stats.data;
  const list = useCrudList('galleries', { page, per_page: 12, q: dq || undefined, f: { category: category || undefined, status: status || undefined } }, view === 'grid');
  const rows = list.data?.rows ?? [];
  const filtered = !!(dq || category || status);
  useEffect(() => setPage(1), [dq, category, status]);

  const refresh = () => Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'galleries'] }), qc.invalidateQueries({ queryKey: ['gallery-stats'] })]);
  const setAlbumStatus = async (r: Row, next: 'published' | 'draft') => {
    try {
      await api.post(`crud/galleries/${r.id}`, { status: next });
      toast.success(next === 'published' ? `“${r.title}” is now visible on the website.` : `“${r.title}” is hidden from the website.`);
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const remove = async (r: Row) => {
    const ok = await confirm({ title: 'Delete this album?', message: <>“{String(r.title)}” and its {Number(r.items_count)} item(s) will be deleted. The files stay in the media library.</>, confirmText: 'Delete album', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/galleries/${r.id}`);
      toast.success(res.message || 'Album deleted.');
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const menu = (r: Row) => [
    { label: 'Manage photos', icon: Images, onClick: () => navigate(`/cms/gallery/${r.id}`) },
    can('media', 'edit') && { label: 'Edit details', icon: Pencil, onClick: () => setForm({ open: true, id: Number(r.id) }) },
    can('media', 'edit') && r.status !== 'published' && { label: 'Publish', icon: Send, onClick: () => void setAlbumStatus(r, 'published') },
    can('media', 'edit') && r.status === 'published' && { label: 'Hide (move to draft)', icon: EyeOff, onClick: () => void setAlbumStatus(r, 'draft') },
    can('media', 'delete') && { label: 'Delete album', icon: Trash2, danger: true, onClick: () => void remove(r) },
  ];

  const catCounts = st?.by_category ?? {};

  return (
    <>
      <PageHeader
        title="Gallery"
        description="Photo and video albums for the website gallery — campus, events, sports, convocation and more."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Gallery' }]}
        actions={
          <>
            <Button variant="secondary" icon={FolderOpen} to="/cms/media">Media library</Button>
            {can('media', 'create') && <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>New album</Button>}
          </>
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={60}>
        {[
          <StatCard key="a" label="Albums" icon={GalleryHorizontalEnd} tone="navy" loading={stats.isLoading} value={st?.albums ?? 0} hint={st ? `${st.drafts} draft${st.drafts === 1 ? '' : 's'}` : undefined} />,
          <StatCard key="p" label="Published" icon={Send} tone="green" loading={stats.isLoading} value={st?.published ?? 0} hint="Visible on the website" />,
          <StatCard key="i" label="Photos & videos" icon={Images} tone="blue" loading={stats.isLoading} value={st?.items ?? 0} hint={st ? `${st.videos} video${st.videos === 1 ? '' : 's'}` : undefined} />,
          <StatCard key="e" label="Empty albums" icon={ImageOff} tone={st?.empty ? 'amber' : 'slate'} loading={stats.isLoading} value={st?.empty ?? 0} hint={st?.empty ? 'Add photos before publishing' : 'Every album has photos'} />,
        ]}
      </Stagger>

      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        {view === 'grid' ? (
          <Tabs
            variant="pills"
            value={category}
            onChange={setCategory}
            tabs={[{ key: '', label: 'All albums', count: st?.albums }, ...CATEGORIES.filter((c) => catCounts[c.value]).map((c) => ({ key: c.value, label: c.label, count: catCounts[c.value] }))]}
          />
        ) : (
          <span />
        )}
        <Tabs variant="pills" value={view} onChange={(v) => setView(v as 'grid' | 'table')} tabs={[{ key: 'grid', label: 'Albums', icon: LayoutGrid }, { key: 'table', label: 'Table', icon: Rows3 }]} />
      </div>

      {view === 'table' ? (
        <CrudTable
          module="galleries"
          urlState
          addLabel="New album"
          onCreate={() => setForm({ open: true, id: null })}
          onEdit={(r: Row) => setForm({ open: true, id: Number(r.id) })}
          onView={(r: Row) => navigate(`/cms/gallery/${r.id}`)}
          rowMenu={(r: Row) => [{ label: 'Manage photos', icon: Images, onClick: () => navigate(`/cms/gallery/${r.id}`) }]}
          emptyTitle="No albums yet"
          emptyText="Create an album and add photos from the media library."
          renderers={{
            cover_url: (r: Row) => (r.cover_url ? <img src={mediaSrc(String(r.cover_url))} alt="" loading="lazy" className="h-10 w-14 rounded-md object-cover ring-1 ring-slate-200 dark:ring-slate-700" /> : <span className="flex h-10 w-14 items-center justify-center rounded-md bg-slate-100 text-slate-400 dark:bg-slate-800"><ImageOff className="h-4 w-4" /></span>),
            category: (r: Row) => <span className="whitespace-nowrap">{catLabel(r.category)}</span>,
          }}
          onSaved={() => void qc.invalidateQueries({ queryKey: ['gallery-stats'] })}
        />
      ) : (
        <>
          <Card className="mb-4 flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search albums…" className="w-full sm:max-w-xs" size="sm" />
            <Select inputSize="sm" value={status} onChange={(e) => setStatus(e.target.value)} placeholder="Status: All" aria-label="Status" options={[{ value: 'published', label: 'Published' }, { value: 'draft', label: 'Draft' }]} className="sm:!w-40" />
            {filtered && (
              <button type="button" onClick={() => { setQ(''); setCategory(''); setStatus(''); }} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800 sm:ml-auto">
                <FilterX className="h-3.5 w-3.5" /> Clear filters
              </button>
            )}
          </Card>

          {list.error ? (
            <Alert variant="error" title="Unable to load albums">{(list.error as ApiError).message}</Alert>
          ) : list.isLoading ? (
            <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
              {Array.from({ length: 6 }).map((_, i) => (
                <div key={i} className="card overflow-hidden">
                  <div className="skeleton aspect-[16/10] rounded-none" />
                  <div className="space-y-2 p-4"><div className="skeleton h-4 w-2/3" /><div className="skeleton h-3 w-1/2" /></div>
                </div>
              ))}
            </div>
          ) : rows.length === 0 ? (
            <Card>
              <EmptyState
                icon={filtered ? FilterX : GalleryHorizontalEnd}
                title={filtered ? 'No albums match your filters' : 'No albums yet'}
                description={filtered ? 'Try another search or category.' : 'Create the first album — for example a campus tour or the annual fest — and add photos from the media library.'}
                action={filtered ? <Button variant="secondary" icon={X} onClick={() => { setQ(''); setCategory(''); setStatus(''); }}>Clear filters</Button> : can('media', 'create') ? <Button icon={Plus} onClick={() => setForm({ open: true, id: null })}>New album</Button> : undefined}
              />
            </Card>
          ) : (
            <Stagger className={clsx('grid gap-5 sm:grid-cols-2 xl:grid-cols-3', list.isFetching && 'opacity-70')} step={50}>
              {rows.map((r) => (
                <article key={r.id} className="card group h-full overflow-hidden transition duration-200 hover:-translate-y-0.5 hover:shadow-soft">
                  <button type="button" onClick={() => navigate(`/cms/gallery/${r.id}`)} className="relative block aspect-[16/10] w-full overflow-hidden bg-slate-100 dark:bg-slate-800" aria-label={`Manage ${r.title}`}>
                    {r.cover_url ? (
                      <img src={mediaSrc(String(r.cover_url))} alt="" loading="lazy" className="h-full w-full object-cover transition duration-500 group-hover:scale-105" />
                    ) : (
                      <span className="flex h-full flex-col items-center justify-center gap-2 text-slate-400">
                        <ImageOff className="h-8 w-8" />
                        <span className="text-xs">No photos yet</span>
                      </span>
                    )}
                    <span className="absolute inset-x-0 bottom-0 h-20 bg-gradient-to-t from-black/55 to-transparent" aria-hidden />
                    <span className="absolute bottom-3 left-3 inline-flex items-center gap-1.5 rounded-full bg-white/95 px-2.5 py-1 text-xs font-semibold text-slate-800 shadow-sm dark:bg-slate-900/90 dark:text-slate-100">
                      <Images className="h-3.5 w-3.5" /> {Number(r.items_count)}
                      {Number(r.videos_count) > 0 && <><span className="text-slate-300">·</span><Video className="h-3.5 w-3.5" /> {Number(r.videos_count)}</>}
                    </span>
                    <span className="absolute right-3 top-3"><StatusBadge status={String(r.status)} /></span>
                  </button>
                  <div className="flex items-start gap-3 p-4">
                    <div className="min-w-0 flex-1">
                      <h3 className="truncate font-display text-base font-semibold text-slate-900 dark:text-white">
                        <button type="button" onClick={() => navigate(`/cms/gallery/${r.id}`)} className="hover:text-brand-700 dark:hover:text-brand-300">{String(r.title)}</button>
                      </h3>
                      <p className="mt-0.5 flex flex-wrap items-center gap-x-2 text-xs text-slate-500 dark:text-slate-400">
                        <span className="font-medium text-brand-700 dark:text-brand-300">{catLabel(r.category)}</span>
                        {r.event_date && <span className="inline-flex items-center gap-1"><CalendarDays className="h-3 w-3" /> {formatDate(r.event_date)}</span>}
                      </p>
                      {r.description && <p className="mt-2 line-clamp-2 text-sm text-slate-600 dark:text-slate-300">{String(r.description)}</p>}
                    </div>
                    <Dropdown label={`Actions for ${r.title}`} triggerClassName="btn-icon !h-8 !w-8 !rounded-lg shrink-0" trigger={<MoreHorizontal className="h-4 w-4" />} items={menu(r)} />
                  </div>
                </article>
              ))}
            </Stagger>
          )}
          {list.data && list.data.pages > 1 && (
            <Card className="mt-5">
              <Pagination page={list.data.page} pages={list.data.pages} total={list.data.total} perPage={list.data.per_page} onPage={setPage} />
            </Card>
          )}
        </>
      )}

      <ContentFormModal
        open={form.open}
        onClose={() => setForm({ open: false, id: null })}
        module="galleries"
        id={form.id}
        title={form.id ? 'Edit album details' : 'New album'}
        onSaved={(_row, newId) => {
          void refresh();
          if (!form.id) navigate(`/cms/gallery/${newId}`);
        }}
        custom={{ cover_image: ({ value, setValue, invalid }) => <MediaField value={(value as string) || null} onChange={(p) => setValue(p ?? '')} folder="gallery" invalid={invalid} placeholder="Choose a cover image (optional)" /> }}
      />
    </>
  );
}
