import { useEffect, useMemo, useState } from 'react';
import clsx from 'clsx';
import { Check, FolderOpen, ImageIcon, ImagePlus, Library, RefreshCw, Trash2, UploadCloud } from 'lucide-react';
import { Alert, Button, EmptyState, Modal, Pagination, SearchInput, Select, Tabs } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { useCrudList } from '@/lib/queries';
import { useDebounce } from '@/lib/hooks';
import { useAuth } from '@/lib/auth';
import { MEDIA_FOLDERS, MEDIA_TYPES, MediaThumb, mediaSrc } from './media';
import { MediaUploader } from './MediaUploader';
import type { MediaItem } from './types';

export interface MediaPickerProps {
  open: boolean;
  onClose: () => void;
  /** Receives the chosen items (one item unless `multiple`). */
  onSelect: (items: MediaItem[]) => void;
  multiple?: boolean;
  /** Restrict to images, images + videos, or allow any file */
  accept?: 'any' | 'image' | 'image-video';
  /** Folder suggested for new uploads (and the initial library filter when `filterFolder`) */
  folder?: string;
  filterFolder?: boolean;
  title?: string;
  confirmLabel?: string;
  /** Media ids that are already used (e.g. photos already in the album) — shown as "Added" and not selectable */
  disabledIds?: number[];
  disabledLabel?: string;
}

/**
 * Reusable media library picker: browse/search/filter the library or upload new files, then pick one or many.
 *   <MediaPicker open={open} onClose={close} accept="image" onSelect={([m]) => setValue(m.path)} />
 */
export function MediaPicker({ open, onClose, onSelect, multiple, accept = 'any', folder = 'general', filterFolder, title, confirmLabel, disabledIds, disabledLabel = 'Added' }: MediaPickerProps) {
  const { can } = useAuth();
  const canUpload = can('media', 'create');
  const [tab, setTab] = useState<'library' | 'upload'>('library');
  const [q, setQ] = useState('');
  const dq = useDebounce(q, 300);
  const [type, setType] = useState<string>(accept === 'image' ? 'image' : '');
  const [folderF, setFolderF] = useState(filterFolder ? folder : '');
  const [page, setPage] = useState(1);
  const [selected, setSelected] = useState<MediaItem[]>([]);

  useEffect(() => {
    if (open) {
      setSelected([]);
      setTab('library');
      setPage(1);
    }
  }, [open]);
  useEffect(() => setPage(1), [dq, type, folderF]);

  const typeFilter = accept === 'image' ? 'image' : type || undefined;
  // "image-video" pickers filter server-side (kind = visual) so pages and totals stay correct.
  const list = useCrudList<MediaItem>(
    'media',
    { page, per_page: 24, q: dq || undefined, f: { type: typeFilter, kind: accept === 'image-video' && !typeFilter ? 'visual' : undefined, folder: folderF || undefined } },
    open,
  );
  const rows = useMemo(() => list.data?.rows ?? [], [list.data]);

  const isSel = (m: MediaItem) => selected.some((s) => s.id === m.id);
  const isDisabled = (m: MediaItem) => !!disabledIds?.includes(m.id);
  const toggle = (m: MediaItem) => {
    if (isDisabled(m)) return;
    if (!multiple) {
      setSelected(isSel(m) ? [] : [m]);
      return;
    }
    setSelected((s) => (isSel(m) ? s.filter((x) => x.id !== m.id) : [...s, m]));
  };
  const confirm = () => {
    if (!selected.length) return;
    onSelect(selected);
    onClose();
  };

  const typeOptions = accept === 'image' ? [] : MEDIA_TYPES.filter((t) => accept === 'any' || t.value === 'image' || t.value === 'video');

  return (
    <Modal
      open={open}
      onClose={onClose}
      size="2xl"
      title={title ?? (multiple ? 'Choose files from the media library' : accept === 'image' ? 'Choose an image' : 'Choose a file')}
      description={multiple ? 'Select one or more files, or upload new ones.' : 'Pick a file from the library or upload a new one.'}
      icon={
        <span className="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
          <Library className="h-5 w-5" />
        </span>
      }
      footer={
        <>
          <span className="mr-auto hidden text-sm text-slate-500 dark:text-slate-400 sm:inline">
            {selected.length ? `${selected.length} selected` : multiple ? 'Click files to select them' : 'Click a file to select it'}
          </span>
          <Button variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button icon={Check} onClick={confirm} disabled={!selected.length}>
            {confirmLabel
              ? `${confirmLabel}${multiple && selected.length ? ` (${selected.length})` : ''}`
              : multiple ? `Add ${selected.length || ''} file${selected.length === 1 ? '' : 's'}`.replace('  ', ' ') : 'Use this file'}
          </Button>
        </>
      }
    >
      <Tabs
        className="-mt-2 mb-4"
        value={tab}
        onChange={(k) => setTab(k as 'library' | 'upload')}
        tabs={[{ key: 'library', label: 'Media library', icon: FolderOpen, count: list.data?.total }, ...(canUpload ? [{ key: 'upload', label: 'Upload new', icon: UploadCloud }] : [])]}
      />
      {tab === 'upload' ? (
        <MediaUploader
          folder={folder}
          accept={accept}
          multiple={!!multiple}
          onUploaded={(items, { failed }) => {
            setSelected((s) => (multiple ? [...s, ...items.filter((i) => !s.some((x) => x.id === i.id))] : items.slice(-1)));
            setQ('');
            setFolderF('');
            setPage(1);
            // Stay on the upload tab when some files failed so their errors remain visible; the uploaded ones are already selected.
            if (!failed) setTab('library');
          }}
        />
      ) : (
        <>
          <div className="mb-4 flex flex-col gap-2 sm:flex-row sm:items-center">
            <SearchInput value={q} onChange={setQ} placeholder="Search by title, file name, alt text…" className="w-full sm:max-w-xs" size="sm" />
            {typeOptions.length > 0 && (
              <Select inputSize="sm" value={type} onChange={(e) => setType(e.target.value)} options={typeOptions} placeholder="All types" aria-label="File type" className="sm:!w-40" />
            )}
            <Select inputSize="sm" value={folderF} onChange={(e) => setFolderF(e.target.value)} options={MEDIA_FOLDERS} placeholder="All folders" aria-label="Folder" className="sm:!w-48" />
            <button type="button" onClick={() => list.refetch()} className="btn-icon sm:ml-auto" aria-label="Refresh library" title="Refresh">
              <RefreshCw className={clsx('h-4 w-4', list.isFetching && 'animate-spin')} />
            </button>
          </div>
          {list.error ? (
            <Alert variant="error" title="Unable to load the media library">
              {(list.error as ApiError).message}
            </Alert>
          ) : list.isLoading ? (
            <div className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6">
              {Array.from({ length: 12 }).map((_, i) => (
                <div key={i} className="skeleton aspect-square rounded-xl" />
              ))}
            </div>
          ) : rows.length === 0 ? (
            <EmptyState
              icon={ImageIcon}
              title={dq || folderF || type ? 'No files match your search' : 'The media library is empty'}
              description={dq || folderF || type ? 'Try another search term or clear the filters.' : 'Upload the first file to get started.'}
              action={
                dq || folderF || type ? (
                  <Button variant="secondary" onClick={() => { setQ(''); setFolderF(''); setType(accept === 'image' ? 'image' : ''); }}>
                    Clear filters
                  </Button>
                ) : canUpload ? (
                  <Button icon={UploadCloud} onClick={() => setTab('upload')}>
                    Upload files
                  </Button>
                ) : undefined
              }
            />
          ) : (
            <>
              <ul className="grid grid-cols-3 gap-3 sm:grid-cols-4 lg:grid-cols-6" role="listbox" aria-multiselectable={!!multiple} aria-label="Media files">
                {rows.map((m) => {
                  const sel = isSel(m);
                  const used = isDisabled(m);
                  return (
                    <li key={m.id} role="option" aria-selected={sel} aria-disabled={used || undefined}>
                      <button
                        type="button"
                        onClick={() => toggle(m)}
                        disabled={used}
                        onDoubleClick={() => {
                          if (!multiple && !used) {
                            onSelect([m]);
                            onClose();
                          }
                        }}
                        className={clsx(
                          'group relative block w-full overflow-hidden rounded-xl text-left ring-2 ring-offset-2 transition focus:outline-none focus-visible:ring-brand-500 dark:ring-offset-slate-900',
                          sel ? 'ring-brand-600' : 'ring-transparent hover:ring-slate-300 dark:hover:ring-slate-600',
                          used && 'cursor-not-allowed hover:ring-transparent',
                        )}
                        title={used ? `${m.title ?? m.original_name ?? ''} (${disabledLabel.toLowerCase()})` : m.title ?? m.original_name ?? ''}
                      >
                        <div className={clsx('relative aspect-square', used && 'opacity-50')}>
                          <MediaThumb item={m} className="transition duration-300 group-hover:scale-[1.03]" />
                        </div>
                        {used && (
                          <span className="absolute left-1.5 top-1.5 inline-flex items-center gap-1 rounded-md bg-emerald-600 px-1.5 py-0.5 text-[10px] font-semibold text-white shadow">
                            <Check className="h-3 w-3" aria-hidden /> {disabledLabel}
                          </span>
                        )}
                        <span
                          className={clsx(
                            'absolute right-1.5 top-1.5 inline-flex h-6 w-6 items-center justify-center rounded-full border-2 transition',
                            sel ? 'border-brand-600 bg-brand-600 text-white' : 'border-white/90 bg-black/20 text-transparent sm:opacity-0 sm:group-hover:opacity-100',
                            used && 'hidden',
                          )}
                          aria-hidden
                        >
                          <Check className="h-3.5 w-3.5" />
                        </span>
                        <span className="block truncate px-0.5 pt-1.5 text-xs font-medium text-slate-700 dark:text-slate-200">{m.title || m.original_name}</span>
                        <span className="block truncate px-0.5 text-[11px] text-slate-400">
                          {m.size_label}
                          {m.width && m.height ? ` · ${m.width}×${m.height}` : ''}
                        </span>
                      </button>
                    </li>
                  );
                })}
              </ul>
              {list.data && list.data.pages > 1 && (
                <Pagination className="mt-4 rounded-xl border border-slate-100 dark:border-slate-800" page={list.data.page} pages={list.data.pages} total={list.data.total} perPage={list.data.per_page} onPage={setPage} />
              )}
            </>
          )}
        </>
      )}
    </Modal>
  );
}

interface MediaFieldProps {
  value: string | null | undefined;
  onChange: (path: string | null, item?: MediaItem) => void;
  accept?: 'any' | 'image' | 'image-video';
  folder?: string;
  invalid?: boolean;
  id?: string;
  /** Aspect ratio class of the preview, e.g. "aspect-video" */
  aspect?: string;
  placeholder?: string;
  compact?: boolean;
  disabled?: boolean;
  /** "row" (preview left, details right on wide screens) or "stack" (preview on top — narrow sidebars) */
  layout?: 'row' | 'stack';
}

/** Form control that stores a media path: preview + "Choose from library" / "Remove". */
export function MediaField({ value, onChange, accept = 'image', folder = 'general', invalid, id, aspect = 'aspect-video', placeholder, compact, disabled, layout = 'row' }: MediaFieldProps) {
  const [open, setOpen] = useState(false);
  const isImage = !!value && /\.(jpe?g|png|webp|gif)(\?|$)/i.test(value);
  const name = value ? value.split('/').pop() : '';
  return (
    <div>
      {value ? (
        <div className={clsx('flex gap-3', compact ? 'items-center' : layout === 'stack' ? 'flex-col' : 'flex-col sm:flex-row sm:items-start')}>
          <div className={clsx('relative shrink-0 overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700', compact ? 'h-16 w-16' : clsx(layout === 'stack' ? 'w-full' : 'w-full sm:w-56', aspect), invalid && 'ring-red-300')}>
            {isImage ? (
              <img src={mediaSrc(value)} alt="" className="h-full w-full object-cover" />
            ) : (
              <MediaThumb item={{ type: /\.pdf$/i.test(value) ? 'pdf' : /\.(mp4|webm|mov)$/i.test(value) ? 'video' : 'document', path: value, alt_text: null, title: null, extension: value.split('.').pop() ?? null }} rounded="rounded-none" />
            )}
          </div>
          <div className="min-w-0 flex-1">
            <p className="truncate text-sm font-medium text-slate-700 dark:text-slate-200" title={value}>{name}</p>
            <p className="truncate text-xs text-slate-400">{value}</p>
            <div className="mt-2 flex flex-wrap gap-2">
              <Button size="xs" variant="secondary" icon={RefreshCw} onClick={() => setOpen(true)} disabled={disabled}>
                Change
              </Button>
              <Button size="xs" variant="ghost" icon={Trash2} className="!text-red-600" onClick={() => onChange(null)} disabled={disabled}>
                Remove
              </Button>
            </div>
          </div>
        </div>
      ) : (
        <button
          id={id}
          type="button"
          disabled={disabled}
          onClick={() => setOpen(true)}
          className={clsx(
            'flex w-full items-center gap-3 rounded-xl border-2 border-dashed px-4 text-left transition hover:border-brand-400 hover:bg-brand-50/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 disabled:opacity-50 dark:hover:bg-slate-800/50',
            compact ? 'py-2.5' : 'py-4',
            invalid ? 'border-red-300' : 'border-slate-200 dark:border-slate-700',
          )}
        >
          <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
            <ImagePlus className="h-5 w-5" />
          </span>
          <span className="min-w-0 text-sm">
            <span className="block font-medium text-brand-700 dark:text-brand-300">{placeholder ?? (accept === 'image' ? 'Choose an image' : 'Choose a file')}</span>
            <span className="block text-xs text-slate-500">From the media library or upload a new one</span>
          </span>
        </button>
      )}
      <MediaPicker open={open} onClose={() => setOpen(false)} accept={accept} folder={folder} onSelect={([m]) => onChange(m.path, m)} />
    </div>
  );
}
