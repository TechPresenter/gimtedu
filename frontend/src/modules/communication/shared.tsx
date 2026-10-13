import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { CheckCircle2, Loader2, Mail, MessageCircle, MessageSquareText, PauseCircle, XCircle, type LucideIcon } from 'lucide-react';
import { Button, Modal, ProgressBar } from '@/components/ui';
import { api, apiUrl, ApiError } from '@/lib/api';
import { formatNumber } from '@/lib/format';
import { badgeClass, type BadgeColor } from '@/lib/status';

/* ------------------------------------------------------------------ reference data (mirrors app/services/communication.php) */

export const NOTICE_CATEGORIES: Record<string, { label: string; color: BadgeColor }> = {
  general: { label: 'General', color: 'slate' },
  academic: { label: 'Academic', color: 'blue' },
  examination: { label: 'Examination', color: 'purple' },
  admission: { label: 'Admission', color: 'green' },
  holiday: { label: 'Holiday', color: 'cyan' },
  event: { label: 'Event', color: 'navy' },
  placement: { label: 'Placement', color: 'green' },
  fee: { label: 'Fees', color: 'amber' },
  circular: { label: 'Circular', color: 'navy' },
  urgent: { label: 'Urgent', color: 'red' },
};

export const NOTICE_STATES: Record<string, { label: string; color: BadgeColor }> = {
  live: { label: 'Live', color: 'green' },
  scheduled: { label: 'Scheduled', color: 'blue' },
  draft: { label: 'Draft', color: 'amber' },
  expired: { label: 'Expired', color: 'slate' },
};

export const AUDIENCES: Record<string, string> = {
  all: 'Everyone', students: 'All students', faculty: 'Faculty', staff: 'Staff', parents: 'Parents', alumni: 'Alumni',
  department: 'Department', course: 'Course / Program', semester: 'Semester',
};

export const EVENT_CATEGORIES: Record<string, { label: string; color: BadgeColor }> = {
  academic: { label: 'Academic', color: 'blue' },
  cultural: { label: 'Cultural', color: 'purple' },
  sports: { label: 'Sports', color: 'green' },
  seminar: { label: 'Seminar', color: 'navy' },
  workshop: { label: 'Workshop', color: 'cyan' },
  webinar: { label: 'Webinar', color: 'cyan' },
  conference: { label: 'Conference', color: 'navy' },
  placement: { label: 'Placement', color: 'green' },
  admission: { label: 'Admission', color: 'amber' },
  fdp: { label: 'Faculty Development', color: 'purple' },
  alumni: { label: 'Alumni', color: 'amber' },
  other: { label: 'Other', color: 'slate' },
};

export const EVENT_STATUS_COLORS: Record<string, BadgeColor> = { draft: 'amber', published: 'green', completed: 'blue', cancelled: 'red' };

export const PARTICIPANT_TYPES: Record<string, string> = { student: 'Student', faculty: 'Faculty', staff: 'Staff', alumni: 'Alumni', parent: 'Parent', guest: 'Guest' };

export const REG_STATUS: Record<string, { label: string; color: BadgeColor }> = {
  registered: { label: 'Registered', color: 'blue' },
  attended: { label: 'Checked in', color: 'green' },
  cancelled: { label: 'Cancelled', color: 'red' },
};

export const CAMPAIGN_AUDIENCES: Record<string, string> = {
  subscribers: 'Newsletter subscribers', students: 'All students', program: 'Students by program', parents: 'Parents',
  faculty: 'Faculty', staff: 'Staff', alumni: 'Alumni', custom: 'Custom list',
};

export const CAMPAIGN_STATUS_COLORS: Record<string, BadgeColor> = { draft: 'amber', scheduled: 'blue', sending: 'purple', sent: 'green', failed: 'red', cancelled: 'slate' };

export const SUBSCRIBER_SOURCES: Record<string, string> = {
  website: 'Website', footer: 'Website footer', blog: 'Blog', events: 'Event page', admissions: 'Admissions', counselling: 'Counselling',
  alumni: 'Alumni', import: 'Import', admin: 'Added by admin', item: 'Website form',
};

export const CHANNELS: Record<string, { label: string; icon: LucideIcon; color: BadgeColor }> = {
  email: { label: 'E-mail', icon: Mail, color: 'blue' },
  sms: { label: 'SMS', icon: MessageSquareText, color: 'purple' },
  whatsapp: { label: 'WhatsApp', icon: MessageCircle, color: 'green' },
};

export interface ChannelsState {
  email: boolean;
  sms: boolean;
  whatsapp: boolean;
}

export interface AudienceSummary {
  people: number;
  email: number;
  sms: number;
  whatsapp: number;
  breakdown: { type: string; label: string; count: number }[];
  channels: ChannelsState;
}

export interface QueueProgress {
  total: number;
  pending: number;
  sent: number;
  failed: number;
  cancelled: number;
  done: boolean;
  percent: number;
  processed?: number;
  batch?: string;
  status?: string;
}

/* ------------------------------------------------------------------ helpers */

/** Open a server-rendered print view served by the API (letterhead, A4). */
export function openApiPrint(path: string, query?: Record<string, string | number | undefined>) {
  window.open(apiUrl(path, query), '_blank', 'noopener');
}

export function useCommRefresh() {
  const qc = useQueryClient();
  return useCallback(
    (...keys: string[]) => Promise.all([...keys, 'comm'].map((k) => qc.invalidateQueries({ queryKey: [k] }))),
    [qc],
  );
}

export function ChannelBadge({ channel, className }: { channel: string; className?: string }) {
  const c = CHANNELS[channel] ?? { label: channel, icon: Mail, color: 'slate' as BadgeColor };
  const Icon = c.icon;
  return (
    <span className={clsx('badge', badgeClass[c.color], 'whitespace-nowrap', className)}>
      <Icon className="h-3 w-3" aria-hidden />
      {c.label}
    </span>
  );
}

/** Small "x / y" usage meter (registrations vs capacity, delivered vs recipients). */
export function Meter({ value, max, tone = 'blue', label }: { value: number; max: number | null; tone?: 'blue' | 'green' | 'amber' | 'red' | 'purple'; label?: ReactNode }) {
  const pct = max ? Math.min(100, (value / max) * 100) : 0;
  return (
    <div className="min-w-[7.5rem]">
      <div className="flex items-baseline justify-between gap-2 text-xs">
        <span className="font-semibold tabular-nums text-slate-800 dark:text-slate-100">
          {formatNumber(value)}
          {max ? <span className="font-normal text-slate-400"> / {formatNumber(max)}</span> : null}
        </span>
        {label}
      </div>
      {max ? <ProgressBar value={pct} tone={pct >= 100 ? 'red' : pct >= 85 ? 'amber' : tone} className="mt-1 !h-1.5" /> : null}
    </div>
  );
}

/** Sandboxed iframe for rendered e-mail HTML. */
export function EmailFrame({ html, title = 'E-mail preview', className }: { html: string | null | undefined; title?: string; className?: string }) {
  // The e-mail layout is a fixed 600px table — let it shrink to the preview width.
  const fit = '<style>table[width="600"]{width:100%!important}img{max-width:100%}body{margin:0}</style>';
  const doc = html ? (html.includes('</head>') ? html.replace('</head>', `${fit}</head>`) : fit + html) : null;
  return (
    <iframe
      title={title}
      sandbox="allow-same-origin"
      srcDoc={doc ?? '<p style="font-family:sans-serif;color:#64748b;padding:24px">Nothing to preview yet.</p>'}
      className={clsx('w-full rounded-xl border border-slate-200 bg-white dark:border-slate-700', className ?? 'h-[480px]')}
    />
  );
}

/* ------------------------------------------------------------------ delivery progress */

interface DeliveryProgressProps {
  open: boolean;
  title: string;
  description?: ReactNode;
  initial?: QueueProgress | null;
  /** Called repeatedly until the queue is drained. */
  step: () => Promise<QueueProgress>;
  onClose: () => void;
  onFinished?: (p: QueueProgress) => void;
}

/**
 * Drives a batched send: keeps calling `step` (each call sends the next time-boxed batch on the server) and shows
 * real counts. Closing the dialog only pauses the client loop — queued messages stay pending and can be resumed.
 */
export function DeliveryProgressModal({ open, title, description, initial, step, onClose, onFinished }: DeliveryProgressProps) {
  const [p, setP] = useState<QueueProgress | null>(initial ?? null);
  const [error, setError] = useState<string | null>(null);
  const running = useRef(false);
  const alive = useRef(true);
  const stepRef = useRef(step);
  stepRef.current = step;
  const finishedRef = useRef(onFinished);
  finishedRef.current = onFinished;

  useEffect(() => {
    alive.current = open;
    if (!open) return;
    setP(initial ?? null);
    setError(null);
    if (initial?.done) {
      finishedRef.current?.(initial);
      return;
    }
    if (running.current) return;
    running.current = true;
    (async () => {
      let guard = 0;
      while (alive.current && guard++ < 500) {
        try {
          const next = await stepRef.current();
          if (!alive.current) break;
          setP(next);
          if (next.done || (next.processed === 0 && next.pending === 0)) {
            finishedRef.current?.(next);
            break;
          }
        } catch (e) {
          if (alive.current) setError((e as ApiError).message);
          break;
        }
      }
      running.current = false;
    })();
    return () => {
      alive.current = false;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const done = !!p?.done;
  return (
    <Modal
      open={open}
      onClose={onClose}
      size="md"
      title={title}
      description={description}
      icon={
        <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full', done ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-500/15' : error ? 'bg-red-100 text-red-600 dark:bg-red-500/15' : 'bg-brand-100 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200')}>
          {done ? <CheckCircle2 className="h-5 w-5" /> : error ? <XCircle className="h-5 w-5" /> : <Loader2 className="h-5 w-5 animate-spin motion-reduce:animate-none" />}
        </span>
      }
      footer={
        <Button variant={done ? 'primary' : 'secondary'} icon={done ? CheckCircle2 : PauseCircle} onClick={onClose}>
          {done ? 'Done' : 'Continue in background'}
        </Button>
      }
    >
      <div className="space-y-4" aria-live="polite">
        <div>
          <div className="mb-1.5 flex items-baseline justify-between text-sm">
            <span className="font-medium text-slate-700 dark:text-slate-200">{done ? 'All messages processed' : 'Sending…'}</span>
            <span className="font-display text-lg font-bold tabular-nums text-slate-900 dark:text-white">{p?.percent ?? 0}%</span>
          </div>
          <ProgressBar value={p?.percent ?? 0} tone={done ? 'green' : 'blue'} className="!h-2.5" label="Delivery progress" />
        </div>
        <div className="grid grid-cols-3 gap-2 text-center">
          {[
            { label: 'Delivered', value: p?.sent ?? 0, cls: 'text-emerald-600 dark:text-emerald-400' },
            { label: 'Failed', value: p?.failed ?? 0, cls: 'text-red-600 dark:text-red-400' },
            { label: 'Pending', value: p?.pending ?? 0, cls: 'text-slate-700 dark:text-slate-200' },
          ].map((s) => (
            <div key={s.label} className="rounded-xl border border-slate-200 px-2 py-2.5 dark:border-slate-700">
              <p className={clsx('font-display text-xl font-bold tabular-nums', s.cls)}>{formatNumber(s.value)}</p>
              <p className="text-xs text-slate-500 dark:text-slate-400">{s.label}</p>
            </div>
          ))}
        </div>
        {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-500/10 dark:text-red-300">{error} Pending messages are kept — you can resume later.</p>}
        {!done && !error && <p className="text-xs text-slate-500 dark:text-slate-400">Messages are sent in small batches. You can close this window — unsent messages stay queued and can be resumed.</p>}
        {done && (p?.failed ?? 0) > 0 && <p className="text-xs text-slate-500 dark:text-slate-400">Failed messages can be retried from Message Logs.</p>}
      </div>
    </Modal>
  );
}

/** Convenience wrapper: process a queue batch through the generic endpoint. */
export function processBatch(batch: string) {
  return api.post<QueueProgress>('communication/queue/process', { batch }).then((r) => r.data);
}

/** Pill-style filter chips with counts (category / source pickers). */
export function ChipFilter({ items, value, onChange, allLabel = 'All' }: { items: { key: string; label: string; count?: number }[]; value: string; onChange: (k: string) => void; allLabel?: string }) {
  const all = [{ key: '', label: allLabel }, ...items];
  return (
    <div className="flex max-w-full gap-2 overflow-x-auto pb-1 scrollbar-none" role="radiogroup" aria-label="Filter">
      {all.map((it) => {
        const active = value === it.key;
        return (
          <button
            key={it.key || 'all'}
            type="button"
            role="radio"
            aria-checked={active}
            onClick={() => onChange(it.key)}
            className={clsx(
              'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-medium transition',
              active
                ? 'border-brand-800 bg-brand-800 text-white dark:border-brand-400 dark:bg-brand-500/20 dark:text-white'
                : 'border-slate-200 bg-white text-slate-600 hover:border-brand-300 hover:text-brand-800 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-300 dark:hover:border-slate-500',
            )}
          >
            {it.label}
            {'count' in it && it.count !== undefined && (
              <span className={clsx('rounded-full px-1.5 text-[10px] font-semibold', active ? 'bg-white/20' : 'bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400')}>{it.count}</span>
            )}
          </button>
        );
      })}
    </div>
  );
}

/** Copy a {{placeholder}} to the clipboard. */
export async function copyText(text: string): Promise<boolean> {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    return false;
  }
}
