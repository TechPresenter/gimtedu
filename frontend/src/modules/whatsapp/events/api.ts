import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { api, type Query } from '@/lib/api';
import type { BadgeColor } from '@/lib/status';
import type { WaEvent, WaEventDetail, WaEventListResponse, WaEventLog, WaEventLogsPage, WaEventOptions, WaEventOutcome, WaEventStats } from './types';

/** Query keys of the Event Manager (all under ['wa', 'events'] so useWaRefresh()('events') refreshes everything). */
export const evtKeys = {
  list: ['wa', 'events', 'list'] as const,
  options: ['wa', 'events', 'options'] as const,
  detail: (id: number) => ['wa', 'events', 'detail', id] as const,
  logs: (q: Query) => ['wa', 'events', 'logs', q] as const,
  stats: (id: number, days: number) => ['wa', 'events', 'stats', id, days] as const,
};

export function useEvents() {
  return useQuery({
    queryKey: evtKeys.list,
    queryFn: ({ signal }) => api.get<WaEventListResponse>('whatsapp/events', undefined, { signal }),
    staleTime: 15_000,
    // follow bulk deliveries while the worker expands them
    refetchInterval: (q) => ((q.state.data?.fanouts ?? []).some((f) => f.status === 'pending' || f.status === 'running') ? 10_000 : false),
  });
}

export function useEventOptions() {
  return useQuery({
    queryKey: evtKeys.options,
    queryFn: ({ signal }) => api.get<WaEventOptions>('whatsapp/events/options', undefined, { signal }),
    staleTime: 5 * 60_000,
  });
}

export function useEventDetail(id: number | null) {
  return useQuery({
    queryKey: evtKeys.detail(id ?? 0),
    queryFn: ({ signal }) => api.get<WaEventDetail>(`whatsapp/events/${id}`, undefined, { signal }),
    enabled: !!id,
  });
}

export function useEventLogs(q: Query, enabled = true) {
  return useQuery({
    queryKey: evtKeys.logs(q),
    queryFn: ({ signal }) => api.get<WaEventLogsPage>('whatsapp/events/logs', q, { signal }),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useEventStats(id: number | null, days = 30) {
  return useQuery({
    queryKey: evtKeys.stats(id ?? 0, days),
    queryFn: ({ signal }) => api.get<WaEventStats>(`whatsapp/events/${id}/stats`, { days }, { signal }),
    enabled: !!id,
  });
}

/** PHP encodes an empty JSON object as []: normalise to a plain object. */
export function asRecord<T = unknown>(v: unknown): Record<string, T> {
  return v && typeof v === 'object' && !Array.isArray(v) ? (v as Record<string, T>) : {};
}

export const CATEGORY_TONE: Record<string, { dot: string; chip: string }> = {
  enquiry: { dot: 'bg-sky-500', chip: 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30' },
  registration: { dot: 'bg-indigo-500', chip: 'bg-indigo-50 text-indigo-700 ring-indigo-200 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-500/30' },
  admission: { dot: 'bg-violet-500', chip: 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30' },
  course: { dot: 'bg-teal-500', chip: 'bg-teal-50 text-teal-700 ring-teal-200 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-500/30' },
  payment: { dot: 'bg-emerald-500', chip: 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30' },
  exam: { dot: 'bg-amber-500', chip: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30' },
  certificate: { dot: 'bg-cyan-500', chip: 'bg-cyan-50 text-cyan-700 ring-cyan-200 dark:bg-cyan-500/10 dark:text-cyan-300 dark:ring-cyan-500/30' },
  support: { dot: 'bg-rose-500', chip: 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/30' },
  staff: { dot: 'bg-brand-700', chip: 'bg-brand-50 text-brand-800 ring-brand-200 dark:bg-brand-500/10 dark:text-brand-200 dark:ring-brand-500/30' },
  custom: { dot: 'bg-accent-600', chip: 'bg-accent-50 text-accent-700 ring-accent-200 dark:bg-accent-500/10 dark:text-accent-300 dark:ring-accent-500/30' },
};

export function categoryTone(cat: string) {
  return CATEGORY_TONE[cat] ?? CATEGORY_TONE.custom;
}

/** Display status of a log row: the Event Manager outcome wins over the bus status. */
export function logDisplayStatus(l: Pick<WaEventLog, 'status' | 'outcome'>): { key: string; label: string; color: BadgeColor } {
  if (l.status === 'duplicate') return { key: 'duplicate', label: 'Duplicate', color: 'slate' };
  if (l.status === 'failed') return { key: 'failed', label: 'Failed', color: 'red' };
  if (l.status === 'received') return { key: 'received', label: 'Received', color: 'amber' };
  const o: WaEventOutcome | null = l.outcome;
  // the same-template repeat guard kept the earlier identical message (e.g. a workflow welcome sent seconds before)
  if (o?.status === 'queued' && o.send_status === 'duplicate_recent') return { key: 'duplicate_recent', label: 'Already sent recently', color: 'cyan' };
  if (o?.status === 'queued') return { key: 'sent', label: o.scheduled_at ? 'Scheduled' : 'Message queued', color: 'green' };
  if (o?.status === 'failed') return { key: 'failed', label: 'Send refused', color: 'red' };
  if (o?.status === 'skipped') return { key: 'skipped', label: 'Skipped', color: 'slate' };
  return l.status === 'skipped' ? { key: 'skipped', label: 'No action', color: 'slate' } : { key: 'processed', label: 'Processed', color: 'blue' };
}

export function eventOptionsList(events: WaEvent[] | undefined) {
  return (events ?? []).map((e) => ({ value: e.code, label: `${e.name} (${e.code})` }));
}

export function prettyJson(v: unknown): string {
  try {
    return JSON.stringify(v ?? {}, null, 2);
  } catch {
    return String(v);
  }
}

/** Flattened keys of a payload ("data.amount") — mirrors wa_evt_flatten() on the server. */
export function payloadKeys(payload: unknown, prefix = '', depth = 0): string[] {
  const rec = asRecord(payload);
  const out: string[] = [];
  for (const [k, v] of Object.entries(rec)) {
    if (!k || k.startsWith('_')) continue;
    if (v && typeof v === 'object' && !Array.isArray(v) && depth < 2) out.push(...payloadKeys(v, `${prefix}${k}.`, depth + 1));
    else out.push(prefix + k);
  }
  return out;
}

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
