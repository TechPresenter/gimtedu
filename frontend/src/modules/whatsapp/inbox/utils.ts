import { apiUrl, getCsrfToken } from '@/lib/api';
import { parseServerDate, type WaMediaUpload } from '../shared';

/** Tag chip colours (literal classes so Tailwind keeps them). */
export const TAG_CLASSES: Record<string, string> = {
  blue: 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-400/30',
  green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30',
  amber: 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30',
  red: 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/30',
  violet: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/30',
  purple: 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/30',
  slate: 'bg-slate-100 text-slate-700 ring-slate-500/20 dark:bg-slate-700/40 dark:text-slate-300 dark:ring-slate-400/30',
  cyan: 'bg-cyan-50 text-cyan-700 ring-cyan-600/20 dark:bg-cyan-500/10 dark:text-cyan-300 dark:ring-cyan-400/30',
  pink: 'bg-pink-50 text-pink-700 ring-pink-600/20 dark:bg-pink-500/10 dark:text-pink-300 dark:ring-pink-400/30',
  orange: 'bg-orange-50 text-orange-700 ring-orange-600/20 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-400/30',
  navy: 'bg-brand-50 text-brand-800 ring-brand-700/20 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-400/30',
};
export const TAG_COLORS = ['blue', 'green', 'amber', 'red', 'violet', 'slate', 'cyan', 'pink', 'orange', 'navy'] as const;
export const TAG_SWATCH: Record<string, string> = {
  blue: 'bg-blue-500', green: 'bg-emerald-500', amber: 'bg-amber-500', red: 'bg-red-500', violet: 'bg-violet-500', slate: 'bg-slate-500',
  cyan: 'bg-cyan-500', pink: 'bg-pink-500', orange: 'bg-orange-500', navy: 'bg-brand-800',
};
export const tagClass = (c: string | null | undefined) => TAG_CLASSES[c ?? 'slate'] ?? TAG_CLASSES.slate;

const IST = 'Asia/Kolkata';
const dayKey = (d: Date) => d.toLocaleDateString('en-CA', { timeZone: IST });

/** List time: 10:42 am today, "Yesterday", weekday within a week, else 27 Oct. */
export function listTime(v: string | null | undefined): string {
  const d = parseServerDate(v);
  if (!d) return '';
  const now = new Date();
  if (dayKey(d) === dayKey(now)) return d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: IST });
  const y = new Date(now.getTime() - 86400000);
  if (dayKey(d) === dayKey(y)) return 'Yesterday';
  if (now.getTime() - d.getTime() < 6 * 86400000) return d.toLocaleDateString('en-IN', { weekday: 'short', timeZone: IST });
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', timeZone: IST });
}

/** Date separator label inside the chat. */
export function dayLabel(v: string | null | undefined): string {
  const d = parseServerDate(v);
  if (!d) return '';
  const now = new Date();
  if (dayKey(d) === dayKey(now)) return 'Today';
  if (dayKey(d) === dayKey(new Date(now.getTime() - 86400000))) return 'Yesterday';
  return d.toLocaleDateString('en-IN', { weekday: 'long', day: 'numeric', month: 'long', year: now.getFullYear() === d.getFullYear() ? undefined : 'numeric', timeZone: IST });
}

export function messageDayKey(v: string | null | undefined): string {
  const d = parseServerDate(v);
  return d ? dayKey(d) : '';
}

/** "Now" in the server's format (Asia/Kolkata), for optimistic messages. */
export function serverNow(): string {
  const parts = new Intl.DateTimeFormat('en-CA', { timeZone: IST, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false }).formatToParts(new Date());
  const g = (t: string) => parts.find((p) => p.type === t)?.value ?? '00';
  return `${g('year')}-${g('month')}-${g('day')} ${g('hour') === '24' ? '00' : g('hour')}:${g('minute')}:${g('second')}`;
}

export function formatSecs(s: number | null | undefined): string {
  if (s === null || s === undefined) return '—';
  if (s < 60) return `${s}s`;
  const m = Math.round(s / 60);
  if (m < 60) return `${m} min`;
  const h = Math.floor(m / 60);
  return `${h}h ${m % 60}m`;
}

/* ------------------------------------------------------------------ attachments */

export type AttachKind = 'image' | 'video' | 'document' | 'audio';

/** WhatsApp (Meta) session-media limits. */
export const ATTACH_RULES: Record<AttachKind, { label: string; accept: string; mimes: string[]; exts: string[]; max: number }> = {
  image: { label: 'Photo', accept: 'image/jpeg,image/png,image/webp', mimes: ['image/jpeg', 'image/png', 'image/webp'], exts: ['jpg', 'jpeg', 'png', 'webp'], max: 5 * 1024 * 1024 },
  video: { label: 'Video', accept: 'video/mp4,video/3gpp', mimes: ['video/mp4', 'video/3gpp'], exts: ['mp4', '3gp'], max: 16 * 1024 * 1024 },
  audio: { label: 'Audio', accept: 'audio/aac,audio/mp4,audio/mpeg,audio/amr,audio/ogg,.m4a,.mp3,.aac,.amr,.ogg', mimes: ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'audio/x-m4a', 'audio/m4a'], exts: ['aac', 'm4a', 'mp3', 'amr', 'ogg'], max: 16 * 1024 * 1024 },
  // same list as core's wa_media_types() (CSV is not a WhatsApp document type there)
  document: {
    label: 'Document',
    accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt',
    mimes: [],
    exts: ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'],
    max: 16 * 1024 * 1024,
  },
};

export function validateAttachment(kind: AttachKind, file: File): string | null {
  const rule = ATTACH_RULES[kind];
  const ext = (file.name.split('.').pop() ?? '').toLowerCase();
  const typeOk = rule.exts.includes(ext) || (file.type !== '' && rule.mimes.includes(file.type));
  if (!typeOk) return `${rule.label}: ${rule.exts.map((e) => e.toUpperCase()).join(', ')} files only.`;
  if (file.size > rule.max) return `${rule.label} is too large — WhatsApp allows up to ${Math.round(rule.max / 1024 / 1024)} MB.`;
  if (file.size === 0) return 'The file is empty.';
  return null;
}

/** POST whatsapp/media/upload with upload progress (XHR — fetch has no upload progress). */
export function uploadWithProgress(file: File, kind: AttachKind, onProgress: (pct: number) => void, signal?: AbortSignal): Promise<WaMediaUpload> {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', apiUrl('whatsapp/media/upload'));
    xhr.withCredentials = true;
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.setRequestHeader('X-CSRF-Token', getCsrfToken());
    xhr.upload.onprogress = (e) => {
      if (e.lengthComputable) onProgress(Math.round((e.loaded / e.total) * 100));
    };
    xhr.onerror = () => reject(new Error('Upload failed — check your connection and try again.'));
    xhr.onabort = () => reject(new DOMException('Aborted', 'AbortError'));
    xhr.onload = () => {
      let json: { ok?: boolean; data?: WaMediaUpload; message?: string; errors?: Record<string, string> } | null = null;
      try {
        json = JSON.parse(xhr.responseText);
      } catch {
        json = null;
      }
      if (xhr.status >= 200 && xhr.status < 300 && json?.ok && json.data) resolve(json.data);
      else {
        const fieldMsg = json?.errors ? Object.values(json.errors)[0] : null;
        reject(new Error(fieldMsg || json?.message || `Upload failed (${xhr.status}).`));
      }
    };
    signal?.addEventListener('abort', () => xhr.abort());
    const fd = new FormData();
    fd.append('file', file);
    fd.append('type', kind);
    xhr.send(fd);
  });
}

export function newClientRef(): string {
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
}
