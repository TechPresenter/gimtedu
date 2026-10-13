import { useEffect, useRef, useState } from 'react';
import clsx from 'clsx';
import { AlertCircle, CheckCircle2, RotateCcw, UploadCloud, X } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Button, ProgressBar, Select } from '@/components/ui';
import { ApiError } from '@/lib/api';
import { humanFileSize } from '@/lib/format';
import { acceptAttr, checkUpload, MEDIA_FOLDERS, mediaTypeIcon, kindOfFile, uploadWithProgress, useMediaStats } from './media';
import type { MediaItem } from './types';

interface QueueItem {
  key: string;
  file: File;
  status: 'queued' | 'uploading' | 'done' | 'error';
  progress: number;
  error?: string;
  result?: MediaItem;
  preview?: string;
}

interface MediaUploaderProps {
  /** Default folder for new files */
  folder?: string;
  /** Lock the folder selector to the default folder */
  lockFolder?: boolean;
  accept?: 'any' | 'image' | 'image-video';
  multiple?: boolean;
  /** Called once a batch has finished with the files that uploaded successfully (+ how many files in the list failed) */
  onUploaded?: (items: MediaItem[], info: { failed: number }) => void;
  /** Called after each successful file */
  onFileUploaded?: (item: MediaItem) => void;
  compact?: boolean;
  className?: string;
}

const CONCURRENCY = 2;

/**
 * Drag & drop multi-file uploader for the media library: per-type extension/size validation (limits from the server),
 * a queue with per-file progress, retry and remove. Files are stored through POST /api/media/upload.
 */
export function MediaUploader({ folder: initialFolder = 'general', lockFolder, accept = 'any', multiple = true, onUploaded, onFileUploaded, compact, className }: MediaUploaderProps) {
  const { data: stats } = useMediaStats();
  const rules = stats?.rules;
  const qc = useQueryClient();
  const inputRef = useRef<HTMLInputElement>(null);
  const [folder, setFolder] = useState(initialFolder);
  const [drag, setDrag] = useState(false);
  const [queue, setQueue] = useState<QueueItem[]>([]);
  const running = useRef(0);
  const batch = useRef<MediaItem[]>([]);
  const queueRef = useRef<QueueItem[]>([]);
  queueRef.current = queue;

  useEffect(() => setFolder(initialFolder), [initialFolder]);
  useEffect(() => () => queueRef.current.forEach((q) => q.preview && URL.revokeObjectURL(q.preview)), []);

  const patch = (key: string, p: Partial<QueueItem>) => setQueue((q) => q.map((it) => (it.key === key ? { ...it, ...p } : it)));

  const pump = () => {
    const pending = queueRef.current.filter((q) => q.status === 'queued');
    while (running.current < CONCURRENCY && pending.length) {
      const item = pending.shift()!;
      running.current++;
      item.status = 'uploading';
      patch(item.key, { status: 'uploading', progress: 1 });
      uploadWithProgress<MediaItem>('media/upload', { file: item.file, folder }, (pct) => patch(item.key, { progress: pct }))
        .then((res) => {
          patch(item.key, { status: 'done', progress: 100, result: res.data });
          batch.current.push(res.data);
          onFileUploaded?.(res.data);
        })
        .catch((e: ApiError) => patch(item.key, { status: 'error', error: e.message }))
        .finally(() => {
          running.current--;
          // Let state settle, then continue / finish the batch
          setTimeout(() => {
            const left = queueRef.current.filter((q) => q.status === 'queued' || q.status === 'uploading');
            if (!left.length && running.current === 0) {
              if (batch.current.length) {
                void qc.invalidateQueries({ queryKey: ['crud', 'media'] });
                void qc.invalidateQueries({ queryKey: ['media-stats'] });
                onUploaded?.(batch.current, { failed: queueRef.current.filter((q) => q.status === 'error').length });
              }
              batch.current = [];
            } else {
              pump();
            }
          }, 0);
        });
    }
  };

  const add = (files: FileList | File[] | null) => {
    if (!files || !files.length) return;
    const list = Array.from(files).slice(0, multiple ? 50 : 1);
    const items: QueueItem[] = list.map((file, i) => {
      const error = checkUpload(file, rules, accept);
      return {
        key: `${Date.now()}-${i}-${file.name}`,
        file,
        status: error ? 'error' : 'queued',
        progress: 0,
        error: error ?? undefined,
        preview: !error && file.type.startsWith('image/') ? URL.createObjectURL(file) : undefined,
      };
    });
    setQueue((q) => [...(multiple ? q : []), ...items]);
    setTimeout(pump, 0);
  };

  const retry = (key: string) => {
    patch(key, { status: 'queued', error: undefined, progress: 0 });
    setTimeout(pump, 0);
  };
  const remove = (key: string) => setQueue((q) => q.filter((it) => it.key !== key || it.status === 'uploading'));
  const clearFinished = () => setQueue((q) => q.filter((it) => it.status === 'queued' || it.status === 'uploading'));

  const done = queue.filter((q) => q.status === 'done').length;
  const failed = queue.filter((q) => q.status === 'error').length;
  const busy = queue.some((q) => q.status === 'uploading' || q.status === 'queued');
  const limits = rules
    ? accept === 'image'
      ? `JPG, PNG, WEBP, GIF · up to ${humanFileSize(rules.image.max_bytes)}`
      : `Images up to ${humanFileSize(rules.image.max_bytes)} · ${accept === 'image-video' ? '' : 'PDF & documents up to ' + humanFileSize(rules.document.max_bytes) + ' · '}videos up to ${humanFileSize(rules.video.max_bytes)}`
    : 'Checking upload limits…';

  return (
    <div className={className}>
      {!lockFolder && (
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <label htmlFor="upload-folder" className="text-sm font-medium text-slate-600 dark:text-slate-300">Upload to</label>
          <Select id="upload-folder" inputSize="sm" value={folder} onChange={(e) => setFolder(e.target.value)} options={MEDIA_FOLDERS} className="!w-56" />
        </div>
      )}
      <div
        role="button"
        tabIndex={0}
        aria-label="Upload files: click to browse or drop files here"
        onClick={() => inputRef.current?.click()}
        onKeyDown={(e) => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), inputRef.current?.click())}
        onDragOver={(e) => {
          e.preventDefault();
          setDrag(true);
        }}
        onDragLeave={() => setDrag(false)}
        onDrop={(e) => {
          e.preventDefault();
          setDrag(false);
          add(e.dataTransfer.files);
        }}
        className={clsx(
          'flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed text-center transition focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500',
          compact ? 'px-4 py-5' : 'px-6 py-9',
          drag ? 'border-brand-500 bg-brand-50/70 dark:bg-brand-500/10' : 'border-slate-200 bg-slate-50/60 hover:border-brand-400 hover:bg-brand-50/40 dark:border-slate-700 dark:bg-slate-800/30 dark:hover:bg-slate-800/60',
        )}
      >
        <span className={clsx('inline-flex items-center justify-center rounded-2xl bg-white text-brand-700 shadow-sm ring-1 ring-slate-200 transition dark:bg-slate-900 dark:text-brand-200 dark:ring-slate-700', compact ? 'h-10 w-10' : 'h-12 w-12', drag && '-translate-y-0.5')}>
          <UploadCloud className={compact ? 'h-5 w-5' : 'h-6 w-6'} aria-hidden />
        </span>
        <p className="mt-3 text-sm font-medium text-slate-700 dark:text-slate-200">
          <span className="text-brand-700 dark:text-brand-300">Click to browse</span> or drag & drop {multiple ? 'files' : 'a file'} here
        </p>
        <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{limits}</p>
      </div>
      <input
        ref={inputRef}
        type="file"
        multiple={multiple}
        accept={acceptAttr(rules, accept)}
        className="sr-only"
        tabIndex={-1}
        aria-hidden
        onChange={(e) => {
          add(e.target.files);
          e.target.value = '';
        }}
      />

      {queue.length > 0 && (
        <div className="mt-4">
          <div className="mb-2 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
            <span>
              {done} of {queue.length} uploaded{failed ? ` · ${failed} failed` : ''}
            </span>
            {!busy && (
              <button type="button" onClick={clearFinished} className="font-medium text-brand-700 hover:underline dark:text-brand-300">
                Clear list
              </button>
            )}
          </div>
          <ul className="max-h-72 space-y-2 overflow-y-auto pr-1">
            {queue.map((q) => {
              const Icon = mediaTypeIcon(kindOfFile(q.file.name) === 'document' ? (q.file.name.toLowerCase().endsWith('.pdf') ? 'pdf' : 'document') : (kindOfFile(q.file.name) ?? 'other'), q.file.name.split('.').pop());
              return (
                <li key={q.key} className="flex items-center gap-3 rounded-xl border border-slate-200 bg-white p-2.5 dark:border-slate-700 dark:bg-slate-900">
                  {q.preview ? (
                    <img src={q.preview} alt="" className="h-10 w-10 shrink-0 rounded-lg object-cover" />
                  ) : (
                    <span className="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-slate-500 dark:bg-slate-800">
                      <Icon className="h-5 w-5" />
                    </span>
                  )}
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center justify-between gap-2">
                      <p className="truncate text-sm font-medium text-slate-800 dark:text-slate-100">{q.file.name}</p>
                      <span className="shrink-0 text-xs text-slate-400">{humanFileSize(q.file.size)}</span>
                    </div>
                    {q.status === 'error' ? (
                      <p className="mt-0.5 flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                        <AlertCircle className="h-3.5 w-3.5 shrink-0" /> {q.error}
                      </p>
                    ) : q.status === 'done' ? (
                      <p className="mt-0.5 flex items-center gap-1 text-xs text-emerald-600 dark:text-emerald-400">
                        <CheckCircle2 className="h-3.5 w-3.5" /> Uploaded
                      </p>
                    ) : (
                      <div className="mt-1.5 flex items-center gap-2">
                        <ProgressBar value={q.progress} tone="blue" className="!h-1.5" label={`Uploading ${q.file.name}`} />
                        <span className="w-9 shrink-0 text-right text-[11px] tabular-nums text-slate-500">{q.status === 'queued' ? 'Wait' : `${q.progress}%`}</span>
                      </div>
                    )}
                  </div>
                  {q.status === 'error' && !checkUpload(q.file, rules, accept) && (
                    <button type="button" onClick={() => retry(q.key)} className="btn-icon !h-8 !w-8" aria-label={`Retry ${q.file.name}`} title="Retry">
                      <RotateCcw className="h-4 w-4" />
                    </button>
                  )}
                  {q.status !== 'uploading' && (
                    <button type="button" onClick={() => remove(q.key)} className="btn-icon !h-8 !w-8" aria-label={`Remove ${q.file.name} from the list`} title="Remove">
                      <X className="h-4 w-4" />
                    </button>
                  )}
                </li>
              );
            })}
          </ul>
          {busy && (
            <p className="mt-2 text-xs text-slate-500" aria-live="polite">
              Uploading… keep this window open until all files finish.
            </p>
          )}
          {!busy && failed > 0 && (
            <Button size="xs" variant="ghost" className="mt-2" icon={RotateCcw} onClick={() => queue.filter((q) => q.status === 'error' && !checkUpload(q.file, rules, accept)).forEach((q) => retry(q.key))}>
              Retry failed uploads
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
