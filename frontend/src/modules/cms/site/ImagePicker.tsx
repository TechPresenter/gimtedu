import { useEffect, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Check, ImageIcon, ImagePlus, Images, Library, Trash2, UploadCloud } from 'lucide-react';
import { Alert, Button, EmptyState, Field, FileUpload, Input, Modal, SearchInput, Tabs, useToast } from '@/components/ui';
import { api, ApiError, toFormData } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { useDebounce } from '@/lib/hooks';
import { humanFileSize } from '@/lib/format';
import type { MediaItem } from './types';

interface MediaPage {
  rows: MediaItem[];
  total: number;
  page: number;
  pages: number;
}

const IMAGE_ACCEPT = '.jpg,.jpeg,.png,.webp,.gif';

/** Modal to choose an image from the media library or the bundled campus photography, or to upload a new one. */
export function ImagePickerModal({ open, onClose, onSelect, current }: { open: boolean; onClose: () => void; onSelect: (path: string, item?: MediaItem) => void; current?: string | null }) {
  const toast = useToast();
  const qc = useQueryClient();
  const [tab, setTab] = useState<'library' | 'stock' | 'upload'>('stock');
  const [q, setQ] = useState('');
  const [page, setPage] = useState(1);
  const [file, setFile] = useState<File | null>(null);
  const [alt, setAlt] = useState('');
  const [uploading, setUploading] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);
  const dq = useDebounce(q, 300);
  useEffect(() => setPage(1), [dq, tab]);
  useEffect(() => {
    if (!open) {
      setFile(null);
      setAlt('');
      setUploadError(null);
    }
  }, [open]);

  const source = tab === 'upload' ? null : tab;
  const { data, isLoading, error } = useQuery({
    queryKey: ['cms', 'media', source, dq, page],
    queryFn: () => api.get<MediaPage>('cms/media', { source: source ?? undefined, q: dq || undefined, page, per_page: 24 }),
    enabled: open && !!source,
    placeholderData: (prev) => prev,
  });

  const upload = async () => {
    if (!file) return;
    setUploading(true);
    setUploadError(null);
    try {
      const res = await api.post<MediaItem>('cms/upload', toFormData({ file, alt }));
      toast.success(res.message || 'Image uploaded.');
      void qc.invalidateQueries({ queryKey: ['cms', 'media'] });
      onSelect(res.data.path, res.data);
      onClose();
    } catch (e) {
      setUploadError((e as ApiError).message);
    } finally {
      setUploading(false);
    }
  };

  return (
    <Modal open={open} onClose={onClose} size="xl" title="Choose an image" description="Pick a photo from the media library or the campus photography, or upload a new one.">
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <Tabs
          variant="pills"
          value={tab}
          onChange={(k) => setTab(k as typeof tab)}
          tabs={[
            { key: 'stock', label: 'Campus photos', icon: Images },
            { key: 'library', label: 'Media library', icon: Library },
            { key: 'upload', label: 'Upload', icon: UploadCloud },
          ]}
        />
        {tab !== 'upload' && <SearchInput value={q} onChange={setQ} placeholder="Search images…" className="w-full sm:w-64" size="sm" />}
      </div>

      {tab === 'upload' ? (
        <div className="space-y-4">
          {uploadError && <Alert variant="error">{uploadError}</Alert>}
          <FileUpload file={file} onFile={setFile} accept={IMAGE_ACCEPT} maxSize={5 * 1024 * 1024} image onError={(m) => setUploadError(m)} />
          <Field label="Description (alt text)" hint="Describe the photo for people using screen readers." htmlFor="cms-upload-alt">
            <Input id="cms-upload-alt" value={alt} maxLength={190} onChange={(e) => setAlt(e.target.value)} placeholder="e.g. Students in the computer lab" />
          </Field>
          <div className="flex justify-end">
            <Button icon={ImagePlus} onClick={upload} loading={uploading} disabled={!file}>
              Upload &amp; use image
            </Button>
          </div>
        </div>
      ) : error ? (
        <Alert variant="error" title="Unable to load images">{(error as ApiError).message}</Alert>
      ) : isLoading || !data ? (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          {Array.from({ length: 8 }).map((_, i) => <div key={i} className="skeleton aspect-[4/3] rounded-xl" />)}
        </div>
      ) : data.rows.length === 0 ? (
        <EmptyState
          icon={ImageIcon}
          title={dq ? 'No images match your search' : 'The media library is empty'}
          description={dq ? 'Try another word.' : 'Upload an image to start your library.'}
          action={<Button icon={UploadCloud} onClick={() => setTab('upload')}>Upload an image</Button>}
        />
      ) : (
        <>
          <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
            {data.rows.map((m) => {
              const selected = current === m.path;
              return (
                <button
                  key={`${m.source}-${m.id ?? m.path}`}
                  type="button"
                  onClick={() => {
                    onSelect(m.path, m);
                    onClose();
                  }}
                  className={clsx(
                    'group relative overflow-hidden rounded-xl text-left ring-1 transition hover:-translate-y-0.5 hover:shadow-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
                    selected ? 'ring-2 ring-accent-500' : 'ring-slate-200 dark:ring-slate-700',
                  )}
                >
                  <img src={appUrl(m.path)} alt={m.alt_text ?? m.title ?? ''} loading="lazy" className="aspect-[4/3] w-full object-cover" />
                  <span className="block truncate bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 dark:bg-slate-900 dark:text-slate-200">
                    {m.title ?? m.path.split('/').pop()}
                    <span className="block text-[10px] font-normal text-slate-400">
                      {m.width && m.height ? `${m.width}×${m.height}` : ''}
                      {m.size ? ` · ${humanFileSize(m.size)}` : ''}
                    </span>
                  </span>
                  {selected && (
                    <span className="absolute right-2 top-2 inline-flex h-6 w-6 items-center justify-center rounded-full bg-accent-600 text-white shadow">
                      <Check className="h-4 w-4" />
                    </span>
                  )}
                </button>
              );
            })}
          </div>
          {data.pages > 1 && (
            <div className="mt-4 flex items-center justify-between text-sm text-slate-500">
              <span>
                Page {data.page} of {data.pages} · {data.total} images
              </span>
              <div className="flex gap-2">
                <Button size="sm" variant="secondary" disabled={data.page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
                <Button size="sm" variant="secondary" disabled={data.page >= data.pages} onClick={() => setPage((p) => p + 1)}>Next</Button>
              </div>
            </div>
          )}
        </>
      )}
    </Modal>
  );
}

/** Image value control: thumbnail + Choose / Remove (stores a path such as assets/images/site/x.jpg). */
export function ImageField({ value, onChange, invalid, id, compact, disabled }: { value: string | null | undefined; onChange: (v: string) => void; invalid?: boolean; id?: string; compact?: boolean; disabled?: boolean }) {
  const [open, setOpen] = useState(false);
  const v = value ?? '';
  return (
    <div className={clsx('flex items-center gap-3 rounded-xl border p-2', invalid ? 'border-red-300 dark:border-red-500/50' : 'border-slate-200 dark:border-slate-700')}>
      <button
        id={id}
        type="button"
        onClick={() => setOpen(true)}
        disabled={disabled}
        className={clsx('relative shrink-0 overflow-hidden rounded-lg bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700', compact ? 'h-12 w-16' : 'h-16 w-24')}
        aria-label={v ? 'Change image' : 'Choose image'}
      >
        {v ? <img src={appUrl(v)} alt="" className="h-full w-full object-cover" loading="lazy" /> : <ImageIcon className="mx-auto h-5 w-5 text-slate-400" />}
      </button>
      <div className="min-w-0 flex-1">
        <p className="truncate text-xs font-medium text-slate-700 dark:text-slate-200">{v ? v.split('/').pop() : 'No image selected'}</p>
        <div className="mt-1 flex flex-wrap gap-1.5">
          <Button size="xs" variant="secondary" icon={Images} onClick={() => setOpen(true)} disabled={disabled}>
            {v ? 'Change' : 'Choose'}
          </Button>
          {v && !disabled && (
            <Button size="xs" variant="ghost" icon={Trash2} onClick={() => onChange('')} className="!text-red-600">
              Remove
            </Button>
          )}
        </div>
      </div>
      <ImagePickerModal open={open} onClose={() => setOpen(false)} onSelect={(p) => onChange(p)} current={v} />
    </div>
  );
}
