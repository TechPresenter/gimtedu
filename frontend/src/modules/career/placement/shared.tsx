import { useCallback, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import clsx from 'clsx';
import { Avatar } from '@/components/ui';
import { appUrl } from '@/lib/config';
import { apiUrl } from '@/lib/api';
import { initials, toNumber } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';

/* ---------------------------------------------------------------- labels & colours (mirror app/services/placement.php) */
export const APP_STATUS: Record<string, string> = {
  applied: 'Applied', shortlisted: 'Shortlisted', aptitude: 'Aptitude round', technical: 'Technical round', hr: 'HR round',
  selected: 'Selected', rejected: 'Rejected', on_hold: 'On hold', withdrawn: 'Withdrawn',
};
export const APP_STATUS_COLORS: Record<string, BadgeColor> = {
  applied: 'slate', shortlisted: 'blue', aptitude: 'cyan', technical: 'purple', hr: 'navy', selected: 'green', rejected: 'red', on_hold: 'amber', withdrawn: 'slate',
};
export const OFFER_STATUS: Record<string, string> = { offered: 'Offered', accepted: 'Accepted (placed)', declined: 'Declined', joined: 'Joined', revoked: 'Revoked' };
/** Short offer labels for narrow table cells. */
export const OFFER_STATUS_SHORT: Record<string, string> = { offered: 'Offered', accepted: 'Placed', declined: 'Declined', joined: 'Joined', revoked: 'Revoked' };
export const OFFER_STATUS_COLORS: Record<string, BadgeColor> = { offered: 'amber', accepted: 'green', joined: 'navy', declined: 'slate', revoked: 'red' };
export const DRIVE_STATUS: Record<string, string> = { upcoming: 'Upcoming', open: 'Registration open', ongoing: 'Ongoing', completed: 'Completed', cancelled: 'Cancelled' };
export const DRIVE_STATUS_COLORS: Record<string, BadgeColor> = { upcoming: 'amber', open: 'green', ongoing: 'blue', completed: 'navy', cancelled: 'red' };
export const JOB_TYPES: Record<string, string> = { full_time: 'Full-time', internship: 'Internship', internship_ppo: 'Internship + PPO', contract: 'Contract' };
export const DRIVE_MODES: Record<string, string> = { on_campus: 'On campus', off_campus: 'Off campus', virtual: 'Virtual', pool: 'Pool campus' };
export const TIE_UP: Record<string, string> = { prospect: 'Prospect', active: 'Active', mou_signed: 'MoU signed', dormant: 'Dormant' };
export const TIE_UP_COLORS: Record<string, BadgeColor> = { prospect: 'amber', active: 'green', mou_signed: 'navy', dormant: 'slate' };
export const COMPANY_TYPES: Record<string, string> = {
  mnc: 'MNC', indian_mnc: 'Indian MNC', large_indian: 'Large Indian company', startup: 'Startup', psu: 'PSU', government: 'Government', sme: 'SME', ngo: 'NGO / Non-profit',
};
export const TRAINING_TYPES: Record<string, string> = {
  training: 'Training', mock_test: 'Mock test', mock_interview: 'Mock interview', workshop: 'Workshop', seminar: 'Seminar', certification: 'Certification',
};
export const TRAINING_STATUS_COLORS: Record<string, BadgeColor> = { scheduled: 'amber', ongoing: 'blue', completed: 'green', cancelled: 'red' };
export const ROUND_RESULT: Record<string, string> = { pending: 'Pending', cleared: 'Cleared', failed: 'Not cleared', absent: 'Absent' };
export const ROUND_RESULT_COLORS: Record<string, BadgeColor> = { pending: 'amber', cleared: 'green', failed: 'red', absent: 'slate' };
export const INTERVIEW_MODES: Record<string, string> = { in_person: 'In person', online: 'Online', telephonic: 'Telephonic' };

/** 6.5 → "6.5 LPA" */
export function lpa(v: unknown, empty = '—'): string {
  if (v === null || v === undefined || v === '') return empty;
  const n = toNumber(v);
  return `${Number.isInteger(n) ? n : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')} LPA`;
}

/** CTC range label for a drive. */
export function ctcRange(min: unknown, max: unknown): string {
  const hasMin = min !== null && min !== undefined && min !== '';
  const hasMax = max !== null && max !== undefined && max !== '';
  if (!hasMin && !hasMax) return '—';
  if (!hasMin || !hasMax || toNumber(min) === toNumber(max)) return lpa(hasMax ? max : min);
  return `${lpa(min).replace(' LPA', '')}–${lpa(max)}`;
}

/** URL to open/download a private file (offer letters, resumes). */
export function privateFileUrl(path: string, name?: string): string {
  return apiUrl('files/download', { path, name });
}

/** Short text wordmark for a company without a logo ("NovaByte Technologies" → "NovaByte"). */
function wordmark(name?: string | null): string {
  const words = String(name ?? '').trim().split(/\s+/).filter(Boolean);
  if (!words.length) return '—';
  const first = words[0];
  return first.length <= 3 && words[1] ? `${first} ${words[1]}` : first;
}

/**
 * Company logo (wide logos keep their aspect ratio). `wide` gives every row the same fixed-width box (lists/tables,
 * so names line up) and falls back to a text wordmark of the company name; otherwise the fallback is initials.
 */
export function CompanyLogo({ name, src, size = 'md', className, wide = false }: { name?: string | null; src?: string | null; size?: 'sm' | 'md' | 'lg'; className?: string; wide?: boolean }) {
  const sz = { sm: 'h-8 w-8 text-[11px] rounded-lg', md: 'h-10 w-10 text-xs rounded-xl', lg: 'h-14 w-14 text-base rounded-2xl' }[size];
  if (wide) {
    const box = { sm: 'h-8 w-[5.5rem] rounded-lg', md: 'h-10 w-[7rem] rounded-xl', lg: 'h-14 w-[9rem] rounded-2xl' }[size];
    return src ? (
      <span className={clsx(box, 'inline-flex shrink-0 items-center justify-center bg-white px-1.5 py-1 ring-1 ring-slate-200 dark:ring-slate-700', className)}>
        <img src={appUrl(src)} alt={name ?? ''} loading="lazy" className="max-h-full max-w-full object-contain" />
      </span>
    ) : (
      <span className={clsx(box, 'inline-flex shrink-0 items-center justify-center bg-brand-50 px-1.5 ring-1 ring-brand-100 dark:bg-brand-500/15 dark:ring-brand-500/20', className)} title={name ?? undefined}>
        <span className="truncate font-display text-[12px] font-extrabold tracking-tight text-brand-800 dark:text-brand-200">{wordmark(name)}</span>
      </span>
    );
  }
  if (src) {
    const box = { sm: 'h-8 min-w-8 max-w-[5.5rem] rounded-lg', md: 'h-10 min-w-10 max-w-[7rem] rounded-xl', lg: 'h-14 min-w-14 max-w-[9rem] rounded-2xl' }[size];
    return <img src={appUrl(src)} alt={name ?? ''} loading="lazy" className={clsx(box, 'w-auto shrink-0 bg-white object-contain px-1.5 py-1 ring-1 ring-slate-200 dark:ring-slate-700', className)} />;
  }
  return (
    <span className={clsx(sz, 'inline-flex shrink-0 items-center justify-center bg-brand-50 font-bold text-brand-800 ring-1 ring-brand-100 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-500/20', className)} aria-hidden>
      {initials(name)}
    </span>
  );
}

/** Compact student cell for wide placement tables (name + ID/program line, truncated so the row actions stay visible). */
export function StudentCell({ name, sub, src, onClick, extra }: { name: string; sub?: string; src?: string | null; onClick?: () => void; extra?: ReactNode }) {
  const body = (
    <>
      <Avatar name={name} src={src} size="sm" />
      <span className="min-w-0 leading-tight">
        <span className="block truncate font-semibold text-slate-900 group-hover:text-brand-700 dark:text-white dark:group-hover:text-brand-300" title={name}>{name}</span>
        {sub && <span className="block truncate text-xs text-slate-500 dark:text-slate-400" title={sub}>{sub}</span>}
        {extra}
      </span>
    </>
  );
  const cls = 'group flex w-[12.5rem] max-w-[12.5rem] items-center gap-2.5 text-left';
  return onClick ? (
    <button type="button" onClick={onClick} className={cls}>
      {body}
    </button>
  ) : (
    <div className={cls}>{body}</div>
  );
}

/** Calendar date tile (drive lists). */
export function DateTile({ date, tone = 'brand' }: { date: string | null | undefined; tone?: 'brand' | 'green' | 'slate' }) {
  const d = date ? new Date(`${date.slice(0, 10)}T00:00:00`) : null;
  const cls = { brand: 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200', green: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', slate: 'bg-slate-100 text-slate-600 dark:bg-slate-800 dark:text-slate-300' }[tone];
  return (
    <span className={clsx('inline-flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl leading-none', cls)}>
      {d ? (
        <>
          <span className="text-[10px] font-semibold uppercase tracking-wide">{d.toLocaleDateString('en-GB', { month: 'short' })}</span>
          <span className="mt-0.5 font-display text-lg font-bold">{d.getDate()}</span>
        </>
      ) : (
        <span className="text-[10px] font-semibold">TBA</span>
      )}
    </span>
  );
}

/** Days from today, e.g. "in 5 days", "today", "3 days ago". */
export function relativeDays(date: string | null | undefined): string {
  if (!date) return 'Date to be announced';
  const d = new Date(`${date.slice(0, 10)}T00:00:00`);
  const t = new Date();
  t.setHours(0, 0, 0, 0);
  const diff = Math.round((d.getTime() - t.getTime()) / 86_400_000);
  if (diff === 0) return 'Today';
  if (diff === 1) return 'Tomorrow';
  if (diff === -1) return 'Yesterday';
  return diff > 0 ? `in ${diff} days` : `${-diff} days ago`;
}

/** Invalidate every placement query (custom endpoints + CRUD lists). */
export function usePlacementRefresh() {
  const qc = useQueryClient();
  return useCallback(
    (...modules: string[]) =>
      Promise.all([
        qc.invalidateQueries({ queryKey: ['placement'] }),
        ...(modules.length ? modules : ['companies', 'placement_drives', 'placement_applications', 'placement_interviews', 'placement_offers', 'placement_trainings']).map((m) =>
          qc.invalidateQueries({ queryKey: ['crud', m] }),
        ),
        qc.invalidateQueries({ queryKey: ['crud-record'] }),
      ]),
    [qc],
  );
}
