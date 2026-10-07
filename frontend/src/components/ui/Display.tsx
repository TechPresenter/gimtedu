import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowDownRight, ArrowRight, ArrowUpRight, ChevronRight, House, type LucideIcon } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { initials } from '@/lib/format';
import { useDocumentTitle } from '@/lib/hooks';
import { CountUp } from './Motion';

/* ---------------------------------------------------------------- Colors */
export type Tone = 'blue' | 'navy' | 'green' | 'orange' | 'amber' | 'purple' | 'pink' | 'red' | 'cyan' | 'slate';
export const toneClasses: Record<Tone, { bg: string; icon: string; bar: string; text: string; hex: string }> = {
  blue: { bg: 'bg-blue-50/70 dark:bg-blue-500/[.07]', icon: 'bg-blue-100 text-blue-700 dark:bg-blue-500/20 dark:text-blue-300', bar: 'bg-blue-600', text: 'text-blue-700 dark:text-blue-300', hex: '#2563EB' },
  navy: { bg: 'bg-brand-50/70 dark:bg-brand-500/[.07]', icon: 'bg-brand-100 text-brand-800 dark:bg-brand-500/20 dark:text-brand-200', bar: 'bg-brand-700', text: 'text-brand-800 dark:text-brand-200', hex: '#183C7A' },
  green: { bg: 'bg-emerald-50/70 dark:bg-emerald-500/[.07]', icon: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300', bar: 'bg-emerald-500', text: 'text-emerald-700 dark:text-emerald-300', hex: '#10B981' },
  orange: { bg: 'bg-orange-50/70 dark:bg-orange-500/[.07]', icon: 'bg-orange-100 text-orange-600 dark:bg-orange-500/20 dark:text-orange-300', bar: 'bg-orange-500', text: 'text-orange-600 dark:text-orange-300', hex: '#F97316' },
  amber: { bg: 'bg-amber-50/70 dark:bg-amber-500/[.07]', icon: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300', bar: 'bg-amber-500', text: 'text-amber-700 dark:text-amber-300', hex: '#F59E0B' },
  purple: { bg: 'bg-violet-50/70 dark:bg-violet-500/[.07]', icon: 'bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300', bar: 'bg-violet-500', text: 'text-violet-700 dark:text-violet-300', hex: '#8B5CF6' },
  pink: { bg: 'bg-rose-50/70 dark:bg-rose-500/[.07]', icon: 'bg-rose-100 text-rose-600 dark:bg-rose-500/20 dark:text-rose-300', bar: 'bg-rose-500', text: 'text-rose-600 dark:text-rose-300', hex: '#F43F5E' },
  red: { bg: 'bg-red-50/70 dark:bg-red-500/[.07]', icon: 'bg-red-100 text-red-600 dark:bg-red-500/20 dark:text-red-300', bar: 'bg-red-500', text: 'text-red-600 dark:text-red-300', hex: '#EF4444' },
  cyan: { bg: 'bg-cyan-50/70 dark:bg-cyan-500/[.07]', icon: 'bg-cyan-100 text-cyan-700 dark:bg-cyan-500/20 dark:text-cyan-300', bar: 'bg-cyan-500', text: 'text-cyan-700 dark:text-cyan-300', hex: '#06B6D4' },
  slate: { bg: 'bg-slate-50 dark:bg-slate-800/50', icon: 'bg-slate-100 text-slate-600 dark:bg-slate-700 dark:text-slate-300', bar: 'bg-slate-400', text: 'text-slate-600 dark:text-slate-300', hex: '#64748B' },
};

/* ---------------------------------------------------------------- Page header */
export interface Crumb {
  label: string;
  to?: string;
}

interface PageHeaderProps {
  title: string;
  description?: ReactNode;
  breadcrumbs?: Crumb[];
  actions?: ReactNode;
  /** extra content under the title (tabs, badges) */
  children?: ReactNode;
}

/** Page title + breadcrumbs + actions. Also sets document.title. */
export function PageHeader({ title, description, breadcrumbs, actions, children }: PageHeaderProps) {
  useDocumentTitle(title);
  return (
    <div className="mb-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div className="min-w-0">
          {breadcrumbs && breadcrumbs.length > 0 && (
            <nav aria-label="Breadcrumb" className="mb-1.5">
              <ol className="flex flex-wrap items-center gap-1 text-xs text-slate-500 dark:text-slate-400">
                <li>
                  <Link to="/" className="inline-flex items-center hover:text-brand-700 dark:hover:text-white" aria-label="Dashboard">
                    <House className="h-3.5 w-3.5" />
                  </Link>
                </li>
                {breadcrumbs.map((c, i) => (
                  <li key={i} className="flex items-center gap-1">
                    <ChevronRight className="h-3 w-3 text-slate-300 dark:text-slate-600" aria-hidden />
                    {c.to ? (
                      <Link to={c.to} className="hover:text-brand-700 dark:hover:text-white">
                        {c.label}
                      </Link>
                    ) : (
                      <span className="font-medium text-slate-700 dark:text-slate-200" aria-current="page">
                        {c.label}
                      </span>
                    )}
                  </li>
                ))}
              </ol>
            </nav>
          )}
          <h1 className="page-title">{title}</h1>
          {description && <p className="page-subtitle">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
      {children && <div className="mt-4">{children}</div>}
    </div>
  );
}

/* ---------------------------------------------------------------- Stat / KPI cards */
interface StatCardProps {
  label: string;
  value: ReactNode;
  icon: LucideIcon;
  tone?: Tone;
  trend?: { value: string; dir?: 'up' | 'down' | 'flat'; label?: string; positive?: boolean };
  to?: string;
  loading?: boolean;
  hint?: ReactNode;
}

/** Tinted KPI card (dashboard style). */
export function StatCard({ label, value, icon: Icon, tone = 'blue', trend, to, loading, hint }: StatCardProps) {
  const t = toneClasses[tone];
  const dir = trend?.dir ?? 'up';
  const good = trend?.positive ?? dir !== 'down';
  const body = (
    <>
      <span className={clsx('kpi-icon', t.icon)}>
        <Icon className="h-5 w-5" aria-hidden />
      </span>
      <p className="mt-3 text-[13px] font-medium text-slate-600 dark:text-slate-300">{label}</p>
      {loading ? <div className="skeleton mt-1.5 h-7 w-20" /> : <p className="mt-0.5 font-display text-2xl font-bold tracking-tight text-slate-900 dark:text-white">{typeof value === 'number' ? <CountUp value={value} /> : value}</p>}
      {trend && !loading && (
        <p className="mt-1.5 flex items-center gap-1 text-xs">
          <span className={clsx('inline-flex items-center gap-0.5 font-semibold', dir === 'flat' ? 'text-slate-500' : good ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400')}>
            {dir === 'up' ? <ArrowUpRight className="h-3.5 w-3.5" /> : dir === 'down' ? <ArrowDownRight className="h-3.5 w-3.5" /> : <ArrowRight className="h-3.5 w-3.5" />}
            {trend.value}
          </span>
          {trend.label && <span className="truncate text-slate-500 dark:text-slate-400">{trend.label}</span>}
        </p>
      )}
      {hint && !trend && <p className="mt-1.5 truncate text-xs text-slate-500 dark:text-slate-400">{hint}</p>}
    </>
  );
  const cls = clsx('block rounded-2xl border border-slate-200/70 p-4 transition dark:border-slate-800', t.bg, 'duration-200 hover:-translate-y-0.5 hover:shadow-card');
  return to ? (
    <Link to={to} className={cls}>
      {body}
    </Link>
  ) : (
    <div className={cls}>{body}</div>
  );
}

/** Compact stat with icon on the left (module dashboards). */
export function StatTile({ label, value, icon: Icon, tone = 'blue', sub, loading }: { label: string; value: ReactNode; icon: LucideIcon; tone?: Tone; sub?: ReactNode; loading?: boolean }) {
  const t = toneClasses[tone];
  return (
    <div className="card flex items-center gap-4 p-4">
      <span className={clsx('kpi-icon shrink-0', t.icon)}>
        <Icon className="h-5 w-5" aria-hidden />
      </span>
      <div className="min-w-0">
        <p className="truncate text-xs font-medium text-slate-500 dark:text-slate-400">{label}</p>
        {loading ? <div className="skeleton mt-1 h-6 w-16" /> : <p className="font-display text-xl font-bold leading-tight text-slate-900 dark:text-white">{typeof value === 'number' ? <CountUp value={value} /> : value}</p>}
        {sub && <p className="truncate text-xs text-slate-500 dark:text-slate-400">{sub}</p>}
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- Avatar */
const avatarPalette = ['bg-blue-100 text-blue-700', 'bg-emerald-100 text-emerald-700', 'bg-violet-100 text-violet-700', 'bg-amber-100 text-amber-700', 'bg-rose-100 text-rose-700', 'bg-cyan-100 text-cyan-700'];
function hash(s: string) {
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
  return Math.abs(h);
}

export function Avatar({ name, src, size = 'md', className }: { name?: string | null; src?: string | null; size?: 'xs' | 'sm' | 'md' | 'lg' | 'xl'; className?: string }) {
  const sz = { xs: 'h-6 w-6 text-[10px]', sm: 'h-8 w-8 text-xs', md: 'h-9 w-9 text-xs', lg: 'h-12 w-12 text-sm', xl: 'h-20 w-20 text-xl' }[size];
  if (src) return <img src={appUrl(src)} alt={name ?? ''} loading="lazy" className={clsx(sz, 'shrink-0 rounded-full object-cover ring-2 ring-white dark:ring-slate-800', className)} />;
  return (
    <span className={clsx(sz, avatarPalette[hash(name ?? '') % avatarPalette.length], 'inline-flex shrink-0 items-center justify-center rounded-full font-semibold', className)} aria-hidden>
      {initials(name)}
    </span>
  );
}

/** Avatar + name + secondary line (table cells, lists). */
export function PersonCell({ name, sub, src, to, size = 'md' }: { name: ReactNode; sub?: ReactNode; src?: string | null; to?: string; size?: 'sm' | 'md' }) {
  const label = typeof name === 'string' ? name : undefined;
  return (
    <div className="flex min-w-0 items-center gap-3">
      <Avatar name={label} src={src} size={size} />
      <div className="min-w-0 leading-tight">
        {to ? (
          <Link to={to} className="font-semibold text-slate-900 hover:text-brand-700 dark:text-white dark:hover:text-brand-300">
            {name}
          </Link>
        ) : (
          <span className="font-semibold text-slate-900 dark:text-white">{name}</span>
        )}
        {sub && <div className="truncate text-xs text-slate-500 dark:text-slate-400">{sub}</div>}
      </div>
    </div>
  );
}

/* ---------------------------------------------------------------- Progress */
export function ProgressBar({ value, tone = 'blue', className, label }: { value: number; tone?: Tone; className?: string; label?: string }) {
  const v = Math.max(0, Math.min(100, value || 0));
  return (
    <div className={clsx('h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800', className)} role="progressbar" aria-valuenow={Math.round(v)} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <div className={clsx('h-full rounded-full transition-all', toneClasses[tone].bar)} style={{ width: `${v}%` }} />
    </div>
  );
}

/* ---------------------------------------------------------------- Empty state & skeletons */
export function EmptyState({ icon: Icon, title, description, action, className }: { icon: LucideIcon; title: string; description?: ReactNode; action?: ReactNode; className?: string }) {
  return (
    <div className={clsx('flex flex-col items-center px-6 py-14 text-center', className)}>
      <span className="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-brand-50 text-brand-700 ring-8 ring-brand-50/50 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-500/5">
        <Icon className="h-7 w-7" aria-hidden />
      </span>
      <h3 className="mt-5 text-base font-semibold text-slate-900 dark:text-white">{title}</h3>
      {description && <p className="mt-1 max-w-md text-sm text-slate-500 dark:text-slate-400">{description}</p>}
      {action && <div className="mt-5 flex flex-wrap justify-center gap-2">{action}</div>}
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div className={clsx('skeleton', className ?? 'h-4 w-full')} />;
}

export function CardSkeleton({ lines = 4 }: { lines?: number }) {
  return (
    <div className="card space-y-3 p-5">
      <Skeleton className="h-5 w-1/3" />
      {Array.from({ length: lines }).map((_, i) => (
        <Skeleton key={i} className={clsx('h-4', i % 2 ? 'w-5/6' : 'w-full')} />
      ))}
    </div>
  );
}

/* ---------------------------------------------------------------- Description list */
export function DescriptionList({ items, columns = 2 }: { items: { label: string; value: ReactNode; full?: boolean }[]; columns?: 1 | 2 | 3 }) {
  return (
    <dl className={clsx('grid gap-x-6 gap-y-4', columns === 1 ? 'grid-cols-1' : columns === 2 ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3')}>
      {items.map((it, i) => (
        <div key={i} className={clsx(it.full && 'sm:col-span-full')}>
          <dt className="text-xs font-medium uppercase tracking-wide text-slate-500 dark:text-slate-400">{it.label}</dt>
          <dd className="mt-1 break-words text-sm font-medium text-slate-800 dark:text-slate-100">{it.value === null || it.value === undefined || it.value === '' ? <span className="text-slate-400">—</span> : it.value}</dd>
        </div>
      ))}
    </dl>
  );
}

/* ---------------------------------------------------------------- Timeline */
export interface TimelineItem {
  icon?: LucideIcon;
  tone?: Tone;
  title: ReactNode;
  description?: ReactNode;
  time?: ReactNode;
}

export function Timeline({ items, empty = 'No activity yet.' }: { items: TimelineItem[]; empty?: string }) {
  if (!items.length) return <p className="py-6 text-center text-sm text-slate-500">{empty}</p>;
  return (
    <ol className="relative space-y-5 before:absolute before:bottom-2 before:left-4 before:top-2 before:w-px before:bg-slate-200 dark:before:bg-slate-800">
      {items.map((it, i) => {
        const Icon = it.icon;
        return (
          <li key={i} className="relative flex gap-3">
            <span className={clsx('relative z-[1] inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full ring-4 ring-white dark:ring-slate-900', toneClasses[it.tone ?? 'slate'].icon)}>
              {Icon ? <Icon className="h-4 w-4" /> : <span className="h-2 w-2 rounded-full bg-current" />}
            </span>
            <div className="min-w-0 pt-1">
              <p className="text-sm text-slate-800 dark:text-slate-100">{it.title}</p>
              {it.description && <p className="text-xs text-slate-500 dark:text-slate-400">{it.description}</p>}
              {it.time && <p className="mt-0.5 text-xs text-slate-400">{it.time}</p>}
            </div>
          </li>
        );
      })}
    </ol>
  );
}

/* ---------------------------------------------------------------- Tabs */
export interface TabDef {
  key: string;
  label: string;
  icon?: LucideIcon;
  count?: number | string;
}

export function Tabs({ tabs, value, onChange, variant = 'underline', className }: { tabs: TabDef[]; value: string; onChange: (k: string) => void; variant?: 'underline' | 'pills'; className?: string }) {
  if (variant === 'pills') {
    return (
      <div className={clsx('pill-tabs max-w-full overflow-x-auto scrollbar-none', className)} role="tablist">
        {tabs.map((t) => (
          <button key={t.key} type="button" role="tab" aria-selected={value === t.key} onClick={() => onChange(t.key)} className={clsx('pill-tab inline-flex items-center gap-1.5 whitespace-nowrap', value === t.key && 'pill-tab-active')}>
            {t.icon && <t.icon className="h-3.5 w-3.5" />}
            {t.label}
            {t.count !== undefined && <span className="rounded-full bg-slate-200/70 px-1.5 text-[10px] font-semibold dark:bg-slate-700">{t.count}</span>}
          </button>
        ))}
      </div>
    );
  }
  return (
    <div className={clsx('tabs scrollbar-none', className)} role="tablist">
      {tabs.map((t) => (
        <button key={t.key} type="button" role="tab" aria-selected={value === t.key} onClick={() => onChange(t.key)} className={clsx('tab', value === t.key && 'tab-active')}>
          {t.icon && <t.icon className="h-4 w-4" />}
          {t.label}
          {t.count !== undefined && <span className="rounded-full bg-slate-100 px-1.5 py-px text-[10px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-300">{t.count}</span>}
        </button>
      ))}
    </div>
  );
}

/* ---------------------------------------------------------------- Stepper (pipelines) */
export function Stepper({ steps, current }: { steps: { key: string; label: string }[]; current: string }) {
  const idx = steps.findIndex((s) => s.key === current);
  return (
    <ol className="flex w-full items-center gap-1 overflow-x-auto scrollbar-none">
      {steps.map((s, i) => {
        const done = idx >= 0 && i < idx;
        const active = i === idx;
        return (
          <li key={s.key} className="flex min-w-0 flex-1 items-center gap-1">
            <div className="flex min-w-0 flex-col items-center gap-1.5 text-center">
              <span className={clsx('inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-bold', done ? 'bg-accent-600 text-white' : active ? 'bg-brand-800 text-white ring-4 ring-brand-100 dark:ring-brand-500/20' : 'bg-slate-100 text-slate-500 dark:bg-slate-800')}>
                {i + 1}
              </span>
              <span className={clsx('truncate text-[11px] font-medium', active ? 'text-brand-800 dark:text-white' : 'text-slate-500')}>{s.label}</span>
            </div>
            {i < steps.length - 1 && <span className={clsx('mb-5 h-0.5 flex-1 rounded', done ? 'bg-accent-500' : 'bg-slate-200 dark:bg-slate-800')} />}
          </li>
        );
      })}
    </ol>
  );
}
