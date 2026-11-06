import clsx from 'clsx';
import { Lock, LockOpen, Timer } from 'lucide-react';
import { useEffect, useState } from 'react';

const WINDOW_MS = 24 * 60 * 60 * 1000;
const EXPIRING_MS = 2 * 60 * 60 * 1000;

/** Parse a server DATETIME ('Y-m-d H:i:s', Asia/Kolkata) into a Date regardless of the browser's time zone. */
export function parseServerDate(v: string | null | undefined): Date | null {
  if (!v) return null;
  const s = String(v).trim();
  if (/[zZ]|[+-]\d\d:?\d\d$/.test(s)) {
    const d = new Date(s);
    return Number.isNaN(d.getTime()) ? null : d;
  }
  const d = new Date(`${s.replace(' ', 'T')}+05:30`);
  return Number.isNaN(d.getTime()) ? null : d;
}

export type WaWindowState = 'open' | 'expiring' | 'closed';

/** 24-hour customer-service window from the contact's last inbound message. */
export function waWindow(lastInboundAt: string | null | undefined, now: number = Date.now()): { state: WaWindowState; msLeft: number; expiresAt: Date | null } {
  const last = parseServerDate(lastInboundAt);
  if (!last) return { state: 'closed', msLeft: 0, expiresAt: null };
  const expiresAt = new Date(last.getTime() + WINDOW_MS);
  const msLeft = expiresAt.getTime() - now;
  if (msLeft <= 0) return { state: 'closed', msLeft: 0, expiresAt };
  return { state: msLeft <= EXPIRING_MS ? 'expiring' : 'open', msLeft, expiresAt };
}

/** '21h 14m' / '9m' */
export function formatDuration(ms: number): string {
  const totalMin = Math.max(0, Math.floor(ms / 60000));
  const h = Math.floor(totalMin / 60);
  const m = totalMin % 60;
  return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

/**
 * 24-hour window badge with a live countdown: open (green), expiring within 2 h (amber), closed (slate — templates
 * only). Pass the contact's / conversation's last_inbound_at.
 */
export function WindowBadge({ lastInboundAt, className, compact = false }: { lastInboundAt: string | null | undefined; className?: string; compact?: boolean }) {
  const [now, setNow] = useState(() => Date.now());
  const w = waWindow(lastInboundAt, now);
  useEffect(() => {
    if (w.state === 'closed') return;
    const t = window.setInterval(() => setNow(Date.now()), 30_000);
    return () => window.clearInterval(t);
  }, [w.state]);

  const tone =
    w.state === 'open'
      ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20'
      : w.state === 'expiring'
        ? 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20'
        : 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-700/40 dark:text-slate-300 dark:ring-slate-400/20';
  const Icon = w.state === 'open' ? LockOpen : w.state === 'expiring' ? Timer : Lock;
  const label =
    w.state === 'closed'
      ? compact
        ? 'Window closed'
        : 'Window closed · templates only'
      : compact
        ? `${formatDuration(w.msLeft)} left`
        : `Window open · ${formatDuration(w.msLeft)} left`;
  const title =
    w.state === 'closed'
      ? 'No message from this contact in the last 24 hours. Only approved templates can be sent.'
      : `Free-form replies allowed until ${w.expiresAt?.toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Kolkata' })} IST.`;

  return (
    <span
      className={clsx('inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset', tone, className)}
      title={title}
    >
      <Icon size={12} aria-hidden />
      {label}
    </span>
  );
}

export default WindowBadge;
