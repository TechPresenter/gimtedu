import type { ReactNode } from 'react';
import clsx from 'clsx';
import { CalendarOff, ChevronLeft, ChevronRight, PartyPopper } from 'lucide-react';
import { Alert, Combobox, ProgressBar, type Tone } from '@/components/ui';
import { useApi } from '@/lib/queries';
import type { DayInfo, EmployeeStatus, OptionsPayload } from '../types';

/* ---------------------------------------------------------------- Status meta */
export const STATUS_META: Record<EmployeeStatus, { label: string; short: string; active: string; dot: string; text: string; soft: string }> = {
  present: {
    label: 'Present', short: 'P', active: 'bg-emerald-600 text-white shadow-sm dark:bg-emerald-500', dot: 'bg-emerald-500',
    text: 'text-emerald-700 dark:text-emerald-300', soft: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
  },
  absent: {
    label: 'Absent', short: 'A', active: 'bg-red-600 text-white shadow-sm dark:bg-red-500', dot: 'bg-red-500',
    text: 'text-red-600 dark:text-red-300', soft: 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
  },
  late: {
    label: 'Late', short: 'L', active: 'bg-amber-500 text-white shadow-sm', dot: 'bg-amber-500',
    text: 'text-amber-700 dark:text-amber-300', soft: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  },
  leave: {
    label: 'Leave', short: 'LV', active: 'bg-sky-600 text-white shadow-sm dark:bg-sky-500', dot: 'bg-sky-500',
    text: 'text-sky-700 dark:text-sky-300', soft: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300',
  },
  half_day: {
    label: 'Half day', short: 'HD', active: 'bg-violet-600 text-white shadow-sm dark:bg-violet-500', dot: 'bg-violet-500',
    text: 'text-violet-700 dark:text-violet-300', soft: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300',
  },
};

export const STUDENT_STATUSES: EmployeeStatus[] = ['present', 'absent', 'late', 'leave'];
export const EMPLOYEE_STATUSES: EmployeeStatus[] = ['present', 'absent', 'late', 'leave', 'half_day'];

/** Map a status code from registers (P/A/L/LV/HD) to a status. */
export const CODE_STATUS: Record<string, EmployeeStatus> = { P: 'present', A: 'absent', L: 'late', LV: 'leave', HD: 'half_day' };

/* ---------------------------------------------------------------- Segmented status control */
interface StatusSegmentProps {
  name: string;
  label: string;
  value: EmployeeStatus | null;
  onChange: (s: EmployeeStatus) => void;
  statuses?: EmployeeStatus[];
  disabled?: boolean;
  invalid?: boolean;
  compact?: boolean;
  /** Full-width with large touch targets on mobile */
  stretch?: boolean;
}

/** Accessible radio-group styled as a segmented control (arrow keys move between options). */
export function StatusSegment({ name, label, value, onChange, statuses = STUDENT_STATUSES, disabled, invalid, compact, stretch }: StatusSegmentProps) {
  return (
    <div
      role="radiogroup"
      aria-label={label}
      className={clsx(
        stretch ? 'flex w-full sm:inline-flex sm:w-auto' : 'inline-flex',
        'shrink-0 rounded-xl bg-slate-100 p-0.5 ring-1 ring-inset transition dark:bg-slate-800',
        invalid ? 'ring-red-300 dark:ring-red-500/50' : 'ring-transparent',
        disabled && 'opacity-60',
      )}
    >
      {statuses.map((s) => {
        const m = STATUS_META[s];
        const active = value === s;
        return (
          <label
            key={s}
            title={m.label}
            className={clsx(
              'relative cursor-pointer select-none rounded-[10px] text-xs font-semibold transition duration-150 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-brand-500',
              compact ? 'px-2 py-1.5' : 'px-2.5 py-1.5 sm:px-3',
              stretch && 'flex-1 py-2 text-center sm:flex-none sm:py-1.5',
              active ? m.active : 'text-slate-500 hover:bg-white hover:text-slate-800 dark:text-slate-400 dark:hover:bg-slate-700 dark:hover:text-white',
              disabled && 'pointer-events-none',
            )}
          >
            <input type="radio" className="sr-only" name={name} value={s} checked={active} disabled={disabled} onChange={() => onChange(s)} />
            <span className={clsx(compact ? '' : 'sm:hidden')}>{m.short}</span>
            {!compact && <span className="hidden sm:inline">{m.label}</span>}
          </label>
        );
      })}
    </div>
  );
}

/* ---------------------------------------------------------------- Percent helpers */
export function percentTone(p: number | null | undefined, min = 75): Tone {
  if (p === null || p === undefined) return 'slate';
  if (p < min) return 'red';
  if (p < min + 10) return 'amber';
  return 'green';
}

export function PercentPill({ value, min = 75, className }: { value: number | null | undefined; min?: number; className?: string }) {
  if (value === null || value === undefined) return <span className="text-xs text-slate-400">—</span>;
  const tone = percentTone(value, min);
  const cls = { red: 'bg-red-50 text-red-700 ring-red-600/15 dark:bg-red-500/10 dark:text-red-300', amber: 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300', green: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300' } as Record<string, string>;
  return <span className={clsx('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums ring-1 ring-inset', cls[tone] ?? 'bg-slate-100 text-slate-600', className)}>{value.toFixed(1)}%</span>;
}

export function PercentBar({ value, min = 75, className }: { value: number | null | undefined; min?: number; className?: string }) {
  const tone = percentTone(value, min);
  return (
    <div className={clsx('flex min-w-[8rem] items-center gap-2', className)}>
      <ProgressBar value={value ?? 0} tone={tone} className="h-1.5" label="Attendance percentage" />
      <span className={clsx('w-12 shrink-0 text-right text-xs font-semibold tabular-nums', tone === 'red' ? 'text-red-600 dark:text-red-400' : tone === 'amber' ? 'text-amber-600 dark:text-amber-400' : 'text-slate-700 dark:text-slate-200')}>
        {value === null || value === undefined ? '—' : `${value.toFixed(1)}%`}
      </span>
    </div>
  );
}

/** Ring gauge for a percentage (pure SVG; animates the stroke via CSS transition). */
export function PercentRing({ value, size = 64, stroke = 7, min = 75, label }: { value: number | null | undefined; size?: number; stroke?: number; min?: number; label?: string }) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const v = Math.max(0, Math.min(100, value ?? 0));
  const tone = percentTone(value, min);
  const color = { red: '#EF4444', amber: '#F59E0B', green: '#10B981', slate: '#94A3B8' }[tone as 'red' | 'amber' | 'green' | 'slate'] ?? '#94A3B8';
  return (
    <div className="relative inline-flex shrink-0 items-center justify-center" style={{ width: size, height: size }} role="img" aria-label={`${label ?? 'Attendance'} ${value ?? 0}%`}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth={stroke} className="stroke-slate-100 dark:stroke-slate-800" />
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth={stroke} stroke={color} strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c - (v / 100) * c} className="transition-[stroke-dashoffset] duration-700 ease-out motion-reduce:transition-none" />
      </svg>
      <span className="absolute font-display text-sm font-bold tabular-nums text-slate-900 dark:text-white">{value === null || value === undefined ? '—' : `${Math.round(v)}%`}</span>
    </div>
  );
}

/* ---------------------------------------------------------------- Heatmap colours */
export function heatClass(p: number | null | undefined, min = 75): string {
  if (p === null || p === undefined) return 'bg-slate-50 text-slate-400 dark:bg-slate-800/50 dark:text-slate-500';
  if (p >= 95) return 'bg-emerald-600 text-white dark:bg-emerald-500/80';
  if (p >= 90) return 'bg-emerald-500 text-white dark:bg-emerald-500/60';
  if (p >= 85) return 'bg-emerald-300 text-emerald-950 dark:bg-emerald-500/35 dark:text-emerald-50';
  if (p >= min) return 'bg-amber-200 text-amber-950 dark:bg-amber-500/35 dark:text-amber-50';
  return 'bg-red-400 text-white dark:bg-red-500/60';
}

export function HeatLegend({ min = 75 }: { min?: number }) {
  const items: [string, string][] = [
    [`< ${min}%`, heatClass(min - 1, min)],
    [`${min}–85%`, heatClass(min, min)],
    ['85–90%', heatClass(86, min)],
    ['90–95%', heatClass(91, min)],
    ['95%+', heatClass(96, min)],
  ];
  return (
    <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-slate-500 dark:text-slate-400">
      {items.map(([l, c]) => (
        <span key={l} className="inline-flex items-center gap-1.5">
          <span className={clsx('h-3 w-3 rounded', c)} aria-hidden />
          {l}
        </span>
      ))}
      <span className="inline-flex items-center gap-1.5">
        <span className="h-3 w-3 rounded bg-rose-100 ring-1 ring-inset ring-rose-200 dark:bg-rose-500/20 dark:ring-rose-500/30" aria-hidden />
        Holiday
      </span>
    </div>
  );
}

/* ---------------------------------------------------------------- Day banner */
export function DayBanner({ day, className, action }: { day: DayInfo | null | undefined; className?: string; action?: ReactNode }) {
  if (!day) return null;
  if (day.blocked) {
    const Icon = day.holiday ? PartyPopper : CalendarOff;
    return (
      <div className={clsx('flex items-start gap-3 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-200', className)} role="status">
        <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
        <div className="min-w-0 flex-1">
          <p className="font-semibold">{day.holiday ? day.holiday.title : `${day.day_name} · weekly off`}</p>
          <p className="opacity-90">{day.blocked}</p>
        </div>
        {action}
      </div>
    );
  }
  if (day.warning) {
    return (
      <Alert variant="warning" className={className} title={day.holiday?.title ?? 'Heads up'} action={action}>
        {day.warning}
      </Alert>
    );
  }
  return null;
}

/* ---------------------------------------------------------------- Month helpers */
export function currentMonth(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export function shiftMonth(month: string, delta: number): string {
  const [y, m] = month.split('-').map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}

export function monthLabel(month: string): string {
  const [y, m] = month.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}

export function MonthNav({ value, onChange, max }: { value: string; onChange: (m: string) => void; max?: string }) {
  const atMax = !!max && value >= max;
  return (
    <div className="inline-flex items-center gap-1 rounded-xl border border-slate-200 bg-white p-1 shadow-sm dark:border-slate-700 dark:bg-slate-900">
      <button type="button" className="btn-icon !h-8 !w-8 !rounded-lg" onClick={() => onChange(shiftMonth(value, -1))} aria-label="Previous month">
        <ChevronLeft className="h-4 w-4" />
      </button>
      <label className="relative">
        <span className="sr-only">Month</span>
        <input
          type="month"
          value={value}
          max={max}
          onChange={(e) => e.target.value && onChange(e.target.value)}
          className="w-[9.5rem] cursor-pointer rounded-lg border-0 bg-transparent px-1 py-1 text-center text-sm font-semibold text-slate-800 focus:ring-2 focus:ring-brand-500/30 dark:text-white [&::-webkit-calendar-picker-indicator]:opacity-60"
        />
      </label>
      <button type="button" className="btn-icon !h-8 !w-8 !rounded-lg" onClick={() => onChange(shiftMonth(value, 1))} disabled={atMax} aria-label="Next month">
        <ChevronRight className="h-4 w-4" />
      </button>
    </div>
  );
}

/* ---------------------------------------------------------------- Options + section picker */
export function useAttendanceOptions() {
  return useApi<OptionsPayload>(['attendance', 'options'], 'attendance/options', undefined, { staleTime: 5 * 60 * 1000 });
}

export function SectionPicker({ value, onChange, id, placeholder = 'Select class / section…', invalid, size }: { value: number | null; onChange: (v: number | null) => void; id?: string; placeholder?: string; invalid?: boolean; size?: 'sm' | 'md' }) {
  const { data, isLoading } = useAttendanceOptions();
  return (
    <Combobox
      id={id}
      options={data?.sections ?? []}
      value={value}
      onChange={(v) => onChange(v === null || v === '' ? null : Number(v))}
      placeholder={isLoading ? 'Loading sections…' : placeholder}
      disabled={isLoading}
      invalid={invalid}
      size={size}
    />
  );
}

/** Small stat used inside report/summary strips. */
export function MiniStat({ label, value, tone = 'slate', hint }: { label: string; value: ReactNode; tone?: 'slate' | 'green' | 'red' | 'amber' | 'blue'; hint?: ReactNode }) {
  const color = { slate: 'text-slate-900 dark:text-white', green: 'text-emerald-600 dark:text-emerald-400', red: 'text-red-600 dark:text-red-400', amber: 'text-amber-600 dark:text-amber-400', blue: 'text-brand-700 dark:text-brand-300' }[tone];
  return (
    <div className="min-w-0">
      <p className="truncate text-xs font-medium text-slate-500 dark:text-slate-400">{label}</p>
      <p className={clsx('font-display text-lg font-bold tabular-nums', color)}>{value}</p>
      {hint && <p className="truncate text-[11px] text-slate-400">{hint}</p>}
    </div>
  );
}

export function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

export function hm(t: string | null | undefined): string {
  return t ? t.slice(0, 5) : '';
}
