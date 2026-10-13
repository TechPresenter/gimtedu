import { useEffect, useState, type ReactNode } from 'react';
import clsx from 'clsx';
import { useLocation } from 'react-router-dom';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ExternalLink, Printer, type LucideIcon } from 'lucide-react';
import { useToast } from '@/components/ui';
import { api, apiUrl, type Query } from '@/lib/api';
import { formatMoney, labelize } from '@/lib/format';
import { badgeClass, statusColor } from '@/lib/status';
import { appUrl } from '@/lib/config';
import { usePortalQuery } from '../../lib/hooks';
import { errorMessage } from '../../lib/errors';

/* ------------------------------------------------------------------ layout */

/**
 * Keep the active sidebar link visible: the student menu is longer than short desktop screens, and the shell's
 * sidebar scrolls independently, so a deep link (e.g. /student/certificates) would otherwise highlight an off-screen item.
 */
export function useNavReveal() {
  const { pathname } = useLocation();
  useEffect(() => {
    const t = window.setTimeout(() => {
      const el = document.querySelector<HTMLElement>('aside[aria-label="Portal navigation"] .nav-link-active');
      const nav = el?.closest('nav');
      if (!el || !nav) return;
      const top = el.offsetTop - nav.offsetTop;
      if (top < nav.scrollTop || top + el.offsetHeight > nav.scrollTop + nav.clientHeight) nav.scrollTop = Math.max(0, top - nav.clientHeight / 2);
    }, 60);
    return () => window.clearTimeout(t);
  }, [pathname]);
}

/* ------------------------------------------------------------------ data */

/** GET /api/portal/student/{path} cached under ['portal','student',...key]. */
export function useStudent<T>(key: unknown[], path: string, query?: Query, opts: { enabled?: boolean } = {}) {
  useNavReveal();
  return usePortalQuery<T>('student', key, path, query, opts);
}

interface PostOptions<R> {
  /** query keys (after 'portal','student') to refresh on success */
  invalidate?: unknown[][];
  /** toast the server message on success (default true) */
  toastSuccess?: boolean;
  onSuccess?: (data: R, message: string) => void;
}

/**
 * POST to a student endpoint with toast feedback. Field errors (422) are left on `error` for inline display
 * (use fieldErrors(error)); other failures are also toasted.
 */
export function useStudentPost<B = Record<string, unknown>, R = unknown>(path: string | ((body: B) => string), opts: PostOptions<R> = {}) {
  const qc = useQueryClient();
  const toast = useToast();
  return useMutation({
    mutationFn: async (body: B) => {
      const p = typeof path === 'function' ? path(body) : path;
      return api.post<R>(`portal/student/${p}`, body);
    },
    onSuccess: (res) => {
      if (opts.toastSuccess !== false && res.message) toast.success(res.message);
      (opts.invalidate ?? []).forEach((k) => void qc.invalidateQueries({ queryKey: ['portal', 'student', ...k] }));
      opts.onSuccess?.(res.data, res.message);
    },
    onError: (e) => {
      const status = (e as { status?: number }).status;
      if (status !== 422 || !Object.keys((e as { errors?: Record<string, string> }).errors ?? {}).length) toast.error(errorMessage(e));
    },
  });
}

/* ------------------------------------------------------------------ print / files */

/** Absolute URL of a student print view: printPath('receipt/12'). */
export function printPath(path: string): string {
  return apiUrl(`portal/student/print/${path.replace(/^\/+/, '')}`);
}

export function openPrint(path: string) {
  window.open(printPath(path), '_blank');
}

export function PrintButton({ path, children = 'Print', className, size = 'sm', variant = 'secondary', icon: Icon = Printer }: { path: string; children?: ReactNode; className?: string; size?: 'xs' | 'sm'; variant?: 'secondary' | 'primary' | 'navy' | 'soft'; icon?: LucideIcon }) {
  return (
    <button type="button" onClick={() => openPrint(path)} className={clsx('btn', size === 'xs' ? 'btn-xs' : 'btn-sm', `btn-${variant}`, className)}>
      <Icon className="h-4 w-4" aria-hidden /> {children}
    </button>
  );
}

/* ------------------------------------------------------------------ status + values */

const OVERRIDES: Record<string, string> = {
  open: 'badge-blue',
  issued: 'badge-green',
  valid: 'badge-green',
  due_today: 'badge-amber',
  returned_late: 'badge-amber',
  ongoing: 'badge-flame',
  upcoming: 'badge-navy',
  completed: 'badge-slate',
  in_progress: 'badge-amber',
  closed: 'badge-slate',
  withdrawn: 'badge-slate',
  pending: 'badge-amber',
  PASS: 'badge-green',
  BACKLOG: 'badge-amber',
  FAIL: 'badge-red',
  ABSENT: 'badge-slate',
  WITHHELD: 'badge-purple',
};

export function statusBadgeClass(status: string | null | undefined): string {
  const s = String(status ?? '');
  return OVERRIDES[s] ?? badgeClass[statusColor(s)];
}

export function StatusBadge({ status, label, className, icon: Icon }: { status: string | null | undefined; label?: ReactNode; className?: string; icon?: LucideIcon }) {
  if (!status) return null;
  return (
    <span className={clsx('badge', statusBadgeClass(status), className)}>
      {Icon && <Icon className="h-3 w-3" aria-hidden />}
      {label ?? (status === status.toUpperCase() ? labelize(status.toLowerCase()) : labelize(status))}
    </span>
  );
}

/** "Due today" / "Due in 3 days" / "Overdue by 5 days". */
export function dueLabel(days: number | null | undefined, verb = 'Due'): string {
  if (days === null || days === undefined) return '';
  if (days === 0) return `${verb} today`;
  if (days === 1) return `${verb} tomorrow`;
  if (days > 1) return `${verb} in ${days} days`;
  return `Overdue by ${Math.abs(days)} day${days === -1 ? '' : 's'}`;
}

export function money(v: number | null | undefined, decimals = 0): string {
  return formatMoney(v ?? 0, decimals);
}

/** "₹4.5 – 7 LPA" */
export function packageLabel(min: number | null, max: number | null): string | null {
  const f = (n: number) => (Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0$/, ''));
  if (min && max && min !== max) return `₹${f(min)} – ${f(max)} LPA`;
  if (max || min) return `₹${f((max ?? min) as number)} LPA`;
  return null;
}

/** Tone for a percentage against a threshold. */
export function pctTone(p: number | null | undefined, threshold: number): 'green' | 'amber' | 'red' {
  if (p === null || p === undefined) return 'amber';
  if (p < threshold) return 'red';
  return p < threshold + 10 ? 'amber' : 'green';
}

const BAR: Record<string, string> = { green: 'bg-emerald-500', amber: 'bg-amber-500', red: 'bg-red-500', navy: 'bg-brand-700 dark:bg-brand-400', flame: 'bg-flame-500', sky: 'bg-sky-500' };

export function Bar({ value, tone = 'navy', marker, label, className }: { value: number; tone?: keyof typeof BAR | string; marker?: number; label?: string; className?: string }) {
  const v = Math.max(0, Math.min(100, value || 0));
  return (
    <div className={clsx('relative h-2 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800', className)} role="progressbar" aria-valuenow={Math.round(v)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={clsx('h-full rounded-full transition-[width] duration-500 ease-out motion-reduce:transition-none', BAR[tone] ?? BAR.navy)} style={{ width: `${v}%` }} />
      {marker !== undefined && <span className="absolute inset-y-0 w-0.5 bg-slate-900/40 dark:bg-white/50" style={{ left: `${Math.max(0, Math.min(100, marker))}%` }} aria-hidden />}
    </div>
  );
}

/** Small label/value tile used inside cards and drawers. */
export function MiniStat({ label, value, sub, tone = 'default', className }: { label: string; value: ReactNode; sub?: ReactNode; tone?: 'default' | 'navy' | 'flame' | 'green' | 'red' | 'amber'; className?: string }) {
  const tones = {
    default: 'bg-slate-50 dark:bg-slate-800/50',
    navy: 'bg-brand-50 dark:bg-brand-500/10',
    flame: 'bg-flame-50 dark:bg-flame-500/10',
    green: 'bg-emerald-50 dark:bg-emerald-500/10',
    red: 'bg-red-50 dark:bg-red-500/10',
    amber: 'bg-amber-50 dark:bg-amber-500/10',
  } as const;
  return (
    <div className={clsx('min-w-0 rounded-xl px-3 py-2.5', tones[tone], className)}>
      <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{label}</p>
      <p className="mt-0.5 truncate font-display text-[15px] font-bold text-slate-900 dark:text-white">{value}</p>
      {sub && <p className="mt-0.5 truncate text-[11px] text-slate-500 dark:text-slate-400">{sub}</p>}
    </div>
  );
}

/** Compact date tile (navy block with day + month). */
export function DateTile({ date, tone = 'navy', className }: { date: string | null; tone?: 'navy' | 'flame' | 'muted'; className?: string }) {
  const d = date ? new Date(/^\d{4}-\d{2}-\d{2}$/.test(date) ? `${date}T00:00:00` : date.replace(' ', 'T')) : null;
  const ok = d && !Number.isNaN(d.getTime());
  const cls = { navy: 'bg-brand-900 text-white dark:bg-brand-800', flame: 'bg-flame-600 text-white', muted: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }[tone];
  return (
    <div className={clsx('flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl', cls, className)} aria-hidden>
      <span className="font-display text-base font-extrabold leading-none">{ok ? String(d!.getDate()).padStart(2, '0') : '--'}</span>
      <span className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide opacity-75">{ok ? d!.toLocaleDateString('en-GB', { month: 'short' }) : ''}</span>
    </div>
  );
}

/** Company logo (fixed height, readable) or a text wordmark fallback. */
export function CompanyLogo({ src, name, className }: { src: string | null; name: string; className?: string }) {
  const [failed, setFailed] = useState(false);
  if (src && !failed) {
    return (
      <span className={clsx('inline-flex h-12 w-16 shrink-0 items-center justify-center rounded-xl border border-slate-200/80 bg-white p-1.5 dark:border-slate-700', className)}>
        <img src={appUrl(src)} alt={name} loading="lazy" className="max-h-8 w-auto max-w-full object-contain" onError={() => setFailed(true)} />
      </span>
    );
  }
  return (
    <span className={clsx('inline-flex h-12 w-16 shrink-0 items-center justify-center rounded-xl bg-brand-50 px-1 text-center font-display text-[11px] font-extrabold leading-tight text-brand-800 dark:bg-brand-500/15 dark:text-brand-200', className)} aria-label={name}>
      {name
        .split(/\s+/)
        .slice(0, 2)
        .map((w) => w[0])
        .join('')
        .toUpperCase()}
    </span>
  );
}

/** Pill chip. */
export function Chip({ children, icon: Icon, className }: { children: ReactNode; icon?: LucideIcon; className?: string }) {
  return (
    <span className={clsx('inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-600 dark:bg-slate-800 dark:text-slate-300', className)}>
      {Icon && <Icon className="h-3.5 w-3.5 text-slate-400" aria-hidden />}
      {children}
    </span>
  );
}

export function ExternalA({ href, children, className }: { href: string; children: ReactNode; className?: string }) {
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className={clsx('link inline-flex items-center gap-1', className)}>
      {children} <ExternalLink className="h-3.5 w-3.5" aria-hidden />
    </a>
  );
}

/** Generic list skeleton. */
export function ListSkeleton({ rows = 4, className, h = 'h-16' }: { rows?: number; className?: string; h?: string }) {
  return (
    <div className={clsx('space-y-3', className)} aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }).map((_, i) => (
        <div key={i} className={clsx('skeleton rounded-2xl', h)} />
      ))}
    </div>
  );
}

/* ------------------------------------------------------------------ QR (bundled assets/vendor/qrcode.js) */

type QrFactory = (type: number, level: string) => { addData: (d: string) => void; make: () => void; createSvgTag: (o: { cellSize: number; margin: number; scalable: boolean }) => string };
let qrLoader: Promise<QrFactory | null> | null = null;

function loadQr(): Promise<QrFactory | null> {
  const w = window as unknown as { qrcode?: QrFactory };
  if (w.qrcode) return Promise.resolve(w.qrcode);
  if (!qrLoader) {
    qrLoader = new Promise((resolve) => {
      const s = document.createElement('script');
      s.src = appUrl('assets/vendor/qrcode.js');
      s.async = true;
      s.onload = () => resolve(w.qrcode ?? null);
      s.onerror = () => {
        qrLoader = null;
        resolve(null);
      };
      document.head.appendChild(s);
    });
  }
  return qrLoader;
}

export function QrCode({ text, className, label }: { text: string; className?: string; label?: string }) {
  const [svg, setSvg] = useState('');
  useEffect(() => {
    let alive = true;
    void loadQr().then((qr) => {
      if (!qr || !alive) return;
      try {
        const q = qr(0, 'M');
        q.addData(text);
        q.make();
        setSvg(q.createSvgTag({ cellSize: 3, margin: 0, scalable: true }));
      } catch {
        setSvg('');
      }
    });
    return () => {
      alive = false;
    };
  }, [text]);
  if (!svg) return <div className={clsx('skeleton rounded-lg', className)} aria-hidden />;
  return <div className={clsx('rounded-lg bg-white p-1.5 [&>svg]:h-full [&>svg]:w-full', className)} role="img" aria-label={label ?? 'QR code'} dangerouslySetInnerHTML={{ __html: svg }} />;
}
