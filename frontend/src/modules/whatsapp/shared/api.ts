import { useQuery, useQueryClient } from '@tanstack/react-query';
import { api, apiUrl } from '@/lib/api';
import type { WaMediaUpload, WaMessage, WaSendResult, WaStatus, WaVariable, WaWorkerStats } from './types';

/**
 * WhatsApp API helpers shared by every WhatsApp page. Every query key starts with 'wa' so useWaRefresh() can
 * invalidate the whole module after an action (send, simulate, worker run …).
 */
export const waKeys = {
  all: ['wa'] as const,
  status: ['wa', 'status'] as const,
  variables: ['wa', 'variables'] as const,
  area: (area: string, ...rest: unknown[]) => ['wa', area, ...rest] as const,
};

/** Invalidate every cached WhatsApp query (or only the given areas: useWaRefresh()('inbox', 'contacts')). */
export function useWaRefresh() {
  const qc = useQueryClient();
  return (...areas: string[]) =>
    areas.length
      ? Promise.all(areas.map((a) => qc.invalidateQueries({ queryKey: ['wa', a] })))
      : qc.invalidateQueries({ queryKey: ['wa'] });
}

/** GET whatsapp/status — mode, configuration, queue sizes and worker health. Polls in the background. */
export function useWaStatus(opts: { refetchInterval?: number | false; enabled?: boolean } = {}) {
  return useQuery({
    queryKey: waKeys.status,
    queryFn: ({ signal }) => api.get<WaStatus>('whatsapp/status', undefined, { signal, background: true }),
    refetchInterval: opts.refetchInterval ?? 60_000,
    refetchIntervalInBackground: false,
    staleTime: 30_000,
    enabled: opts.enabled ?? true,
  });
}

/** GET whatsapp/variables — the GIMT variable catalog (student_name, application_number, …). Cached for the session. */
export function useWaVariables() {
  return useQuery({
    queryKey: waKeys.variables,
    queryFn: ({ signal }) => api.get<WaVariable[]>('whatsapp/variables', undefined, { signal }),
    staleTime: 10 * 60_000,
  });
}

/**
 * A URL that is safe to put in href / src: http(s) or site-relative ('/assets/…'). Anything else (javascript:, data:,
 * vbscript:, protocol-relative '//host') returns null — message media URLs can come from webhooks, CSV uploads or
 * event payloads, so they are never trusted.
 */
export function waSafeUrl(v: string | null | undefined): string | null {
  if (!v) return null;
  const s = String(v).trim();
  if (/^https?:\/\/[^\s]+$/i.test(s)) return s;
  if (s.startsWith('/') && !s.startsWith('//') && !/\s/.test(s)) return s;
  return null;
}

/** Same-origin URL that streams a message's media (inbound copies are private; outbound media are public URLs). */
export function waMediaUrl(m: Pick<WaMessage, 'id' | 'media_url' | 'media_local' | 'direction'> & { media_proxy_url?: string | null }): string | null {
  if (m.media_proxy_url) return waSafeUrl(m.media_proxy_url);
  if (m.media_local) return apiUrl(`whatsapp/media/${m.id}`);
  return waSafeUrl(m.media_url);
}

/** POST whatsapp/media/upload — stores a public copy and returns its absolute URL (for media sends / template headers). */
export async function waUploadMedia(file: File, kind?: WaMediaUpload['type']): Promise<WaMediaUpload> {
  const fd = new FormData();
  fd.append('file', file);
  if (kind) fd.append('type', kind);
  const res = await api.post<WaMediaUpload>('whatsapp/media/upload', fd);
  return res.data;
}

/** POST whatsapp/worker/run — runs one worker tick now (manage permission). */
export function waRunWorker() {
  return api.post<WaWorkerStats>('whatsapp/worker/run');
}

/** POST whatsapp/simulate/inbound — pretend a contact sent a message (test mode only). */
export function waSimulateInbound(body: {
  phone: string;
  text?: string;
  type?: 'text' | 'image' | 'document' | 'audio' | 'video' | 'location' | 'button_reply' | 'list_reply' | 'sticker';
  media_url?: string;
  name?: string;
}) {
  return api.post<{ webhook_log_id: number | null; status: string; note: string | null; message_id: number | null; outbox: Record<string, number | string> }>(
    'whatsapp/simulate/inbound',
    body,
  );
}

/** POST whatsapp/simulate/status — pretend Interakt reported sent / delivered / read / failed for a message. */
export function waSimulateStatus(body: { message_id: number; status: 'sent' | 'delivered' | 'read' | 'failed'; error?: string }) {
  return api.post<{ webhook_log_id: number | null; status: string; message_status: string; note: string | null }>('whatsapp/simulate/status', body);
}

/** Decode a JSON column that may arrive as a string (LONGTEXT) or already decoded. */
export function parseJson<T>(v: unknown, fallback: T | null = null): T | null {
  if (v === null || v === undefined || v === '') return fallback;
  if (typeof v !== 'string') return v as T;
  try {
    return JSON.parse(v) as T;
  } catch {
    return fallback;
  }
}

/** Human message for a refused / failed send (wa_send codes). */
export function waSendError(r: Pick<WaSendResult, 'code' | 'error'>): string {
  const map: Record<string, string> = {
    invalid_phone: 'This is not a valid WhatsApp number.',
    blocked: 'This contact is blocked.',
    opted_out: 'The contact has not opted in to WhatsApp marketing messages.',
    window_closed: 'The 24-hour window is closed — send an approved template instead.',
    template_not_approved: 'The template is not approved yet.',
    template_not_found: 'The template no longer exists.',
    missing_variables: 'Some template variables have no value.',
    invalid_message: 'The message is incomplete or not supported by WhatsApp.',
    not_failed: 'Only failed messages can be retried.',
  };
  return r.error || (r.code ? map[r.code] ?? r.code : 'The message could not be sent.');
}
