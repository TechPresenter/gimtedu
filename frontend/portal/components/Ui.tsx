import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowUpRight, ChevronRight, RefreshCw, ServerCrash, type LucideIcon } from 'lucide-react';
import { CountUp } from '@/components/ui';
import { initials } from '@/lib/format';
import { usePortalTitle } from '../lib/hooks';
import type { Area } from '../lib/config';
import { errorMessage } from '../lib/errors';

/* ---------------------------------------------------------------- Avatar (server URLs already carry the base path) */
const AVATAR_TONES = ['bg-flame-100 text-flame-800', 'bg-brand-100 text-brand-800', 'bg-amber-100 text-amber-800', 'bg-emerald-100 text-emerald-800', 'bg-violet-100 text-violet-800'];

export function PortalAvatar({ name, src, size = 'md', className }: { name: string; src?: string | null; size?: 'sm' | 'md' | 'lg' | 'xl'; className?: string }) {
  const sz = { sm: 'h-8 w-8 text-xs', md: 'h-10 w-10 text-sm', lg: 'h-14 w-14 text-lg', xl: 'h-20 w-20 text-2xl sm:h-24 sm:w-24' }[size];
  if (src) return <img src={src} alt="" className={clsx(sz, 'shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-slate-800', className)} />;
  let h = 0;
  for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) | 0;
  return (
    <span className={clsx(sz, AVATAR_TONES[Math.abs(h) % AVATAR_TONES.length], 'inline-flex shrink-0 items-center justify-center rounded-full font-display font-bold', className)} aria-hidden>
      {initials(name)}
    </span>
  );
}

/* ---------------------------------------------------------------- Page header */
export function PageHead({ title, subtitle, area, actions, crumbs }: { title: string; subtitle?: ReactNode; area: Area; actions?: ReactNode; crumbs?: { label: string; to?: string }[] }) {
  usePortalTitle(title, area);
  return (
    <div className="mb-5 flex flex-col gap-3 sm:mb-6 sm:flex-row sm:items-end sm:justify-between">
      <div className="min-w-0">
        {crumbs && crumbs.length > 0 && (
          <nav aria-label="Breadcrumb" className="mb-1">
            <ol className="flex flex-wrap items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
              {crumbs.map((c, i) => (
                <li key={i} className="flex items-center gap-1">
                  {i > 0 && <ChevronRight className="h-3 w-3 text-slate-300 dark:text-slate-600" aria-hidden />}
                  {c.to ? (
                    <Link to={c.to} className="hover:text-brand-900 dark:hover:text-white">
                      {c.label}
                    </Link>
                  ) : (
                    <span aria-current="page" className="font-medium text-slate-700 dark:text-slate-200">
                      {c.label}
                    </span>
                  )}
                </li>
              ))}
            </ol>
          </nav>
        )}
        <h1 className="page-title">{title}</h1>
        {subtitle && <p className="page-subtitle">{subtitle}</p>}
      </div>
      {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
    </div>
  );
}

/* ---------------------------------------------------------------- KPI card */
export type KpiTone = 'flame' | 'navy' | 'green' | 'amber' | 'red' | 'violet' | 'sky';
const KPI_TONE: Record<KpiTone, { chip: string; accent: string }> = {
  flame: { chip: 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300', accent: 'from-flame-500/70' },
  navy: { chip: 'bg-brand-50 text-brand-800 dark:bg-brand-500/15 dark:text-brand-200', accent: 'from-brand-600/70' },
  green: { chip: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', accent: 'from-emerald-500/70' },
  amber: { chip: 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', accent: 'from-amber-500/70' },
  red: { chip: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300', accent: 'from-red-500/70' },
  violet: { chip: 'bg-violet-50 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300', accent: 'from-violet-500/70' },
  sky: { chip: 'bg-sky-50 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300', accent: 'from-sky-500/70' },
};

interface KpiCardProps {
  label: string;
  icon: LucideIcon;
  tone?: KpiTone;
  /** headline value; numbers count up */
  value: ReactNode;
  /** small text after the value (e.g. "/ 10", "%") */
  unit?: ReactNode;
  foot?: ReactNode;
  /** right side visual (ring etc.) */
  aside?: ReactNode;
  to?: string;
  badge?: ReactNode;
  className?: string;
}

/** Dashboard KPI card (white surface, tinted icon chip, gradient hairline on top). */
export function KpiCard({ label, icon: Icon, tone = 'navy', value, unit, foot, aside, to, badge, className }: KpiCardProps) {
  const t = KPI_TONE[tone];
  const body = (
    <>
      <span className={clsx('absolute inset-x-0 top-0 h-[3px] rounded-t-2xl bg-gradient-to-r to-transparent', t.accent)} aria-hidden />
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2.5">
            <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', t.chip)}>
              <Icon className="h-[18px] w-[18px]" aria-hidden />
            </span>
            <p className="line-clamp-2 text-[13px] font-semibold leading-tight text-slate-600 dark:text-slate-300">{label}</p>
          </div>
          <p className="mt-3 flex items-baseline gap-1 font-display text-[28px] font-extrabold leading-none tracking-tight text-slate-900 dark:text-white">
            {typeof value === 'number' ? <CountUp value={value} /> : value}
            {unit && <span className="text-sm font-semibold text-slate-400 dark:text-slate-500">{unit}</span>}
          </p>
        </div>
        {aside}
      </div>
      {badge && <div className="mt-2.5">{badge}</div>}
      {foot && <div className="mt-2.5 text-xs leading-relaxed text-slate-500 dark:text-slate-400">{foot}</div>}
      {to && <ArrowUpRight className="absolute bottom-3.5 right-3.5 h-4 w-4 text-slate-300 transition group-hover:text-flame-600 dark:text-slate-600" aria-hidden />}
    </>
  );
  const cls = clsx('card group relative block h-full p-4 sm:p-5', to && 'lift', className);
  return to ? (
    <Link to={to} className={cls} aria-label={`${label} – open details`}>
      {body}
    </Link>
  ) : (
    <div className={cls}>{body}</div>
  );
}

export function KpiSkeleton() {
  return (
    <div className="card h-full p-5">
      <div className="flex items-center gap-2.5">
        <div className="skeleton h-9 w-9 rounded-xl" />
        <div className="skeleton h-4 w-24" />
      </div>
      <div className="skeleton mt-4 h-8 w-20" />
      <div className="skeleton mt-3 h-3 w-3/4" />
    </div>
  );
}

/* ---------------------------------------------------------------- Ring progress */
export function Ring({ value, size = 64, stroke = 7, tone = 'flame', label, children }: { value: number; size?: number; stroke?: number; tone?: 'flame' | 'green' | 'amber' | 'red' | 'navy'; label?: string; children?: ReactNode }) {
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  const v = Math.max(0, Math.min(100, value || 0));
  const color = { flame: 'stroke-flame-500', green: 'stroke-emerald-500', amber: 'stroke-amber-500', red: 'stroke-red-500', navy: 'stroke-brand-700 dark:stroke-brand-300' }[tone];
  return (
    <div className="relative shrink-0" style={{ width: size, height: size }} role="img" aria-label={label ?? `${Math.round(v)}%`}>
      <svg width={size} height={size} className="-rotate-90">
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" strokeWidth={stroke} className="stroke-slate-100 dark:stroke-slate-800" />
        <circle
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          strokeWidth={stroke}
          strokeLinecap="round"
          className={clsx(color, 'transition-[stroke-dashoffset] duration-700 ease-out motion-reduce:transition-none')}
          strokeDasharray={c}
          strokeDashoffset={c - (v / 100) * c}
        />
      </svg>
      <div className="absolute inset-0 flex items-center justify-center text-center">{children}</div>
    </div>
  );
}

/* ---------------------------------------------------------------- Section card */
export function Section({ title, subtitle, icon: Icon, action, children, className, bodyClassName }: { title: string; subtitle?: ReactNode; icon?: LucideIcon; action?: ReactNode; children: ReactNode; className?: string; bodyClassName?: string }) {
  return (
    <section className={clsx('card flex flex-col', className)}>
      <header className="card-header items-center">
        <div className="flex min-w-0 items-center gap-2.5">
          {Icon && (
            <span className="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-brand-800 dark:bg-slate-800 dark:text-brand-200">
              <Icon className="h-4 w-4" aria-hidden />
            </span>
          )}
          <div className="min-w-0">
            <h2 className="card-title truncate">{title}</h2>
            {subtitle && <p className="card-subtitle truncate">{subtitle}</p>}
          </div>
        </div>
        {action && <div className="shrink-0">{action}</div>}
      </header>
      <div className={clsx('flex-1', bodyClassName ?? 'p-4 sm:p-5')}>{children}</div>
    </section>
  );
}

/* ---------------------------------------------------------------- Error + empty */
export function ErrorState({ error, onRetry, title = "We couldn't load this right now" }: { error: unknown; onRetry?: () => void; title?: string }) {
  return (
    <div className="card flex flex-col items-center px-6 py-12 text-center" role="alert">
      <span className="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300">
        <ServerCrash className="h-6 w-6" aria-hidden />
      </span>
      <h2 className="mt-4 font-display text-base font-bold text-slate-900 dark:text-white">{title}</h2>
      <p className="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">{errorMessage(error)}</p>
      {onRetry && (
        <button type="button" onClick={onRetry} className="btn btn-secondary btn-sm mt-4">
          <RefreshCw className="h-4 w-4" aria-hidden /> Try again
        </button>
      )}
    </div>
  );
}

export function EmptyBlock({ icon: Icon, title, text, action, compact }: { icon: LucideIcon; title: string; text?: ReactNode; action?: ReactNode; compact?: boolean }) {
  return (
    <div className={clsx('flex flex-col items-center text-center', compact ? 'px-4 py-7' : 'px-6 py-12')}>
      <span className="inline-flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-100 text-slate-500 dark:bg-slate-800 dark:text-slate-400">
        <Icon className="h-5 w-5" aria-hidden />
      </span>
      <p className="mt-3 text-sm font-semibold text-slate-800 dark:text-slate-100">{title}</p>
      {text && <p className="mt-1 max-w-sm text-[13px] text-slate-500 dark:text-slate-400">{text}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

/** Label / value grid for profile screens. */
export function InfoGrid({ items, cols = 2 }: { items: { label: string; value: ReactNode; full?: boolean }[]; cols?: 2 | 3 }) {
  return (
    <dl className={clsx('grid gap-x-6 gap-y-4', cols === 3 ? 'grid-cols-1 sm:grid-cols-2 xl:grid-cols-3' : 'grid-cols-1 sm:grid-cols-2')}>
      {items.map((it) => (
        <div key={it.label} className={clsx('min-w-0', it.full && 'sm:col-span-full')}>
          <dt className="text-[11px] font-semibold uppercase tracking-wider text-slate-400 dark:text-slate-500">{it.label}</dt>
          <dd className="mt-1 break-words text-sm font-medium text-slate-800 dark:text-slate-100">
            {it.value === null || it.value === undefined || it.value === '' ? <span className="text-slate-400">—</span> : it.value}
          </dd>
        </div>
      ))}
    </dl>
  );
}
