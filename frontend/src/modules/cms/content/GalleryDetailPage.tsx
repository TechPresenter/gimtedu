import { useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import {
  ArrowLeft, ArrowRight, CalendarDays, Check, ExternalLink, GripVertical, ImageIcon, ImagePlus, Images, Pencil, PlayCircle, Star, Trash2, UploadCloud, Video, X, XCircle,
} from 'lucide-react';
import {
  Alert, Badge, Button, Card, EmptyState, Field, Input, Modal, PageHeader, Reveal, Skeleton, StatusBadge, useConfirm, useToast,
} from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDate, labelize } from '@/lib/format';
import { useApi } from '@/lib/queries';
import { ContentFormModal } from './ContentFormModal';
import { MediaField, MediaPicker } from './MediaPicker';
import { MediaUploader } from './MediaUploader';
import { useReorder } from './widgets';
import type { GalleryAlbum, GalleryItem, MediaItem } from './types';

interface AlbumPayload {
  album: GalleryAlbum;
  items: GalleryItem[];
}

/** Album manager: add photos from the library or upload, captions, drag & drop ordering, cover, remove. */
export default function GalleryDetailPage() {
  const { id: idParam } = useParams();
  const id = Number(idParam);
  const navigate = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canEdit = can('media', 'edit');
  const { data, isLoading, error, refetch } = useApi<AlbumPayload>(['gallery-album', id], `gallery/${id}`, undefined, { enabled: id > 0 });

  const [pickerOpen, setPickerOpen] = useState(false);
  const [uploadOpen, setUploadOpen] = useState(false);
  const [videoOpen, setVideoOpen] = useState(false);
  const [editOpen, setEditOpen] = useState(false);
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [editing, setEditing] = useState<{ id: number; caption: string } | null>(null);

  const items = useMemo(() => data?.items ?? [], [data]);
  const album = data?.album;

  const refresh = async () => {
    await Promise.all([refetch(), qc.invalidateQueries({ queryKey: ['crud', 'galleries'] }), qc.invalidateQueries({ queryKey: ['gallery-stats'] })]);
  };
  const run = async (fn: () => Promise<{ message: string }>) => {
    try {
      const res = await fn();
      if (res.message) toast.success(res.message);
      await refresh();
      return true;
    } catch (e) {
      toast.error((e as ApiError).message);
      return false;
    }
  };

  const reorder = useReorder(items, (ids) => {
    void api
      .post(`gallery/${id}/reorder`, { ids })
      .then(() => qc.invalidateQueries({ queryKey: ['gallery-album', id] }))
      .catch((e: ApiError) => {
        toast.error(e.message);
        void refetch();
      });
  }, canEdit);

  const addMedia = (list: MediaItem[]) => run(() => api.post(`gallery/${id}/items`, { media_ids: list.map((m) => m.id) }));
  const setCover = (item: GalleryItem | null) => run(() => api.post(`gallery/${id}/cover`, { item_id: item?.id ?? null }));
  const removeItems = async (ids: number[]) => {
    const ok = await confirm({
      title: ids.length === 1 ? 'Remove this item from the album?' : `Remove ${ids.length} items from the album?`,
      message: 'The files stay in the media library — only their place in this album is removed.',
      confirmText: 'Remove',
      danger: true,
    });
    if (!ok) return;
    if (await run(() => api.post(`gallery/${id}/items/remove`, { ids }))) setSelected(new Set());
  };
  const saveCaption = async () => {
    if (!editing) return;
    const ok = await run(() => api.post(`gallery/items/${editing.id}`, { caption: editing.caption }));
    if (ok) setEditing(null);
  };
  const deleteAlbum = async () => {
    if (!album) return;
    const ok = await confirm({ title: 'Delete this album?', message: <>“{album.title}” and its {items.length} item(s) will be deleted. Files stay in the media library.</>, confirmText: 'Delete album', danger: true });
    if (!ok) return;
    try {
      const res = await api.del(`crud/galleries/${id}`);
      toast.success(res.message || 'Album deleted.');
      await qc.invalidateQueries({ queryKey: ['crud', 'galleries'] });
      await qc.invalidateQueries({ queryKey: ['gallery-stats'] });
      navigate('/cms/gallery');
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };
  const toggleSel = (itemId: number) =>
    setSelected((s) => {
      const n = new Set(s);
      if (n.has(itemId)) n.delete(itemId);
      else n.add(itemId);
      return n;
    });

  if (error) {
    const err = error as ApiError;
    return (
      <>
        <PageHeader title="Album not available" breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Gallery', to: '/cms/gallery' }, { label: 'Album' }]} />
        <Card>
          <EmptyState icon={XCircle} title={err.status === 404 ? 'This album no longer exists' : 'Unable to load this album'} description={err.message} action={<Button to="/cms/gallery">Back to gallery</Button>} />
        </Card>
      </>
    );
  }

  const photos = items.filter((i) => i.kind === 'photo').length;
  const videos = items.length - photos;

  return (
    <>
      <PageHeader
        title={album?.title ?? 'Album'}
        description={album ? [labelize(album.category ?? 'campus'), album.event_date ? formatDate(album.event_date) : null, `${photos} photo${photos === 1 ? '' : 's'}${videos ? ` · ${videos} video${videos === 1 ? '' : 's'}` : ''}`].filter(Boolean).join(' · ') : 'Loading album…'}
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Gallery', to: '/cms/gallery' }, { label: album?.title ?? 'Album' }]}
        actions={
          album && (
            <>
              {album.status === 'published' && (
                <Button variant="secondary" icon={ExternalLink} href={album.public_url} target="_blank">
                  View on website
                </Button>
              )}
              {canEdit && (
                <Button variant="secondary" icon={Pencil} onClick={() => setEditOpen(true)}>
                  Edit details
                </Button>
              )}
              {canEdit && (
                <Button icon={ImagePlus} onClick={() => setPickerOpen(true)}>
                  Add photos
                </Button>
              )}
            </>
          )
        }
      />

      {isLoading || !album ? (
        <div className="space-y-6">
          <Skeleton className="h-28 w-full" />
          <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            {Array.from({ length: 8 }).map((_, i) => (
              <Skeleton key={i} className="aspect-[4/3] w-full" />
            ))}
          </div>
        </div>
      ) : (
        <>
          <Reveal>
            <Card className="mb-6 flex flex-col gap-4 p-4 sm:flex-row sm:items-center">
              <div className="aspect-[4/3] w-full shrink-0 overflow-hidden rounded-xl bg-slate-100 sm:w-40 dark:bg-slate-800">
                {album.cover_url ? <img src={album.cover_url} alt="" className="h-full w-full object-cover" /> : <div className="flex h-full items-center justify-center text-slate-400"><ImageIcon className="h-8 w-8" /></div>}
              </div>
              <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                  <StatusBadge status={album.status} />
                  <Badge color="navy">{labelize(album.category ?? 'campus')}</Badge>
                  {album.event_date && (
                    <span className="inline-flex items-center gap-1 text-xs text-slate-500">
                      <CalendarDays className="h-3.5 w-3.5" /> {formatDate(album.event_date)}
                    </span>
                  )}
                </div>
                <p className="mt-2 text-sm text-slate-600 dark:text-slate-300">{album.description || <span className="text-slate-400">No description yet.</span>}</p>
                <p className="mt-1 truncate font-mono text-xs text-slate-400">/gallery/{album.slug}</p>
              </div>
              {canEdit && (
                <div className="flex flex-wrap gap-2 sm:flex-col">
                  <Button size="sm" variant="secondary" icon={UploadCloud} onClick={() => setUploadOpen(true)}>
                    Upload photos
                  </Button>
                  <Button size="sm" variant="secondary" icon={Video} onClick={() => setVideoOpen(true)}>
                    Add video link
                  </Button>
                  {can('media', 'delete') && (
                    <Button size="sm" variant="ghost" icon={Trash2} className="!text-red-600" onClick={deleteAlbum}>
                      Delete album
                    </Button>
                  )}
                </div>
              )}
            </Card>
          </Reveal>

          {selected.size > 0 && (
            <div className="sticky top-16 z-10 mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-brand-100 bg-brand-50/95 px-4 py-2.5 text-sm shadow-sm backdrop-blur dark:border-brand-500/20 dark:bg-slate-900/95">
              <span className="font-semibold text-brand-900 dark:text-brand-100">{selected.size} selected</span>
              <button type="button" className="text-xs text-brand-700 underline dark:text-brand-300" onClick={() => setSelected(new Set())}>
                Clear
              </button>
              <button type="button" className="text-xs text-brand-700 underline dark:text-brand-300" onClick={() => setSelected(new Set(items.map((i) => i.id)))}>
                Select all
              </button>
              <Button size="sm" variant="danger" icon={Trash2} className="ml-auto" onClick={() => removeItems([...selected])}>
                Remove from album
              </Button>
            </div>
          )}

          {items.length === 0 ? (
            <Card>
              <EmptyState
                icon={Images}
                title="This album is empty"
                description="Add photos from the media library or upload new ones. You can reorder them and set the cover afterwards."
                action={
                  canEdit && (
                    <>
                      <Button icon={ImagePlus} onClick={() => setPickerOpen(true)}>Add from library</Button>
                      <Button variant="secondary" icon={UploadCloud} onClick={() => setUploadOpen(true)}>Upload photos</Button>
                    </>
                  )
                }
              />
            </Card>
          ) : (
            <>
              {canEdit && <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">Drag photos to reorder them (or use the arrow buttons). The order is saved automatically.</p>}
              <ul className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5" aria-label="Album items">
                {reorder.items.map((it, idx) => {
                  const isCover = !!album.cover_image && it.path === album.cover_image;
                  const sel = selected.has(it.id);
                  return (
                    <li
                      key={it.id}
                      {...reorder.dragProps(it.id)}
                      className={clsx(
                        'group relative overflow-hidden rounded-2xl border bg-white shadow-card transition dark:bg-slate-900',
                        sel ? 'border-brand-500 ring-2 ring-brand-500' : 'border-slate-200/80 dark:border-slate-800',
                        reorder.overId === it.id && 'ring-2 ring-accent-500',
                        reorder.dragId === it.id && 'opacity-50',
                        canEdit && 'cursor-grab active:cursor-grabbing',
                      )}
                    >
                      <div className="relative aspect-[4/3] overflow-hidden bg-slate-100 dark:bg-slate-800">
                        {it.thumbnail ? (
                          <img src={it.thumbnail} alt={it.alt_text || it.caption || ''} loading="lazy" draggable={false} className="h-full w-full object-cover transition duration-300 group-hover:scale-[1.03]" />
                        ) : (
                          <div className="flex h-full items-center justify-center text-slate-400"><Video className="h-8 w-8" /></div>
                        )}
                        {it.kind === 'video' && (
                          <span className="absolute inset-0 flex items-center justify-center bg-black/20 text-white">
                            <PlayCircle className="h-10 w-10 drop-shadow" />
                          </span>
                        )}
                        {canEdit && (
                          <button
                            type="button"
                            onClick={() => toggleSel(it.id)}
                            aria-pressed={sel}
                            aria-label={sel ? 'Deselect item' : 'Select item'}
                            className={clsx('absolute left-2 top-2 inline-flex h-6 w-6 items-center justify-center rounded-md border-2 transition', sel ? 'border-brand-600 bg-brand-600 text-white' : 'border-white bg-black/25 text-transparent focus:opacity-100 sm:opacity-0 sm:group-hover:opacity-100')}
                          >
                            <Check className="h-3.5 w-3.5" />
                          </button>
                        )}
                        <span className="absolute right-2 top-2 flex gap-1">
                          {isCover && <span className="inline-flex items-center gap-1 rounded-full bg-amber-400 px-2 py-0.5 text-[10px] font-bold text-amber-950 shadow"><Star className="h-3 w-3 fill-current" /> Cover</span>}
                          <span className="rounded-full bg-black/45 px-2 py-0.5 text-[10px] font-semibold text-white">#{idx + 1}</span>
                        </span>
                        {canEdit && (
                          <div className="absolute inset-x-2 bottom-2 flex items-center justify-between gap-1 transition focus-within:opacity-100 sm:opacity-0 sm:group-hover:opacity-100">
                            <span className="inline-flex rounded-lg bg-white/95 shadow dark:bg-slate-900/95">
                              <button type="button" className="p-1.5 text-slate-600 hover:text-brand-700 disabled:opacity-30 dark:text-slate-300" onClick={() => reorder.move(it.id, -1)} disabled={idx === 0} aria-label="Move earlier" title="Move earlier">
                                <ArrowLeft className="h-4 w-4" />
                              </button>
                              <span className="hidden p-1.5 text-slate-400 sm:inline" aria-hidden><GripVertical className="h-4 w-4" /></span>
                              <button type="button" className="p-1.5 text-slate-600 hover:text-brand-700 disabled:opacity-30 dark:text-slate-300" onClick={() => reorder.move(it.id, 1)} disabled={idx === reorder.items.length - 1} aria-label="Move later" title="Move later">
                                <ArrowRight className="h-4 w-4" />
                              </button>
                            </span>
                            <span className="inline-flex rounded-lg bg-white/95 shadow dark:bg-slate-900/95">
                              {it.kind === 'photo' && !isCover && (
                                <button type="button" className="p-1.5 text-slate-600 hover:text-amber-600 dark:text-slate-300" onClick={() => void setCover(it)} aria-label="Use as album cover" title="Use as cover">
                                  <Star className="h-4 w-4" />
                                </button>
                              )}
                              <button type="button" className="p-1.5 text-slate-600 hover:text-brand-700 dark:text-slate-300" onClick={() => setEditing({ id: it.id, caption: it.caption ?? '' })} aria-label="Edit caption" title="Edit caption">
                                <Pencil className="h-4 w-4" />
                              </button>
                              <button type="button" className="p-1.5 text-slate-600 hover:text-red-600 dark:text-slate-300" onClick={() => removeItems([it.id])} aria-label="Remove from album" title="Remove from album">
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </span>
                          </div>
                        )}
                      </div>
                      <div className="px-3 py-2.5">
                        {editing?.id === it.id ? (
                          <form
                            className="flex items-center gap-1"
                            onSubmit={(e) => {
                              e.preventDefault();
                              void saveCaption();
                            }}
                          >
                            <Input inputSize="sm" autoFocus value={editing.caption} maxLength={255} onChange={(e) => setEditing({ id: it.id, caption: e.target.value })} aria-label="Caption" onKeyDown={(e) => e.key === 'Escape' && setEditing(null)} />
                            <button type="submit" className="btn-icon !h-8 !w-8 shrink-0 text-emerald-600" aria-label="Save caption"><Check className="h-4 w-4" /></button>
                            <button type="button" className="btn-icon !h-8 !w-8 shrink-0" aria-label="Cancel" onClick={() => setEditing(null)}><X className="h-4 w-4" /></button>
                          </form>
                        ) : (
                          <button
                            type="button"
                            disabled={!canEdit}
                            onClick={() => setEditing({ id: it.id, caption: it.caption ?? '' })}
                            className="block w-full truncate text-left text-sm font-medium text-slate-700 hover:text-brand-700 disabled:hover:text-slate-700 dark:text-slate-200"
                            title={it.caption ?? 'Add a caption'}
                          >
                            {it.caption || <span className="font-normal italic text-slate-400">Add a caption…</span>}
                          </button>
                        )}
                        <p className="truncate text-[11px] text-slate-400">
                          {it.kind === 'video' ? (it.video_url ? 'Video link' : 'Video file') : [it.width && it.height ? `${it.width}×${it.height}` : null, it.size_label].filter(Boolean).join(' · ') || 'Photo'}
                        </p>
                      </div>
                    </li>
                  );
                })}
              </ul>
              {album.cover_image && canEdit && (
                <p className="mt-4 text-xs text-slate-500">
                  A custom cover is set.{' '}
                  <button type="button" className="font-medium text-brand-700 underline dark:text-brand-300" onClick={() => void setCover(null)}>
                    Use the first photo instead
                  </button>
                </p>
              )}
            </>
          )}
        </>
      )}

      <MediaPicker
        open={pickerOpen}
        onClose={() => setPickerOpen(false)}
        multiple
        accept="image-video"
        folder="gallery"
        title="Add photos to the album"
        confirmLabel="Add to album"
        disabledIds={items.map((i) => i.media_id).filter((x): x is number => x !== null)}
        disabledLabel="In album"
        onSelect={(list) => void addMedia(list)}
      />

      <Modal open={uploadOpen} onClose={() => setUploadOpen(false)} size="lg" title="Upload photos to this album" description="Files are saved to the media library (Gallery folder) and added to the album.">
        <MediaUploader
          folder="gallery"
          lockFolder
          accept="image-video"
          onUploaded={async (list, { failed }) => {
            const ok = await addMedia(list);
            // Keep the uploader open when some files failed so their errors stay visible (retry / remove).
            if (ok && !failed) setUploadOpen(false);
          }}
        />
      </Modal>

      <VideoModal open={videoOpen} onClose={() => setVideoOpen(false)} albumId={id} onSaved={refresh} />

      {album && (
        <ContentFormModal
          open={editOpen}
          onClose={() => setEditOpen(false)}
          module="galleries"
          id={id}
          title="Edit album details"
          onSaved={() => void refresh()}
          custom={{ cover_image: ({ value, setValue, invalid }) => <MediaField value={(value as string) || null} onChange={(p) => setValue(p ?? '')} folder="gallery" invalid={invalid} placeholder="Choose a cover image (optional)" /> }}
        />
      )}
      {!album && !isLoading && <Alert variant="warning">Album not found.</Alert>}
    </>
  );
}

function VideoModal({ open, onClose, albumId, onSaved }: { open: boolean; onClose: () => void; albumId: number; onSaved: () => Promise<void> }) {
  const toast = useToast();
  const [url, setUrl] = useState('');
  const [caption, setCaption] = useState('');
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const submit = async () => {
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post(`gallery/${albumId}/items`, { video_url: url, caption });
      toast.success(res.message);
      await onSaved();
      setUrl('');
      setCaption('');
      onClose();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="md"
      title="Add a video link"
      description="YouTube, Vimeo or a direct MP4 link. YouTube thumbnails are shown automatically."
      footer={
        <>
          <Button variant="secondary" onClick={onClose} disabled={saving}>Cancel</Button>
          <Button icon={Video} loading={saving} onClick={submit}>Add video</Button>
        </>
      }
    >
      <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); void submit(); }} noValidate>
        <Field label="Video URL" required htmlFor="video-url" error={errors.video_url}>
          <Input id="video-url" type="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://www.youtube.com/watch?v=…" invalid={!!errors.video_url} />
        </Field>
        <Field label="Caption" htmlFor="video-caption" error={errors.caption}>
          <Input id="video-caption" value={caption} onChange={(e) => setCaption(e.target.value)} maxLength={255} placeholder="e.g. Campus tour 2026" />
        </Field>
      </form>
    </Modal>
  );
}
