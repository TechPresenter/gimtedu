import clsx from 'clsx';
import { FileArchive, FileSpreadsheet, FileText, FileVideo, ImageIcon, Presentation, type LucideIcon } from 'lucide-react';
import { api, apiUrl, ApiError, getCsrfToken, setCsrfToken } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { humanFileSize } from '@/lib/format';
import { useApi } from '@/lib/queries';
import type { MediaItem, MediaStats, MediaType } from './types';

/** Folders of the media library (mirrors media_folders() in app/services/media.php). */
export const MEDIA_FOLDERS: { value: string; label: string }[] = [
  { value: 'general', label: 'General' },
  { value: 'campus', label: 'Campus' },
  { value: 'programs', label: 'Programs' },
  { value: 'blog', label: 'Blog & News' },
  { value: 'gallery', label: 'Gallery' },
  { value: 'events', label: 'Events' },
  { value: 'placements', label: 'Placements' },
  { value: 'people', label: 'People' },
  { value: 'testimonials', label: 'Testimonials' },
  { value: 'banners', label: 'Banners' },
  { value: 'documents', label: 'Documents & Brochures' },
  { value: 'videos', label: 'Videos' },
  { value: 'page-builder', label: 'Page Builder' },
];

export const folderLabel = (v: string | null | undefined) => MEDIA_FOLDERS.find((f) => f.value === v)?.label ?? (v || 'General');

export const MEDIA_TYPES: { value: MediaType; label: string }[] = [
  { value: 'image', label: 'Images' },
  { value: 'video', label: 'Videos' },
  { value: 'pdf', label: 'PDFs' },
  { value: 'document', label: 'Documents' },
];

const typeMeta: Record<MediaType, { icon: LucideIcon; tone: string }> = {
  image: { icon: ImageIcon, tone: 'bg-blue-50 text-blue-600 dark:bg-blue-500/15 dark:text-blue-300' },
  video: { icon: FileVideo, tone: 'bg-violet-50 text-violet-600 dark:bg-violet-500/15 dark:text-violet-300' },
  pdf: { icon: FileText, tone: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300' },
  document: { icon: FileSpreadsheet, tone: 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/15 dark:text-cyan-300' },
  other: { icon: FileArchive, tone: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' },
};

export function mediaTypeIcon(type: string, ext?: string | null): LucideIcon {
  if (type === 'document' && ext && ['ppt', 'pptx'].includes(ext)) return Presentation;
  return (typeMeta[type as MediaType] ?? typeMeta.other).icon;
}

/** Public URL of a stored path (library uploads and bundled assets). */
export function mediaSrc(path: string | null | undefined, version?: string | null): string {
  if (!path) return '';
  const url = appUrl(path);
  return version ? `${url}${url.includes('?') ? '&' : '?'}v=${encodeURIComponent(version)}` : url;
}

/** Thumbnail tile for any media type (image preview or a tinted file icon). */
export function MediaThumb({ item, className, iconClassName, rounded = 'rounded-xl' }: {
  item: Pick<MediaItem, 'type' | 'path' | 'alt_text' | 'title' | 'extension'> & { updated_at?: string | null };
  className?: string;
  iconClassName?: string;
  rounded?: string;
}) {
  if (item.type === 'image') {
    return (
      <img
        src={mediaSrc(item.path, item.updated_at)}
        alt={item.alt_text || item.title || ''}
        loading="lazy"
        className={clsx('h-full w-full bg-slate-100 object-cover dark:bg-slate-800', rounded, className)}
      />
    );
  }
  const meta = typeMeta[item.type] ?? typeMeta.other;
  const Icon = mediaTypeIcon(item.type, item.extension);
  return (
    <div className={clsx('flex h-full w-full flex-col items-center justify-center gap-1.5', meta.tone, rounded, className)}>
      <Icon className={clsx('h-8 w-8', iconClassName)} aria-hidden />
      {item.extension && <span className="text-[10px] font-bold uppercase tracking-wider opacity-80">{item.extension}</span>}
    </div>
  );
}

/** Upload limits + library counts (cached). */
export function useMediaStats(enabled = true) {
  return useApi<MediaStats>(['media-stats'], 'media/stats', undefined, { enabled, staleTime: 30_000 });
}

export type UploadKind = 'image' | 'video' | 'document';

export function kindOfFile(name: string, rules?: MediaStats['rules']): UploadKind | null {
  const ext = name.split('.').pop()?.toLowerCase() ?? '';
  if (!rules) {
    if (['jpg', 'jpeg', 'png', 'webp', 'gif'].includes(ext)) return 'image';
    if (['mp4', 'webm', 'mov'].includes(ext)) return 'video';
    if (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'ppt', 'pptx'].includes(ext)) return 'document';
    return null;
  }
  return (Object.keys(rules) as UploadKind[]).find((k) => rules[k].extensions.includes(ext)) ?? null;
}

/** Client-side check before upload (the server validates again). Returns an error message or null. */
export function checkUpload(file: File, rules: MediaStats['rules'] | undefined, accept: 'any' | 'image' | 'image-video'): string | null {
  const ext = file.name.split('.').pop()?.toLowerCase() ?? '';
  const kind = kindOfFile(file.name, rules);
  if (!kind) return `.${ext} files are not allowed.`;
  if (accept === 'image' && kind !== 'image') return 'Only images can be used here.';
  if (accept === 'image-video' && kind === 'document') return 'Only images and videos can be added here.';
  const max = rules?.[kind]?.max_bytes;
  if (max && file.size > max) return `Too large — ${kind}s must be under ${humanFileSize(max)}.`;
  if (file.size === 0) return 'The file is empty.';
  return null;
}

export function acceptAttr(rules: MediaStats['rules'] | undefined, accept: 'any' | 'image' | 'image-video'): string {
  const exts = !rules
    ? accept === 'image' ? ['jpg', 'jpeg', 'png', 'webp', 'gif'] : []
    : accept === 'image' ? rules.image.extensions : accept === 'image-video' ? [...rules.image.extensions, ...rules.video.extensions] : [...rules.image.extensions, ...rules.video.extensions, ...rules.document.extensions];
  return exts.map((e) => `.${e}`).join(',');
}

async function refreshCsrf() {
  const res = await api.get<{ csrf_token: string }>('auth/session');
  setCsrfToken(res.csrf_token);
}

/**
 * Multipart POST with upload progress (fetch has no upload progress events). Retries once on an expired CSRF token.
 * Resolves with the API envelope's data; rejects with ApiError (message + field errors).
 */
export function uploadWithProgress<T>(path: string, fields: Record<string, string | Blob>, onProgress?: (pct: number) => void, retried = false): Promise<{ data: T; message: string }> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    const fd = new FormData();
    Object.entries(fields).forEach(([k, v]) => fd.append(k, v));
    xhr.open('POST', apiUrl(path));
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('X-CSRF-Token', getCsrfToken());
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable && onProgress) onProgress(Math.min(99, Math.round((e.loaded / e.total) * 100)));
    };
    xhr.onerror = () => reject(new ApiError('Unable to reach the server. Check your connection and try again.', 0));
    xhr.onload = () => {
      let json: { ok?: boolean; data?: T; message?: string; errors?: Record<string, string> } | null = null;
      try {
        json = JSON.parse(xhr.responseText);
      } catch {
        json = null;
      }
      if (xhr.status === 419 && !retried) {
        refreshCsrf()
          .then(() => uploadWithProgress<T>(path, fields, onProgress, true))
          .then(resolve, reject);
        return;
      }
      if (xhr.status === 401) window.dispatchEvent(new CustomEvent('auth:expired'));
      if (xhr.status < 200 || xhr.status >= 300 || !json || json.ok === false) {
        const errors = json?.errors ?? {};
        const msg = errors.file || json?.message || (xhr.status === 413 ? 'The file is larger than the server allows.' : `Upload failed (${xhr.status}).`);
        reject(new ApiError(msg, xhr.status, errors));
        return;
      }
      onProgress?.(100);
      resolve({ data: json.data as T, message: json.message ?? '' });
    };
    xhr.send(fd);
  });
}

/** Copy text to the clipboard (with a fallback for non-secure contexts). */
export async function copyText(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    const ok = document.execCommand('copy');
    ta.remove();
    return ok;
  }
}
