import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { AlertTriangle, BookOpen, CalendarClock, Mail, Phone, RefreshCw, RotateCcw, SearchX } from 'lucide-react';
import { useQueryClient } from '@tanstack/react-query';
import { Avatar, Badge, Button, ProgressBar, StatusBadge } from '@/components/ui';
import { apiUrl } from '@/lib/api';
import { appUrl } from '@/lib/config';
import { formatDate, formatMoney, isoDate } from '@/lib/format';
import type { BadgeColor } from '@/lib/status';
import type { LibMember, Loan, LoanState, MemberType } from './types';

/* ---------------------------------------------------------------- Labels & colours */

export const MEMBER_TYPES: { value: MemberType; label: string }[] = [
  { value: 'student', label: 'Student' },
  { value: 'faculty', label: 'Faculty' },
  { value: 'staff', label: 'Staff' },
];
export const MEMBER_TYPE_COLORS: Record<string, BadgeColor> = { student: 'blue', faculty: 'purple', staff: 'cyan' };
export const LOAN_STATE_COLORS: Record<string, BadgeColor> = { issued: 'blue', due_today: 'amber', overdue: 'red', returned: 'green', returned_late: 'amber', lost: 'slate' };
export const LOAN_STATE_LABELS: Record<LoanState, string> = { issued: 'On loan', due_today: 'Due today', overdue: 'Overdue', returned: 'Returned', returned_late: 'Returned late', lost: 'Lost' };
export const FINE_REASON_LABELS: Record<string, string> = { late_return: 'Overdue return', lost: 'Lost book', damage: 'Damaged book', other: 'Other' };
export const FINE_REASON_COLORS: Record<string, BadgeColor> = { late_return: 'amber', lost: 'red', damage: 'purple', other: 'slate' };
export const FINE_STATUS_COLORS: Record<string, BadgeColor> = { unpaid: 'red', paid: 'green', waived: 'slate' };
export const COPY_STATUS_COLORS: Record<string, BadgeColor> = { available: 'green', issued: 'blue', damaged: 'amber', lost: 'red', withdrawn: 'slate', reserved: 'purple' };
export const MEMBERSHIP_COLORS: Record<string, BadgeColor> = { active: 'green', suspended: 'red', expired: 'amber' };

export const libCrumbs = (label?: string) => [{ label: 'Library', to: '/library' }, ...(label ? [{ label }] : [])];

/** Server-rendered print views live in the library API (A4 HTML, opened in a new tab). */
export function libPrintUrl(view: 'cards' | 'receipt' | 'overdue' | 'labels', params: Record<string, string | number | undefined> = {}) {
  const q: Record<string, string | number> = {};
  Object.entries(params).forEach(([k, v]) => {
    if (v !== undefined && v !== '') q[k] = v;
  });
  return apiUrl(`library/print/${view}`, q);
}

export function openPrint(view: Parameters<typeof libPrintUrl>[0], params: Record<string, string | number | undefined> = {}) {
  window.open(libPrintUrl(view, params), '_blank', 'noopener');
}

/** Invalidate every cached library query (dashboard, member cards, CRUD lists) after a circulation action. */
export function useLibraryRefresh() {
  const qc = useQueryClient();
  return () =>
    Promise.all([
      qc.invalidateQueries({ queryKey: ['library'] }),
      ...['library_transactions', 'library_fines', 'library_members', 'books', 'book_copies', 'book_categories', 'authors', 'publishers'].map((m) =>
        qc.invalidateQueries({ queryKey: ['crud', m] }),
      ),
    ]);
}

/* ---------------------------------------------------------------- Book cover */

const COVER_TONES = [
  'from-brand-800 to-brand-600',
  'from-emerald-700 to-emerald-500',
  'from-violet-700 to-violet-500',
  'from-amber-600 to-orange-500',
  'from-cyan-700 to-cyan-500',
  'from-rose-700 to-rose-500',
  'from-slate-700 to-slate-500',
  'from-blue-700 to-sky-500',
];
function hash(s: string) {
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
  return Math.abs(h);
}

const coverSize = { xs: 'h-10 w-7 text-[7px]', sm: 'h-12 w-9 text-[8px]', md: 'h-20 w-14 text-[9px]', lg: 'h-40 w-28 text-xs', xl: 'h-52 w-36 text-sm' };

/** Book cover image, or a generated "cloth binding" cover with the title when there is no image. */
export function BookCover({ title, src, size = 'sm', className }: { title: string; src?: string | null; size?: keyof typeof coverSize; className?: string }) {
  if (src) {
    return <img src={appUrl(src)} alt="" loading="lazy" className={clsx(coverSize[size], 'shrink-0 rounded-md object-cover shadow-sm ring-1 ring-black/5', className)} />;
  }
  const tone = COVER_TONES[hash(title) % COVER_TONES.length];
  const big = size === 'lg' || size === 'xl';
  return (
    <span aria-hidden className={clsx(coverSize[size], 'relative flex shrink-0 flex-col justify-between overflow-hidden rounded-md bg-gradient-to-br p-1 text-white shadow-sm ring-1 ring-black/5', tone, className)}>
      <span className="absolute inset-y-0 left-0 w-[3px] bg-black/20" />
      {big ? (
        <>
          <BookOpen className="h-4 w-4 opacity-70" />
          <span className="line-clamp-4 pl-1 font-display font-bold leading-tight">{title}</span>
          <span className="h-0.5 w-8 rounded bg-white/50" />
        </>
      ) : (
        <span className="m-auto font-display font-bold uppercase leading-none tracking-tight opacity-90">{initialsOf(title)}</span>
      )}
    </span>
  );
}
function initialsOf(title: string) {
  const words = title.replace(/[^A-Za-z0-9 ]/g, ' ').split(/\s+/).filter((w) => w.length > 2 || /^[A-Z0-9]/.test(w));
  return (words.slice(0, 2).map((w) => w[0]).join('') || title.slice(0, 2)).toUpperCase();
}

/* ---------------------------------------------------------------- Badges */

export function LoanStateBadge({ state }: { state: LoanState | string }) {
  return <StatusBadge status={state} label={LOAN_STATE_LABELS[state as LoanState] ?? undefined} colors={LOAN_STATE_COLORS} />;
}

export function MemberTypeBadge({ type }: { type: string }) {
  return <Badge color={MEMBER_TYPE_COLORS[type] ?? 'slate'}>{MEMBER_TYPES.find((t) => t.value === type)?.label ?? type}</Badge>;
}

export function membershipState(m: Pick<LibMember, 'status' | 'is_expired'>) {
  return m.status === 'active' && m.is_expired ? 'expired' : m.status;
}

/** "With / due" cell of the accession register: borrower name with the due date underneath (red when overdue). */
export function CopyBorrowerCell({ borrower, due }: { borrower?: string | null; due?: string | null }) {
  if (!borrower) return <span className="text-slate-400">—</span>;
  const overdue = !!due && due < isoDate();
  return (
    <span className="block whitespace-nowrap leading-tight">
      <span className="block text-slate-700 dark:text-slate-200">{borrower}</span>
      {due && <span className={clsx('block text-xs', overdue ? 'font-semibold text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-slate-400')}>{overdue ? 'Overdue since' : 'Due'} {formatDate(due)}</span>}
    </span>
  );
}

/* ---------------------------------------------------------------- Member summary */

/** Profile + borrowing limits block used on the issue desk and in the member drawer. */
export function MemberSummary({ member, blocks, compact, actions }: { member: LibMember; blocks?: string[]; compact?: boolean; actions?: ReactNode }) {
  const state = membershipState(member);
  const pct = member.max_books ? (member.loans / member.max_books) * 100 : 0;
  return (
    <div className="space-y-4">
      <div className="flex items-start gap-3">
        <Avatar name={member.name} src={member.photo} size={compact ? 'lg' : 'xl'} />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="truncate font-display text-base font-bold text-slate-900 dark:text-white">{member.name}</h3>
            <MemberTypeBadge type={member.member_type} />
            <StatusBadge status={state} colors={MEMBERSHIP_COLORS} />
          </div>
          <p className="mt-0.5 truncate text-sm text-slate-500 dark:text-slate-400">{member.info || member.department || '—'}</p>
          <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
            <span className="rounded bg-slate-100 px-1.5 py-0.5 font-mono font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{member.membership_no}</span>
            {member.code && <span>ID {member.code}</span>}
            {member.phone && (
              <a href={`tel:${member.phone.replace(/\s/g, '')}`} className="inline-flex items-center gap-1 hover:text-brand-700 dark:hover:text-brand-300">
                <Phone className="h-3 w-3" /> {member.phone}
              </a>
            )}
            {member.email && !compact && (
              <a href={`mailto:${member.email}`} className="inline-flex min-w-0 items-center gap-1 truncate hover:text-brand-700 dark:hover:text-brand-300">
                <Mail className="h-3 w-3" /> {member.email}
              </a>
            )}
          </div>
        </div>
        {actions && <div className="flex shrink-0 gap-1">{actions}</div>}
      </div>

      <div className="grid grid-cols-3 gap-2 text-center">
        <MiniStat label="Books issued" value={`${member.loans} / ${member.max_books}`} tone={member.loans >= member.max_books ? 'text-amber-600 dark:text-amber-400' : undefined} />
        <MiniStat label="Overdue" value={member.overdue} tone={member.overdue ? 'text-red-600 dark:text-red-400' : undefined} />
        <MiniStat label="Unpaid fines" value={formatMoney(member.unpaid_fines)} tone={member.unpaid_fines > 0 ? 'text-red-600 dark:text-red-400' : undefined} />
      </div>
      <div>
        <div className="mb-1 flex items-center justify-between text-xs text-slate-500 dark:text-slate-400">
          <span>Borrowing limit used</span>
          <span>
            {member.loan_days}-day loans · valid till {member.valid_until ? formatDate(member.valid_until) : 'further notice'}
          </span>
        </div>
        <ProgressBar value={pct} tone={pct >= 100 ? 'amber' : 'green'} label="Borrowing limit used" />
      </div>
      {blocks && blocks.length > 0 && (
        <div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300" role="alert">
          <p className="flex items-center gap-1.5 font-semibold">
            <AlertTriangle className="h-4 w-4" /> Cannot issue new books
          </p>
          <ul className="mt-1 list-disc space-y-0.5 pl-6 text-[13px]">
            {blocks.map((b) => (
              <li key={b}>{b}</li>
            ))}
          </ul>
        </div>
      )}
      {member.remarks && state !== 'active' && <p className="text-xs text-slate-500 dark:text-slate-400">Note: {member.remarks}</p>}
    </div>
  );
}

function MiniStat({ label, value, tone }: { label: string; value: ReactNode; tone?: string }) {
  return (
    <div className="rounded-xl bg-slate-50 px-2 py-2.5 dark:bg-slate-800/60">
      <p className={clsx('font-display text-base font-bold text-slate-900 dark:text-white', tone)}>{value}</p>
      <p className="text-[11px] text-slate-500 dark:text-slate-400">{label}</p>
    </div>
  );
}

/* ---------------------------------------------------------------- Loan row */

interface LoanRowProps {
  loan: Loan;
  onReturn?: (l: Loan) => void;
  onRenew?: (l: Loan) => void;
  onLost?: (l: Loan) => void;
  showMember?: boolean;
  busy?: boolean;
}

/** A current loan with dates, state, accrued fine and quick actions. */
export function LoanRow({ loan, onReturn, onRenew, onLost, showMember, busy }: LoanRowProps) {
  const overdue = loan.state === 'overdue';
  return (
    <li className={clsx('flex flex-col gap-3 rounded-xl border p-3 sm:flex-row sm:items-center', overdue ? 'border-red-200 bg-red-50/40 dark:border-red-500/30 dark:bg-red-500/5' : 'border-slate-200 dark:border-slate-800')}>
      <div className="flex min-w-0 flex-1 items-start gap-3">
        <BookCover title={loan.title} src={loan.cover_image} size="sm" />
        <div className="min-w-0 flex-1">
          <Link to={`/library/books/${loan.book_id}`} className="line-clamp-1 text-sm font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
            {loan.title}
          </Link>
          <p className="truncate text-xs text-slate-500 dark:text-slate-400">
            <span className="font-mono">{loan.accession_no}</span>
            {showMember ? ` · ${loan.member.name} (${loan.member.membership_no})` : loan.authors ? ` · ${loan.authors}` : ''}
          </p>
          <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500 dark:text-slate-400">
            <span className="inline-flex items-center gap-1">
              <CalendarClock className="h-3 w-3" /> Due {formatDate(loan.due_date)}
            </span>
            <LoanStateBadge state={loan.state} />
            {overdue && (
              <span className="font-semibold text-red-600 dark:text-red-400">
                {loan.days_overdue} day{loan.days_overdue === 1 ? '' : 's'} late · {formatMoney(loan.accrued_fine)}
              </span>
            )}
            {loan.renew_count > 0 && <span>Renewed ×{loan.renew_count}</span>}
          </div>
        </div>
      </div>
      {(onReturn || onRenew || onLost) && (
        <div className="flex shrink-0 flex-wrap gap-1.5 sm:justify-end">
          {onReturn && (
            <Button size="xs" icon={RotateCcw} onClick={() => onReturn(loan)} disabled={busy}>
              Return
            </Button>
          )}
          {onRenew && (
            <Button size="xs" variant="secondary" icon={RefreshCw} onClick={() => onRenew(loan)} disabled={busy || !loan.can_renew} title={loan.can_renew ? 'Renew loan' : 'Overdue loans or loans at the renewal limit cannot be renewed'}>
              Renew
            </Button>
          )}
          {onLost && (
            <Button size="xs" variant="ghost" icon={SearchX} onClick={() => onLost(loan)} disabled={busy} className="text-red-600 hover:!bg-red-50 dark:text-red-400 dark:hover:!bg-red-500/10">
              Lost
            </Button>
          )}
        </div>
      )}
    </li>
  );
}
