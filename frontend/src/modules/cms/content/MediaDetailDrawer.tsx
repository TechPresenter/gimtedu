import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { AlertTriangle, Copy, Download, ExternalLink, FileWarning, Link2, RefreshCw, Save, Trash2 } from 'lucide-react';
import { Alert, Button, DescriptionList, Drawer, Field, Input, ProgressBar, Select, Skeleton, Textarea, useConfirm, useToast } from '@/components/ui';
import { api, ApiError } from '@/lib/api';
import { useAuth } from '@/lib/auth';
import { formatDateTime, labelize } from '@/lib/format';
import { useApi } from '@/lib/queries';
import { copyText, MEDIA_FOLDERS, MediaThumb, mediaSrc, uploadWithProgress, useMediaStats, acceptAttr } from './media';
import type { MediaItem } from './types';

interface MediaDetailDrawerProps {
  id: number | null;
  onClose: () => void;
  /** After metadata/file changes or deletion */
  onChanged?: () => void;
}

/** Media item details: preview, metadata form (title, alt, caption, folder), copy URL, replace file, usage and delete. */
export function MediaDetailDrawer({ id, onClose, onChanged }: MediaDetailDrawerProps) {
  const toast = useToast();
  const confirm = useConfirm();
  const qc = useQueryClient();
  const { can } = useAuth();
  const canEdit = can('media', 'edit');
  const { data: stats } = useMediaStats(!!id);
  const { data: item, isLoading, error, refetch } = useApi<MediaItem>(['media-item', id], `media/${id}`, undefined, { enabled: !!id });
  const [form, setForm] = useState({ title: '', alt_text: '', caption: '', folder: 'general' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [saving, setSaving] = useState(false);
  const [progress, setProgress] = useState<number | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (item) {
      setForm({ title: item.title ?? '', alt_text: item.alt_text ?? '', caption: item.caption ?? '', folder: item.folder ?? 'general' });
      setErrors({});
    }
  }, [item]);

  const dirty = !!item && (form.title !== (item.title ?? '') || form.alt_text !== (item.alt_text ?? '') || form.caption !== (item.caption ?? '') || form.folder !== (item.folder ?? 'general'));
  const invalidate = async () => {
    await Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'media'] }), qc.invalidateQueries({ queryKey: ['media-stats'] }), refetch()]);
    onChanged?.();
  };

  const save = async () => {
    if (!item) return;
    setSaving(true);
    setErrors({});
    try {
      const res = await api.post(`crud/media/${item.id}`, form);
      toast.success(res.message || 'Details saved.');
      await invalidate();
    } catch (e) {
      const err = e as ApiError;
      setErrors(err.errors ?? {});
      if (!Object.keys(err.errors ?? {}).length) toast.error(err.message);
    } finally {
      setSaving(false);
    }
  };

  const replace = async (file: File | undefined) => {
    if (!item || !file) return;
    const ok = await confirm({
      title: 'Replace this file?',
      message: (
        <>
          <strong>{file.name}</strong> will replace <strong>{item.original_name ?? item.filename}</strong>.
          {item.usage?.length ? ` It is used in ${item.usage.length} place(s); they will show the new file.` : ''} This cannot be undone.
        </>
      ),
      confirmText: 'Replace file',
    });
    if (!ok) return;
    setProgress(1);
    try {
      const res = await uploadWithProgress<MediaItem>(`media/${item.id}/replace`, { file }, setProgress);
      toast.success(res.message);
      await invalidate();
    } catch (e) {
      toast.error((e as ApiError).message);
    } finally {
      setProgress(null);
    }
  };

  const remove = async () => {
    if (!item) return;
    const used = item.usage ?? [];
    const ok = await confirm({
      title: used.length ? 'This file is in use' : 'Delete this file?',
      danger: true,
      confirmText: used.length ? 'Delete anyway' : 'Delete file',
      message: used.length ? (
        <div className="space-y-2">
          <p>
            <strong>{item.title || item.original_name}</strong> is used in {used.length} place{used.length === 1 ? '' : 's'}. Deleting it will leave broken images or links there:
          </p>
          <ul className="max-h-40 list-disc space-y-0.5 overflow-y-auto pl-5 text-xs">
            {used.slice(0, 10).map((u, i) => (
              <li key={i}>
                {u.label}
                {u.title ? ` — ${u.title}` : ''}
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <>
          <strong>{item.title || item.original_name}</strong> will be permanently removed from the media library.
        </>
      ),
    });
    if (!ok) return;
    try {
      const res = await api.del(`media/${item.id}`, undefined).catch(async (e: ApiError) => {
        if (e.status === 409) return api.del(`media/${item.id}?force=1`);
        throw e;
      });
      toast.success(res.message || 'File deleted.');
      await Promise.all([qc.invalidateQueries({ queryKey: ['crud', 'media'] }), qc.invalidateQueries({ queryKey: ['media-stats'] })]);
      onChanged?.();
      onClose();
    } catch (e) {
      toast.error((e as ApiError).message);
    }
  };

  const copy = async () => {
    if (!item) return;
    const ok = await copyText(item.absolute_url);
    if (ok) toast.success('File URL copied to the clipboard.');
    else toast.error('Unable to copy. Select the URL and copy it manually.');
  };

  return (
    <Drawer
      open={!!id}
      onClose={onClose}
      width="max-w-2xl"
      title={item ? item.title || item.original_name || 'Media file' : 'Media file'}
      description={item ? `${labelize(item.type)} · ${item.size_label}${item.width && item.height ? ` · ${item.width} × ${item.height}px` : ''}` : undefined}
      footer={
        item && (
          <>
            {can('media', 'delete') && (
              <Button variant="ghost" icon={Trash2} className="mr-auto !text-red-600" onClick={remove}>
                Delete
              </Button>
            )}
            <Button variant="secondary" onClick={onClose}>
              Close
            </Button>
            {canEdit && (
              <Button icon={Save} loading={saving} disabled={!dirty} onClick={save}>
                Save details
              </Button>
            )}
          </>
        )
      }
    >
      {error ? (
        <Alert variant="error" title="Unable to load this file">
          {(error as ApiError).message}
        </Alert>
      ) : isLoading || !item ? (
        <div className="space-y-4">
          <Skeleton className="h-64 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      ) : (
        <div className="space-y-6">
          {/* Preview */}
          <div className="overflow-hidden rounded-2xl border border-slate-200 bg-[repeating-conic-gradient(#f1f5f9_0%_25%,#fff_0%_50%)] bg-[length:20px_20px] dark:border-slate-700 dark:bg-[repeating-conic-gradient(#1e293b_0%_25%,#0f172a_0%_50%)]">
            {item.type === 'image' ? (
              <img src={mediaSrc(item.path, item.updated_at)} alt={item.alt_text ?? ''} className="mx-auto max-h-80 w-auto object-contain" />
            ) : item.type === 'video' ? (
              <video src={mediaSrc(item.path, item.updated_at)} controls preload="metadata" className="max-h-80 w-full bg-black" />
            ) : item.type === 'pdf' ? (
              <iframe src={mediaSrc(item.path, item.updated_at)} title={item.title ?? 'PDF preview'} className="h-80 w-full bg-white" />
            ) : (
              <div className="flex h-56 flex-col items-center justify-center gap-3">
                <div className="h-24 w-24"><MediaThumb item={item} /></div>
                <p className="text-sm text-slate-500">Preview is not available — download the file to view it.</p>
              </div>
            )}
          </div>

          {/* Quick actions */}
          <div className="flex flex-wrap gap-2">
            <Button size="sm" variant="secondary" icon={Copy} onClick={copy}>Copy URL</Button>
            <Button size="sm" variant="secondary" icon={ExternalLink} href={mediaSrc(item.path, item.updated_at)} target="_blank">Open</Button>
            <a className="btn btn-secondary btn-sm" href={mediaSrc(item.path)} download={item.original_name ?? item.filename}>
              <Download className="h-4 w-4" aria-hidden /> Download
            </a>
            {canEdit && (
              <>
                <Button size="sm" variant="secondary" icon={RefreshCw} onClick={() => fileRef.current?.click()} disabled={progress !== null}>
                  Replace file
                </Button>
                <input
                  ref={fileRef}
                  type="file"
                  className="sr-only"
                  tabIndex={-1}
                  aria-hidden
                  accept={acceptAttr(stats?.rules, item.type === 'image' ? 'image' : 'any')}
                  onChange={(e) => {
                    void replace(e.target.files?.[0]);
                    e.target.value = '';
                  }}
                />
              </>
            )}
          </div>
          {progress !== null && (
            <div className="flex items-center gap-3">
              <ProgressBar value={progress} label="Replacing file" />
              <span className="w-10 text-right text-xs tabular-nums text-slate-500">{progress}%</span>
            </div>
          )}
          {item.is_bundled && (
            <p className="flex items-start gap-2 rounded-xl bg-slate-50 px-3 py-2 text-xs text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
              <FileWarning className="mt-0.5 h-3.5 w-3.5 shrink-0" /> This is a bundled website photo. Replacing it uploads a new copy to the library and updates the pages that use it; the original file is kept.
            </p>
          )}

          {/* Metadata */}
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Title" required htmlFor="m-title" error={errors.title} className="sm:col-span-2">
              <Input id="m-title" value={form.title} maxLength={190} onChange={(e) => setForm({ ...form, title: e.target.value })} disabled={!canEdit} invalid={!!errors.title} />
            </Field>
            {item.type === 'image' && (
              <Field
                label="Alt text"
                htmlFor="m-alt"
                error={errors.alt_text}
                className="sm:col-span-2"
                hint={!form.alt_text ? <span className="inline-flex items-center gap-1 text-amber-600"><AlertTriangle className="h-3.5 w-3.5" /> Missing — describe the image for screen readers and SEO.</span> : 'Describe what the image shows.'}
                aside={`${form.alt_text.length} / 255`}
              >
                <Input id="m-alt" value={form.alt_text} maxLength={255} onChange={(e) => setForm({ ...form, alt_text: e.target.value })} disabled={!canEdit} invalid={!!errors.alt_text} placeholder="e.g. Students studying on the campus lawn" />
              </Field>
            )}
            <Field label="Caption" htmlFor="m-caption" error={errors.caption} className="sm:col-span-2">
              <Textarea id="m-caption" rows={2} value={form.caption} maxLength={500} onChange={(e) => setForm({ ...form, caption: e.target.value })} disabled={!canEdit} invalid={!!errors.caption} />
            </Field>
            <Field label="Folder" htmlFor="m-folder" error={errors.folder}>
              <Select id="m-folder" value={form.folder} onChange={(e) => setForm({ ...form, folder: e.target.value })} options={MEDIA_FOLDERS} disabled={!canEdit} />
            </Field>
          </div>

          {/* Info */}
          <div className="rounded-2xl border border-slate-200 p-4 dark:border-slate-700">
            <DescriptionList
              columns={2}
              items={[
                { label: 'File name', value: <span className="break-all">{item.original_name ?? item.filename}</span> },
                { label: 'Type', value: `${labelize(item.type)}${item.extension ? ` (.${item.extension})` : ''}` },
                { label: 'Size', value: item.size_label },
                { label: 'Dimensions', value: item.width && item.height ? `${item.width} × ${item.height} px` : '—' },
                { label: 'Uploaded by', value: item.uploaded_by_name ?? '—' },
                { label: 'Uploaded on', value: formatDateTime(item.created_at) },
                {
                  label: 'File URL',
                  full: true,
                  value: (
                    <span className="flex items-center gap-2">
                      <code className="min-w-0 flex-1 truncate rounded-lg bg-slate-100 px-2 py-1 text-xs dark:bg-slate-800">{item.absolute_url}</code>
                      <button type="button" className="btn-icon !h-8 !w-8 shrink-0" onClick={copy} aria-label="Copy URL" title="Copy URL"><Link2 className="h-4 w-4" /></button>
                    </span>
                  ),
                },
              ]}
            />
          </div>

          {/* Usage */}
          <div>
            <h3 className="mb-2 text-sm font-semibold text-slate-900 dark:text-white">Where it is used</h3>
            {item.usage && item.usage.length ? (
              <ul className="divide-y divide-slate-100 rounded-2xl border border-slate-200 dark:divide-slate-800 dark:border-slate-700">
                {item.usage.map((u, i) => (
                  <li key={i} className="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                    <span className="min-w-0">
                      <span className="block text-xs text-slate-500">{u.label}</span>
                      <span className="block truncate font-medium text-slate-800 dark:text-slate-100">{u.title || '—'}</span>
                    </span>
                    {u.link && (
                      <Link to={u.link} onClick={onClose} className="shrink-0 text-xs font-semibold text-brand-700 hover:underline dark:text-brand-300">
                        Open
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="rounded-2xl border border-dashed border-slate-200 px-4 py-4 text-sm text-slate-500 dark:border-slate-700">Not used on any page, post or album yet — safe to delete.</p>
            )}
          </div>
        </div>
      )}
    </Drawer>
  );
}
