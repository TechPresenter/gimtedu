import type { ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { ChevronLeft, ChevronRight, type LucideIcon } from 'lucide-react';
import { formatPercent } from '@/lib/format';
import { areaPath } from '../../lib/config';
import type { AttStatus } from './types';

/** In-app path inside the faculty area: fpath('attendance') -> "/faculty/attendance". */
export const fpath = (path = '') => areaPath('faculty', path);

/** Local "YYYY-MM-DD" (the API speaks Asia/Kolkata wall-clock dates). */
export function todayIso(d: Date = new Date()): string {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function addDays(iso: string, n: number): string {
  const d = new Date(`${iso}T00:00:00`);
  d.setDate(d.getDate() + n);
  return todayIso(d);
}

/** "Mon, 12 Oct" */
export function shortDay(iso: string): string {
  const d = new Date(`${iso}T00:00:00`);
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
}

/** "today" / "yesterday" / "3 days ago" / "in 2 days" for a Y-m-d date. */
export function relDay(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(`${iso.slice(0, 10)}T00:00:00`);
  const t = new Date(`${todayIso()}T00:00:00`);
  const diff = Math.round((t.getTime() - d.getTime()) / 86400000);
  if (Number.isNaN(diff)) return '';
  if (diff === 0) return 'today';
  if (diff === 1) return 'yesterday';
  if (diff === -1) return 'tomorrow';
  return diff > 0 ? `${diff} days ago` : `in ${-diff} days`;
}

export const DAY_NAMES = ['', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
export const DAY_SHORT = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

/** Invalidate cached faculty-area queries after a write (prefix match on ['portal', 'faculty', key]). */
export function useFacultyInvalidate() {
  const qc = useQueryClient();
  return (...keys: string[]) => Promise.all(keys.map((k) => qc.invalidateQueries({ queryKey: ['portal', 'faculty', k] })));
}

/* ------------------------------------------------------------------ Attendance statuses */
export const ATT_META: Record<AttStatus, { label: string; short: string; on: string; soft: string; dot: string; text: string }> = {
  present: {
    label: 'Present',
    short: 'P',
    on: 'bg-emerald-600 text-white ring-emerald-600 dark:bg-emerald-500 dark:ring-emerald-500',
    soft: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
    dot: 'bg-emerald-500',
    text: 'text-emerald-700 dark:text-emerald-300',
  },
  absent: {
    label: 'Absent',
    short: 'A',
    on: 'bg-red-600 text-white ring-red-600 dark:bg-red-500 dark:ring-red-500',
    soft: 'bg-red-50 text-red-700 ring-red-600/15 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-400/20',
    dot: 'bg-red-500',
    text: 'text-red-700 dark:text-red-300',
  },
  late: {
    label: 'Late',
    short: 'L',
    on: 'bg-amber-500 text-white ring-amber-500 dark:bg-amber-500 dark:ring-amber-500',
    soft: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
    dot: 'bg-amber-500',
    text: 'text-amber-700 dark:text-amber-300',
  },
  leave: {
    label: 'Leave',
    short: 'LV',
    on: 'bg-sky-600 text-white ring-sky-600 dark:bg-sky-500 dark:ring-sky-500',
    soft: 'bg-sky-50 text-sky-700 ring-sky-600/15 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/20',
    dot: 'bg-sky-500',
    text: 'text-sky-700 dark:text-sky-300',
  },
};

export const ATT_ORDER: AttStatus[] = ['present', 'absent', 'late', 'leave'];

/** Last N registers as coloured dots (oldest -> newest). */
export function TrailDots({ trail, dates }: { trail: (string | null)[]; dates?: { date: string; slot: string | null }[] }) {
  return (
    <span className="inline-flex items-center gap-1" aria-label={`Last ${trail.length} classes`}>
      {trail.map((s, i) => {
        const meta = s && s in ATT_META ? ATT_META[s as AttStatus] : null;
        const title = `${dates?.[i] ? shortDay(dates[i].date) : `Class ${i + 1}`}: ${meta ? meta.label : 'not marked'}`;
        return <span key={i} title={title} className={clsx('h-2.5 w-2.5 rounded-full ring-2 ring-white dark:ring-slate-900', meta ? meta.dot : 'bg-slate-200 dark:bg-slate-700')} />;
      })}
    </span>
  );
}

/** Attendance percentage pill coloured against the institute minimum. */
export function PercentPill({ value, min = 75, className, title }: { value: number | null | undefined; min?: number; className?: string; title?: string }) {
  if (value === null || value === undefined) return <span className={clsx('text-xs text-slate-400', className)}>—</span>;
  const tone = value < min ? 'badge-red' : value < min + 10 ? 'badge-amber' : 'badge-green';
  return (
    <span className={clsx('badge tabular-nums', tone, className)} title={title}>
      {formatPercent(value, value % 1 ? 1 : 0)}
    </span>
  );
}

/** Thin progress bar (value 0-100). */
export function Bar({ value, tone = 'flame', className }: { value: number; tone?: 'flame' | 'green' | 'amber' | 'red' | 'navy' | 'violet'; className?: string }) {
  const color = { flame: 'bg-flame-500', green: 'bg-emerald-500', amber: 'bg-amber-500', red: 'bg-red-500', navy: 'bg-brand-700 dark:bg-brand-400', violet: 'bg-violet-500' }[tone];
  return (
    <div className={clsx('h-1.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800', className)} role="progressbar" aria-valuenow={Math.round(value)} aria-valuemin={0} aria-valuemax={100}>
      <div className={clsx('h-full rounded-full transition-[width] duration-500 ease-out motion-reduce:transition-none', color)} style={{ width: `${Math.max(0, Math.min(100, value))}%` }} />
    </div>
  );
}

/** Small stat tile used inside cards / page headers. */
export function MiniStat({ label, value, icon: Icon, tone = 'navy', sub }: { label: string; value: ReactNode; icon?: LucideIcon; tone?: 'navy' | 'flame' | 'green' | 'amber' | 'red' | 'violet' | 'sky'; sub?: ReactNode }) {
  const chip = {
    navy: 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200',
    flame: 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300',
    green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
    red: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300',
    violet: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
    sky: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  }[tone];
  return (
    <div className="card flex h-full items-center gap-3 p-3.5 sm:p-4">
      {Icon && (
        <span className={clsx('inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl', chip)}>
          <Icon className="h-5 w-5" aria-hidden />
        </span>
      )}
      <div className="min-w-0">
        <p className="text-xs font-semibold leading-tight text-slate-500 dark:text-slate-400">{label}</p>
        <p className="font-display text-xl font-extrabold leading-tight tracking-tight text-slate-900 dark:text-white">{value}</p>
        {sub && <p className="truncate text-[11px] text-slate-400 dark:text-slate-500">{sub}</p>}
      </div>
    </div>
  );
}

/** Prev / next stepper around a label (dates, weeks, months). */
export function Stepper({ label, sub, onPrev, onNext, prevDisabled, nextDisabled, prevLabel = 'Previous', nextLabel = 'Next', children }: {
  label: ReactNode;
  sub?: ReactNode;
  onPrev: () => void;
  onNext: () => void;
  prevDisabled?: boolean;
  nextDisabled?: boolean;
  prevLabel?: string;
  nextLabel?: string;
  children?: ReactNode;
}) {
  return (
    <div className="flex items-center gap-1.5">
      <button type="button" className="btn-icon h-9 w-9 border border-slate-200 bg-white dark:border-slate-700 dark:bg-slate-800" onClick={onPrev} disabled={prevDisabled} aria-label={prevLabel}>
        <ChevronLeft className="h-4 w-4" aria-hidden />
      </button>
      <div className="min-w-0 px-1 text-center">
        <p className="truncate text-sm font-bold text-slate-900 dark:text-white">{label}</p>
        {sub && <p className="truncate text-[11px] text-slate-500 dark:text-slate-400">{sub}</p>}
      </div>
      <button type="button" className="btn-icon h-9 w-9 border border-slate-200 bg-white disabled:opacity-40 dark:border-slate-700 dark:bg-slate-800" onClick={onNext} disabled={nextDisabled} aria-label={nextLabel}>
        <ChevronRight className="h-4 w-4" aria-hidden />
      </button>
      {children}
    </div>
  );
}

/** Pill tabs bound to a value (accessible tablist). */
export function PillTabs<T extends string>({ value, onChange, tabs, className, label }: { value: T; onChange: (v: T) => void; tabs: { value: T; label: ReactNode; count?: number }[]; className?: string; label: string }) {
  return (
    <div className={clsx('pill-tabs max-w-full overflow-x-auto scrollbar-none', className)} role="tablist" aria-label={label}>
      {tabs.map((t) => (
        <button key={t.value} type="button" role="tab" aria-selected={value === t.value} onClick={() => onChange(t.value)} className={clsx('pill-tab inline-flex items-center gap-1.5 whitespace-nowrap', value === t.value && 'pill-tab-active')}>
          {t.label}
          {t.count !== undefined && (
            <span className={clsx('rounded-full px-1.5 text-[11px] font-bold tabular-nums', value === t.value ? 'bg-flame-50 text-flame-700 dark:bg-flame-500/20 dark:text-flame-200' : 'bg-slate-200/70 text-slate-500 dark:bg-slate-700 dark:text-slate-300')}>{t.count}</span>
          )}
        </button>
      ))}
    </div>
  );
}

/** Lucide-free check: list skeleton rows. */
export function ListSkeleton({ rows = 4, className = 'h-16' }: { rows?: number; className?: string }) {
  return (
    <div className="space-y-2.5" aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className={clsx('skeleton rounded-2xl', className)} />
      ))}
    </div>
  );
}
