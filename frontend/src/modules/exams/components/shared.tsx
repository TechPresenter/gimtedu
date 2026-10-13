import type { ReactNode } from 'react';
import clsx from 'clsx';
import { Badge } from '@/components/ui';
import type { BadgeColor } from '@/lib/status';
import { formatDate, formatTime } from '@/lib/format';
import type { ExamStatus, ResultStatus } from './types';

/** Exam workflow statuses (stored + derived "ongoing" / "marks_entry"). */
export const EXAM_STATUS: Record<ExamStatus, { label: string; color: BadgeColor }> = {
  draft: { label: 'Draft', color: 'slate' },
  scheduled: { label: 'Scheduled', color: 'blue' },
  ongoing: { label: 'Ongoing', color: 'cyan' },
  marks_entry: { label: 'Marks entry', color: 'amber' },
  verified: { label: 'Verified', color: 'purple' },
  published: { label: 'Published', color: 'green' },
  cancelled: { label: 'Cancelled', color: 'red' },
};

export const RESULT_STATUS: Record<ResultStatus, { label: string; color: BadgeColor; hex: string }> = {
  PASS: { label: 'Pass', color: 'green', hex: '#22943F' },
  BACKLOG: { label: 'Backlog', color: 'amber', hex: '#F59E0B' },
  FAIL: { label: 'Fail', color: 'red', hex: '#EF4444' },
  ABSENT: { label: 'Absent', color: 'slate', hex: '#94A3B8' },
  WITHHELD: { label: 'Withheld', color: 'purple', hex: '#8B5CF6' },
};

export function ExamStatusBadge({ status }: { status: string | null | undefined }) {
  const s = EXAM_STATUS[(status ?? 'draft') as ExamStatus] ?? { label: status ?? '—', color: 'slate' as BadgeColor };
  return (
    <Badge color={s.color} dot>
      {s.label}
    </Badge>
  );
}

export function ResultBadge({ status }: { status: string | null | undefined }) {
  const s = RESULT_STATUS[(status ?? '') as ResultStatus];
  if (!s) return <span className="text-slate-400">—</span>;
  return (
    <Badge color={s.color} dot>
      {s.label}
    </Badge>
  );
}

/** "12" / "12.5" (no trailing zeros), "—" for empty. */
export function fmtMarks(v: number | string | null | undefined, digits = 2): string {
  if (v === null || v === undefined || v === '') return '—';
  const n = Number(v);
  if (!Number.isFinite(n)) return '—';
  return String(Number(n.toFixed(digits)));
}

export function fmtGpa(v: number | string | null | undefined): string {
  if (v === null || v === undefined || v === '') return '—';
  return Number(v).toFixed(2);
}

export function dateRange(from: string | null | undefined, to: string | null | undefined): string {
  if (!from) return '—';
  if (!to || to === from) return formatDate(from);
  const a = new Date(`${from}T00:00:00`);
  const b = new Date(`${to}T00:00:00`);
  if (a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth()) {
    return `${a.getDate()}–${b.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' })}`;
  }
  return `${formatDate(from)} – ${formatDate(to)}`;
}

export function timeRange(start: string | null | undefined, end: string | null | undefined): string {
  if (!start) return '—';
  return `${formatTime(start)} – ${formatTime(end)}`;
}

/** Small key/value chip used in summary strips. */
export function Chip({ label, value, tone = 'slate', onClick, active }: { label: string; value: ReactNode; tone?: 'slate' | 'green' | 'red' | 'amber' | 'blue' | 'purple'; onClick?: () => void; active?: boolean }) {
  const tones: Record<string, string> = {
    slate: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
    red: 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
    blue: 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300',
    purple: 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
  };
  const cls = clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium transition', tones[tone], onClick && 'hover:ring-2 hover:ring-brand-200 dark:hover:ring-brand-500/30', active && 'ring-2 ring-brand-500/60');
  const body = (
    <>
      <span className="opacity-80">{label}</span>
      <span className="font-semibold tabular-nums">{value}</span>
    </>
  );
  return onClick ? (
    <button type="button" className={cls} onClick={onClick} aria-pressed={active}>
      {body}
    </button>
  ) : (
    <span className={cls}>{body}</span>
  );
}

/** Thin labelled progress bar with value text. */
export function MiniProgress({ value, tone = 'blue', label }: { value: number; tone?: 'blue' | 'green' | 'amber' | 'red'; label?: string }) {
  const bar = { blue: 'bg-blue-600', green: 'bg-emerald-500', amber: 'bg-amber-500', red: 'bg-red-500' }[tone];
  const v = Math.max(0, Math.min(100, value || 0));
  return (
    <div className="flex min-w-[7rem] items-center gap-2">
      <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800" role="progressbar" aria-valuenow={Math.round(v)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
        <div className={clsx('h-full rounded-full transition-all duration-500', bar)} style={{ width: `${v}%` }} />
      </div>
      <span className="w-10 text-right text-xs font-medium tabular-nums text-slate-600 dark:text-slate-300">{Math.round(v)}%</span>
    </div>
  );
}

/** Date tile (day + month) used in agenda lists. */
export function DateTile({ date, tone = 'navy' }: { date: string; tone?: 'navy' | 'green' | 'amber' }) {
  const d = new Date(`${date}T00:00:00`);
  const tones = {
    navy: 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200',
    green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    amber: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300',
  };
  return (
    <span className={clsx('flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl leading-none', tones[tone])}>
      <span className="font-display text-lg font-bold">{d.getDate()}</span>
      <span className="mt-0.5 text-[10px] font-semibold uppercase tracking-wide">{d.toLocaleDateString('en-IN', { month: 'short' })}</span>
    </span>
  );
}

export const SEMESTER_OPTIONS = Array.from({ length: 10 }, (_, i) => ({ value: String(i + 1), label: `Semester ${i + 1}` }));

export function todayIso(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}
