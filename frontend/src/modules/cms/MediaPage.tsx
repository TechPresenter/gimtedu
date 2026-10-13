import { useEffect, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  Check, Copy, ExternalLink, FileText, FilterX, FolderInput, HardDrive, ImageIcon, Images, LayoutGrid, List, RefreshCw, Trash2, UploadCloud, X,
} from 'lucide-react';
import {
  Alert, Button, Card, CardHeader, Dropdown, EmptyState, PageHeader, Pagination, Reveal, SearchInput, Select, Stagger, StatCard, Tabs, useConfirm, useToast,
} from '@/components/ui';
import { CrudTable } from '@/components/crud';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, formatNumber, humanFileSize, labelize } from '@/lib/format';
import { useDebounce, useLocalStorage } from '@/lib/hooks';
import { useCrudList } from '@/lib/queries';
import type { Row } from '@/lib/types';
import { MediaDetailDrawer } from './content/MediaDetailDrawer';
import { MediaUploader } from './content/MediaUploader';
import { copyText, folderLabel, MEDIA_FOLDERS, MediaThumb, useMediaStats } from './content/media';
import type { MediaItem } from './content/types';

const SORTS: Record<string, { label: string; sort?: string; dir?: 'asc' | 'desc' }> = {
  newest: { label: 'Newest first' },
  oldest: { label: 'Oldest first', sort: 'created_at', dir: 'asc' },
  name: { label: 'Title A–Z', sort: 'title', dir: 'asc' },
  largest: { label: 'Largest first', sort: 'size_label', dir: 'desc' },
};

/** Media library: grid/list, multi-file upload with progress, type/folder filters, search, detail drawer, bulk move/delete. */
export default function MediaPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { can } = useAuth();
  const [view, setView] = useLocalStorage<'grid' | 'list'>('gimt.media.view', 'grid');
  const [uploadOpen, setUploadOpen] = useState(false);
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 350);
  const [type, setType] = useState('');
  const [folder, setFolder] = useState('');
  const [alt, setAlt] = useState('');
  const [sort, setSort] = useState('newest');
  const [page, setPage] = useState(1);
  const [perPage, setPerPage] = useLocalStorage<number>('gimt.media.perPage', 24);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [openId, setOpenId] = useState<number | null>(null);

  const stats = useMediaStats();
  const st = stats.data;
  const srt = SORTS[sort];
  const list = useCrudList<MediaItem>('media', { page, per_page: perPage, q: dq || undefined, sort: srt.sort, dir: srt.dir, f: { type: type || undefined, folder: folder || undefined, alt: alt || undefined } }, view === 'grid');
  const rows = list.data?.rows ?? [];
  const filtered = !!(dq || type || folder || alt);

  useEffect(() => setPage(1), [dq, type, folder, alt, sort, perPage]);
  useEffect(() => setSelected(new Set()), [dq, type, folder, alt, page]);

  const refresh = () => Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'media'] }), qc.invalidateQueries({ queryKey: ['media-stats'] })]);
  const toggle = (id: number) =>
    setSelected((s) => {
      const n = new Set(s);
      if (n.has(id)) n.delete(id);
      else n.add(id);
      return n;
    });
  const ids = [...selected];

  const moveTo = async (f: string) => {
    try {
      const res = await api.post('crud/media/bulk', { action: 'status', field: 'folder', value: f, ids });
      toast.success(res.message ? `${ids.length} file${ids.length === 1 ? '' : 's'} moved to ${folderLabel(f)}.` : 'Moved.');
      setSelected(new Set());
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const bulkDelete = async () => {
    const ok = await confirm({ title: `Delete ${ids.length} file${ids.length === 1 ? '' : 's'}?`, message: 'Files that are used on the website are checked first — you will be asked again before those are deleted.', confirmText: 'Delete', danger: true });
    if (!ok) return;
    try {
      const res = await api.post<{ deleted: number; skipped: { id: number; title: string }[] }>('media/bulk-delete', { ids });
      if (res.data.skipped.length) {
        const again = await confirm({
          title: `${res.data.skipped.length} file${res.data.skipped.length === 1 ? ' is' : 's are'} in use`,
          danger: true,
          confirmText: 'Delete them too',
          cancelText: 'Keep them',
          message: (
            <div className="space-y-2">
              <p>{res.data.deleted ? `${res.data.deleted} unused file(s) were deleted. ` : ''}These files are used on pages, posts or albums. Deleting them leaves broken images:</p>
              <ul className="max-h-36 list-disc overflow-y-auto pl-5 text-xs">{res.data.skipped.map((s) => <li key={s.id}>{s.title}</li>)}</ul>
            </div>
          ),
        });
        if (again) {
          const r2 = await api.post('media/bulk-delete', { ids: res.data.skipped.map((s) => s.id), force: true });
          toast.success(r2.message);
        } else toast.info(res.message);
      } else toast.success(res.message);
      setSelected(new Set());
      await refresh();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const copyUrl = async (m: { absolute_url?: string }) => {
    if (m.absolute_url && (await copyText(m.absolute_url))) toast.success('File URL copied to the clipboard.');
  };
  const clearFilters = () => {
    setQ('');
    setType('');
    setFolder('');
    setAlt('');
  };

  const typeTabs = [
    { key: '', label: 'All files', count: st?.total },
    { key: 'image', label: 'Images', count: st?.by_type.image },
    { key: 'video', label: 'Videos', count: st?.by_type.video },
    { key: 'pdf', label: 'PDFs', count: st?.by_type.pdf },
    { key: 'document', label: 'Documents', count: st?.by_type.document },
  ];

  return (
    <>
      <PageHeader
        title="Media Library"
        description="All images, videos, PDFs and documents used on the website — upload once, reuse everywhere."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Media Library' }]}
        actions={
          can('media', 'create') && (
            <Button icon={uploadOpen ? X : UploadCloud} variant={uploadOpen ? 'secondary' : 'primary'} onClick={() => setUploadOpen((o) => !o)}>
              {uploadOpen ? 'Close uploader' : 'Upload files'}
            </Button>
          )
        }
      />

      <Stagger className="mb-6 grid grid-cols-2 gap-3 lg:grid-cols-4" step={60}>
        {[
          <StatCard key="t" label="Total files" icon={Images} tone="navy" loading={stats.isLoading} value={st?.total ?? 0} hint={st ? `${st.this_month} uploaded this month` : undefined} />,
          <StatCard key="i" label="Images" icon={ImageIcon} tone="blue" loading={stats.isLoading} value={st?.by_type.image ?? 0} hint={st ? (st.missing_alt ? `${st.missing_alt} missing alt text` : 'All have alt text') : undefined} />,
          <StatCard key="d" label="PDFs & documents" icon={FileText} tone="orange" loading={stats.isLoading} value={(st?.by_type.pdf ?? 0) + (st?.by_type.document ?? 0)} hint={st ? `${st.by_type.video} video${st.by_type.video === 1 ? '' : 's'}` : undefined} />,
          <StatCard key="s" label="Storage used" icon={HardDrive} tone="purple" loading={stats.isLoading} value={st ? humanFileSize(st.total_bytes) : '—'} hint={st ? `Max ${humanFileSize(st.rules.image.max_bytes)} per image` : undefined} />,
        ]}
      </Stagger>

      {uploadOpen && (
        <Reveal className="mb-6">
          <Card>
            <CardHeader title="Upload files" subtitle="Drop several files at once — each one is validated and uploaded with its own progress bar." icon={UploadCloud} />
            <div className="px-5 pb-5 pt-4">
              <MediaUploader
                folder={folder || 'general'}
                onUploaded={(items) => {
                  toast.success(`${items.length} file${items.length === 1 ? '' : 's'} uploaded to the media library.`);
                  setSort('newest');
                  setPage(1);
                }}
              />
            </div>
          </Card>
        </Reveal>
      )}

      <div className="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
        {view === 'grid' ? <Tabs variant="pills" value={type} onChange={setType} tabs={typeTabs} /> : <p className="text-sm text-slate-500 dark:text-slate-400">Table view with column control, filters, export and bulk folder moves.</p>}
        <Tabs variant="pills" value={view} onChange={(v) => setView(v as 'grid' | 'list')} tabs={[{ key: 'grid', label: 'Grid', icon: LayoutGrid }, { key: 'list', label: 'List', icon: List }]} />
      </div>

      {view === 'list' ? (
        <CrudTable
          module="media"
          urlState
          hideFilters={['kind']}
          addLabel="Upload files"
          onCreate={() => setUploadOpen(true)}
          onView={(r: Row) => setOpenId(Number(r.id))}
          onEdit={(r: Row) => setOpenId(Number(r.id))}
          emptyTitle="The media library is empty"
          emptyText="Upload images, videos, PDFs and documents to use them on the website."
          renderers={{
            path: (r: Row) => (
              <button type="button" onClick={() => setOpenId(Number(r.id))} className="block h-11 w-14 overflow-hidden rounded-lg ring-1 ring-slate-200 dark:ring-slate-700" aria-label={`Open ${r.title}`}>
                <MediaThumb item={r as MediaItem} rounded="rounded-none" iconClassName="!h-5 !w-5" />
              </button>
            ),
            folder: (r: Row) => <span className="whitespace-nowrap text-sm text-slate-600 dark:text-slate-300">{folderLabel(r.folder)}</span>,
            title: (r: Row) => (
              <button type="button" onClick={() => setOpenId(Number(r.id))} className="block min-w-[12rem] max-w-xs text-left">
                <span className="block truncate font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">{String(r.title || r.original_name)}</span>
                <span className="block truncate text-xs text-slate-500 dark:text-slate-400">{String(r.original_name ?? '')}</span>
              </button>
            ),
            size_label: (r: Row) => (
              <div className="whitespace-nowrap text-right leading-tight tabular-nums">
                <div>{String(r.size_label)}</div>
                {r.dimensions && <div className="text-xs text-slate-500">{String(r.dimensions)} px</div>}
              </div>
            ),
            dimensions: (r: Row) => (r.dimensions ? <span className="whitespace-nowrap">{String(r.dimensions)}</span> : <span className="text-slate-400">—</span>),
            created_at: (r: Row) => <span className="whitespace-nowrap">{formatDate(r.created_at)}</span>,
          }}
          rowMenu={(r: Row) => [
            { label: 'Copy URL', icon: Copy, onClick: () => void copyUrl(r) },
            { label: 'Open file', icon: ExternalLink, href: String(r.url), target: '_blank' },
          ]}
        />
      ) : (
        <Card className="overflow-hidden">
          <div className="flex flex-col gap-2 border-b border-slate-100 p-4 dark:border-slate-800 lg:flex-row lg:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search by title, file name, alt text…" className="w-full lg:max-w-xs" />
            <div className="flex flex-wrap items-center gap-2 lg:ml-auto">
              <Select inputSize="sm" value={folder} onChange={(e) => setFolder(e.target.value)} placeholder="All folders" aria-label="Folder"
                options={(st?.folders ?? MEDIA_FOLDERS.map((f) => ({ ...f, count: 0 }))).map((f) => ({ value: f.value, label: st ? `${f.label} (${f.count})` : f.label }))} className="!w-full sm:!w-52" />
              <Select inputSize="sm" value={alt} onChange={(e) => setAlt(e.target.value)} placeholder="Alt text: Any" aria-label="Alt text" options={[{ value: 'missing', label: 'Missing alt text' }, { value: 'present', label: 'Has alt text' }]} className="!w-full sm:!w-44" />
              <Select inputSize="sm" value={sort} onChange={(e) => setSort(e.target.value)} aria-label="Sort" options={Object.entries(SORTS).map(([value, s]) => ({ value, label: s.label }))} className="!w-full sm:!w-40" />
              <button type="button" onClick={() => void list.refetch()} className="btn-icon" aria-label="Refresh" title="Refresh">
                <RefreshCw className={clsx('h-4 w-4', list.isFetching && 'animate-spin')} />
              </button>
              {filtered && (
                <button type="button" onClick={clearFilters} className="inline-flex items-center gap-1 rounded-lg px-2 py-1.5 text-xs font-medium text-slate-500 hover:bg-slate-100 hover:text-slate-800 dark:hover:bg-slate-800">
                  <FilterX className="h-3.5 w-3.5" /> Clear
                </button>
              )}
            </div>
          </div>

          {selected.size > 0 && (
            <div className="flex flex-wrap items-center gap-2 border-b border-brand-100 bg-brand-50/70 px-4 py-2.5 text-sm dark:border-brand-500/20 dark:bg-brand-500/10">
              <span className="font-semibold text-brand-900 dark:text-brand-100">{selected.size} selected</span>
              <button type="button" className="text-xs text-brand-700 underline dark:text-brand-300" onClick={() => setSelected(new Set(rows.map((r) => r.id)))}>Select page</button>
              <button type="button" className="text-xs text-brand-700 underline dark:text-brand-300" onClick={() => setSelected(new Set())}>Clear</button>
              <div className="ml-auto flex flex-wrap gap-2">
                {can('media', 'edit') && (
                  <Dropdown trigger={<><FolderInput className="h-4 w-4" />Move to folder</>} items={MEDIA_FOLDERS.map((f) => ({ label: f.label, onClick: () => void moveTo(f.value) }))} />
                )}
                {can('media', 'delete') && (
                  <Button size="sm" variant="danger" icon={Trash2} onClick={bulkDelete}>Delete</Button>
                )}
              </div>
            </div>
          )}

          {list.error ? (
            <div className="p-6">
              <Alert variant="error" title="Unable to load the media library">{(list.error as ApiError).message}</Alert>
            </div>
          ) : list.isLoading ? (
            <div className="grid grid-cols-2 gap-4 p-4 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6">
              {Array.from({ length: 12 }).map((_, i) => (
                <div key={i}>
                  <div className="skeleton aspect-square rounded-xl" />
                  <div className="skeleton mt-2 h-3 w-3/4" />
                </div>
              ))}
            </div>
          ) : rows.length === 0 ? (
            <EmptyState
              icon={filtered ? FilterX : Images}
              title={filtered ? 'No files match your filters' : 'The media library is empty'}
              description={filtered ? 'Try another search term, type or folder.' : 'Upload images, videos, PDFs and documents to use them across the website.'}
              action={
                filtered ? (
                  <Button variant="secondary" icon={X} onClick={clearFilters}>Clear filters</Button>
                ) : can('media', 'create') ? (
                  <Button icon={UploadCloud} onClick={() => setUploadOpen(true)}>Upload files</Button>
                ) : undefined
              }
            />
          ) : (
            <ul className={clsx('grid grid-cols-2 gap-4 p-4 transition-opacity sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-6', list.isFetching && 'opacity-70')} aria-label="Media files">
              {rows.map((m) => {
                const sel = selected.has(m.id);
                return (
                  <li key={m.id} className="group relative">
                    <button
                      type="button"
                      onClick={() => setOpenId(m.id)}
                      className={clsx(
                        'block w-full overflow-hidden rounded-xl text-left ring-2 ring-offset-2 transition hover:-translate-y-0.5 hover:shadow-soft focus:outline-none focus-visible:ring-brand-500 dark:ring-offset-slate-900',
                        sel ? 'ring-brand-600' : 'ring-transparent',
                      )}
                      aria-label={`Open ${m.title || m.original_name}`}
                    >
                      <div className="relative aspect-square overflow-hidden rounded-xl">
                        <MediaThumb item={m} className="transition duration-300 group-hover:scale-[1.04]" />
                        {m.type === 'image' && <span className="absolute bottom-1.5 left-1.5 rounded-md bg-black/55 px-1.5 py-0.5 text-[10px] font-semibold uppercase text-white">{m.extension ?? m.type}</span>}
                        {m.type === 'image' && !m.alt_text && (
                          <span className="absolute bottom-1.5 right-1.5 rounded-md bg-amber-400 px-1.5 py-0.5 text-[10px] font-bold text-amber-950" title="Missing alt text">No alt</span>
                        )}
                      </div>
                    </button>
                    {(can('media', 'edit') || can('media', 'delete')) && (
                      <button
                        type="button"
                        onClick={() => toggle(m.id)}
                        aria-pressed={sel}
                        aria-label={sel ? `Deselect ${m.title}` : `Select ${m.title}`}
                        className={clsx(
                          'absolute left-2 top-2 inline-flex h-6 w-6 items-center justify-center rounded-md border-2 transition',
                          sel ? 'border-brand-600 bg-brand-600 text-white' : 'border-white bg-black/25 text-transparent focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100',
                          selected.size > 0 && 'opacity-100',
                        )}
                      >
                        <Check className="h-3.5 w-3.5" />
                      </button>
                    )}
                    <p className="mt-2 truncate text-sm font-medium text-slate-800 dark:text-slate-100" title={m.title ?? ''}>{m.title || m.original_name}</p>
                    <p className="truncate text-xs text-slate-500 dark:text-slate-400">
                      {m.size_label}
                      {m.width && m.height ? ` · ${m.width}×${m.height}` : ''} · {folderLabel(m.folder)}
                    </p>
                  </li>
                );
              })}
            </ul>
          )}
          {list.data && list.data.total > 0 && (
            <Pagination
              className="border-t border-slate-100 dark:border-slate-800"
              page={list.data.page}
              pages={list.data.pages}
              total={list.data.total}
              perPage={list.data.per_page}
              onPage={setPage}
              onPerPage={setPerPage}
              perPageOptions={[24, 48, 96]}
            />
          )}
        </Card>
      )}

      {st && st.total > 0 && view === 'grid' && (
        <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
          {formatNumber(st.total)} files · {humanFileSize(st.total_bytes)} · Upload limits: images {humanFileSize(st.rules.image.max_bytes)}, documents {humanFileSize(st.rules.document.max_bytes)}, videos {humanFileSize(st.rules.video.max_bytes)}
          {' · '}Types: {Object.entries(st.by_type).filter(([, n]) => n).map(([k, n]) => `${labelize(k)} ${n}`).join(', ')}
        </p>
      )}

      <MediaDetailDrawer id={openId} onClose={() => setOpenId(null)} />
    </>
  );
}
