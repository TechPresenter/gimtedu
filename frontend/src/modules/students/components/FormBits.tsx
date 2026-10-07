import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, Camera, Trash2, type LucideIcon } from 'lucide-react';
import { Avatar } from '@/components/ui';
import { appUrl } from '@/lib/config';
import { humanFileSize } from '@/lib/format';
import type { DuplicateMatch } from '../types';

/** Card section of the full-page form (anchor target for the sticky section nav). */
export function FormSection({ id, title, description, icon: Icon, children, aside }: { id: string; title: string; description?: string; icon: LucideIcon; children: ReactNode; aside?: ReactNode }) {
  return (
    <section id={id} data-form-section={id} className="card scroll-mt-24">
      <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-100 px-5 py-4 dark:border-slate-800">
        <div className="flex items-start gap-3">
          <span className="mt-0.5 inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200">
            <Icon className="h-[18px] w-[18px]" aria-hidden />
          </span>
          <div>
            <h2 className="card-title">{title}</h2>
            {description && <p className="card-subtitle">{description}</p>}
          </div>
        </div>
        {aside}
      </div>
      <div className="p-5">{children}</div>
    </section>
  );
}

/** Amber inline warning listing existing students that share a value. */
export function DuplicateWarning({ matches, what, children }: { matches?: DuplicateMatch[]; what: string; children?: ReactNode }) {
  if (!matches?.length) return null;
  const m = matches[0];
  return (
    <div className="mt-1.5 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2 text-xs text-amber-800 motion-safe:animate-fade-in dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200" role="status">
      <p className="flex items-start gap-1.5">
        <AlertTriangle className="mt-px h-3.5 w-3.5 shrink-0" aria-hidden />
        <span>
          This {what} is already used by{' '}
          <Link to={`/students/${m.id}`} target="_blank" className="font-semibold underline decoration-amber-400 underline-offset-2 hover:text-amber-950 dark:hover:text-white">
            {m.name} ({m.student_uid})
          </Link>
          {matches.length > 1 && ` and ${matches.length - 1} more`}
          {m.status !== 'active' && ` · ${m.status}`}.
        </span>
      </p>
      {children}
    </div>
  );
}

/** Circular photo picker with live preview, type/size validation and remove. */
export function PhotoPicker({ name, current, file, onFile, removed, onRemove, accept = '.jpg,.jpeg,.png,.webp', maxSize = 5 * 1024 * 1024, error, onError }: {
  name: string;
  current: string | null;
  file: File | null;
  onFile: (f: File | null) => void;
  removed: boolean;
  onRemove: () => void;
  accept?: string;
  maxSize?: number;
  error?: string;
  onError: (m: string) => void;
}) {
  const ref = useRef<HTMLInputElement>(null);
  const [preview, setPreview] = useState<string | null>(null);
  useEffect(() => {
    if (!file) {
      setPreview(null);
      return;
    }
    const url = URL.createObjectURL(file);
    setPreview(url);
    return () => URL.revokeObjectURL(url);
  }, [file]);
  const shown = preview ?? (!removed && current ? appUrl(current) : null);
  const pick = (f?: File | null) => {
    if (!f) return;
    const ext = `.${f.name.split('.').pop()?.toLowerCase()}`;
    if (!accept.split(',').includes(ext)) return onError(`Photo must be ${accept.replace(/\./g, '').toUpperCase().replace(/,/g, ', ')}.`);
    if (f.size > maxSize) return onError(`Photo must be smaller than ${humanFileSize(maxSize)}.`);
    onFile(f);
  };
  return (
    <div className="flex items-center gap-4">
      <button
        type="button"
        onClick={() => ref.current?.click()}
        className={clsx('group relative h-24 w-24 shrink-0 overflow-hidden rounded-2xl ring-4 ring-white transition hover:ring-brand-100 focus-visible:ring-brand-300 dark:ring-slate-800', error && '!ring-red-200')}
        aria-label="Upload student photo"
      >
        {shown ? <img src={shown} alt="" className="h-full w-full object-cover" /> : <Avatar name={name || 'Student'} size="xl" className="!h-24 !w-24 !rounded-2xl !text-2xl" />}
        <span className="absolute inset-0 flex items-center justify-center bg-brand-950/0 text-white opacity-0 transition group-hover:bg-brand-950/45 group-hover:opacity-100 group-focus-visible:bg-brand-950/45 group-focus-visible:opacity-100">
          <Camera className="h-6 w-6" aria-hidden />
        </span>
      </button>
      <div className="min-w-0 text-sm">
        <p className="font-semibold text-slate-800 dark:text-slate-100">Profile photo</p>
        <p className="text-xs text-slate-500 dark:text-slate-400">Passport-size, plain background. JPG/PNG/WEBP up to {humanFileSize(maxSize)}.</p>
        <div className="mt-2 flex flex-wrap gap-2">
          <button type="button" onClick={() => ref.current?.click()} className="btn btn-secondary btn-xs">
            <Camera className="h-3.5 w-3.5" /> {shown ? 'Change' : 'Upload'}
          </button>
          {(file || (current && !removed)) && (
            <button type="button" onClick={() => (file ? onFile(null) : onRemove())} className="btn btn-ghost btn-xs !text-red-600">
              <Trash2 className="h-3.5 w-3.5" /> Remove
            </button>
          )}
        </div>
        {file && <p className="mt-1 truncate text-xs text-slate-500">{file.name} · {humanFileSize(file.size)}</p>}
        {error && <p className="form-error">{error}</p>}
      </div>
      <input ref={ref} type="file" accept={accept} className="sr-only" onChange={(e) => { pick(e.target.files?.[0]); e.target.value = ''; }} />
    </div>
  );
}
