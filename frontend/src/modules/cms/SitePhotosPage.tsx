import { useMemo, useRef, useState } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { ImageOff, RotateCcw, Upload } from 'lucide-react';
import { Alert, Badge, Button, Card, EmptyState, PageHeader, SearchInput, Skeleton, Spinner, Stagger, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useApi } from '@/lib/queries';
import { humanFileSize, timeAgo } from '@/lib/format';

interface SitePhoto {
  name: string;
  title: string;
  url: string;
  width: number;
  height: number;
  size: number;
  format: string;
  updated_at: string;
  uses: string[];
  used: boolean;
  versions: number;
}
interface Payload {
  photos: SitePhoto[];
  max_bytes: number;
  can: { edit: boolean };
}

const KEY = ['cms', 'site-photos'];

/**
 * Website CMS › Website Photos: the photos built into the website pages (assets/images/site) — page banners, the Apply
 * band, facility cards, portal sign-in backgrounds, default news photo… Replace = upload any photo; the server crops it to
 * the same shape, saves it at the same size and compresses it, so every page using it changes. Undo = previous version.
 * API: GET cms/site-photos, POST cms/site-photos/{name}/replace (photo), POST cms/site-photos/{name}/undo.
 */
export default function SitePhotosPage() {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { data, isLoading, error, refetch } = useApi<Payload>(KEY, 'cms/site-photos');
  const [q, setQ] = useState('');
  const [busy, setBusy] = useState<string | null>(null);
  const canEdit = data?.can.edit ?? false;
  const maxText = data ? humanFileSize(data.max_bytes) : '';

  const list = useMemo(() => {
    const needle = q.trim().toLowerCase();
    const rows = data?.photos ?? [];
    return needle ? rows.filter((p) => `${p.title} ${p.name} ${p.uses.join(' ')}`.toLowerCase().includes(needle)) : rows;
  }, [data, q]);
  const usedCount = (data?.photos ?? []).filter((p) => p.used).length;

  const update = (p: SitePhoto) => qc.setQueryData<Payload>(KEY, (old) => (old ? { ...old, photos: old.photos.map((x) => (x.name === p.name ? p : x)) } : old));

  const replace = async (photo: SitePhoto, file: File) => {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      toast.error('Upload a JPG, PNG or WebP photo.');
      return;
    }
    if (data && file.size > data.max_bytes) {
      toast.error(`The photo must be smaller than ${maxText}. Compress it first (for example on squoosh.app).`);
      return;
    }
    setBusy(photo.name);
    try {
      const fd = new FormData();
      fd.append('photo', file);
      const res = await api.post<SitePhoto>(`cms/site-photos/${encodeURIComponent(photo.name)}/replace`, fd);
      update(res.data);
      toast.success(res.message || 'Photo replaced.');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  const undo = async (photo: SitePhoto) => {
    const ok = await confirm({
      title: 'Bring back the previous photo?',
      message: `${photo.name} goes back to the version before the last change, on every page that uses it.`,
      confirmText: 'Undo',
    });
    if (!ok) return;
    setBusy(photo.name);
    try {
      const res = await api.post<SitePhoto>(`cms/site-photos/${encodeURIComponent(photo.name)}/undo`);
      update(res.data);
      toast.success(res.message || 'The previous photo is back.');
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setBusy(null);
    }
  };

  return (
    <>
      <PageHeader
        title="Website Photos"
        description="Photos built into the website pages — banners, the Apply band, facility cards, portal sign-in backgrounds. Replace one and every page that uses it changes."
        breadcrumbs={[{ label: 'Website CMS', to: '/cms' }, { label: 'Website Photos' }]}
      />

      <Alert variant="info" className="mb-5" title="How replacing works">
        Upload a JPG, PNG or WebP{maxText ? ` (up to ${maxText})` : ''}. It is cropped to the same shape, saved at the same size and compressed automatically, so keep the main
        subject in the middle of the photo. <strong>Undo</strong> brings back the previous photo. Course thumbnails are changed in Academics › Programs, course banner
        backgrounds in Academics › Departments (Cover image), and page banners in Pages &amp; Page Builder.
      </Alert>

      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p className="text-sm text-slate-500 dark:text-slate-400">
          {isLoading ? 'Loading photos…' : `${usedCount} photos used by the website · ${data?.photos.length ?? 0} in total`}
        </p>
        <SearchInput value={q} onChange={setQ} placeholder="Search by name or page…" className="w-full sm:w-72" />
      </div>

      {error ? (
        <Alert variant="error" title="Unable to load the website photos" action={<Button size="sm" variant="secondary" onClick={() => void refetch()}>Try again</Button>}>
          {(error as ApiError).message}
        </Alert>
      ) : isLoading ? (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => (
            <Skeleton key={i} className="h-80 w-full rounded-2xl" />
          ))}
        </div>
      ) : list.length === 0 ? (
        <Card>
          <EmptyState icon={ImageOff} title="No photos match your search" description="Try the file name (campus-building) or a page name (About, Admissions)." action={<Button variant="secondary" onClick={() => setQ('')}>Clear search</Button>} />
        </Card>
      ) : (
        <Stagger className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4" itemClassName="h-full">
          {list.map((p) => (
            <PhotoCard key={p.name} photo={p} busy={busy === p.name} disabled={busy !== null && busy !== p.name} canEdit={canEdit} onReplace={(f) => void replace(p, f)} onUndo={() => void undo(p)} />
          ))}
        </Stagger>
      )}
    </>
  );
}

function PhotoCard({ photo, busy, disabled, canEdit, onReplace, onUndo }: {
  photo: SitePhoto; busy: boolean; disabled: boolean; canEdit: boolean; onReplace: (f: File) => void; onUndo: () => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  return (
    <Card className="flex h-full flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-soft motion-reduce:transition-none">
      <a href={photo.url} target="_blank" rel="noreferrer" className="relative block aspect-[16/10] bg-[#EDE8E2] dark:bg-slate-800" title="Open the photo in a new tab">
        <img src={photo.url} alt={photo.title} loading="lazy" decoding="async" className="h-full w-full object-contain" />
        {!photo.used && (
          <Badge color="slate" className="absolute left-2 top-2">
            Not used
          </Badge>
        )}
        {busy && (
          <span className="absolute inset-0 flex items-center justify-center gap-2 bg-white/75 text-sm font-medium text-slate-700 dark:bg-slate-900/75 dark:text-slate-200" role="status">
            <Spinner className="h-5 w-5" /> Saving…
          </span>
        )}
      </a>
      <div className="flex flex-1 flex-col p-4">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0">
            <p className="truncate font-semibold text-slate-900 dark:text-white">{photo.title}</p>
            <p className="truncate font-mono text-[11px] text-slate-500 dark:text-slate-400">{photo.name}</p>
          </div>
          <Badge color="navy" className="shrink-0 tabular-nums">
            {photo.width}×{photo.height}
          </Badge>
        </div>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">
          {photo.format} · {humanFileSize(photo.size)} · changed {timeAgo(photo.updated_at)}
        </p>
        {photo.uses.length > 0 ? (
          <ul className="mt-3 space-y-1 text-xs text-slate-600 dark:text-slate-300">
            {photo.uses.map((u) => (
              <li key={u} className="flex gap-1.5">
                <span className="mt-1.5 h-1 w-1 shrink-0 rounded-full bg-[#D03E16]" aria-hidden />
                {u}
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-3 text-xs text-slate-400">Not shown on any page.</p>
        )}
        {canEdit && (
          <div className="mt-auto flex flex-wrap gap-2 pt-4">
            <input
              ref={input}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="sr-only"
              tabIndex={-1}
              aria-label={`Upload a new photo for ${photo.title}`}
              onChange={(e) => {
                const f = e.target.files?.[0];
                e.target.value = '';
                if (f) onReplace(f);
              }}
            />
            <Button size="sm" icon={Upload} loading={busy} disabled={disabled} onClick={() => input.current?.click()}>
              Replace
            </Button>
            {photo.versions > 0 && (
              <Button size="sm" variant="secondary" icon={RotateCcw} disabled={busy || disabled} onClick={onUndo}>
                Undo
              </Button>
            )}
          </div>
        )}
      </div>
    </Card>
  );
}
