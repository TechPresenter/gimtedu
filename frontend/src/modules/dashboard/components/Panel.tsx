import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowRight, type LucideIcon } from 'lucide-react';
import { Card, EmptyState } from '@/components/ui';
import { HUES, type Hue } from './hues';

/**
 * Dashboard card: thin gradient accent along the top + gradient icon badge in the section's hue, bold title,
 * optional subtitle, right-aligned control (period select / link) and a body.
 */
export function Panel({ title, subtitle, icon: Icon, hue = 'navy', action, children, className, bodyClassName, footer }: {
  title: ReactNode;
  subtitle?: ReactNode;
  icon?: LucideIcon;
  hue?: Hue;
  action?: ReactNode;
  children: ReactNode;
  className?: string;
  bodyClassName?: string;
  footer?: ReactNode;
}) {
  const h = HUES[hue];
  return (
    <Card className={clsx('relative flex h-full flex-col overflow-hidden', className)}>
      <span className={clsx('absolute inset-x-0 top-0 h-1 bg-gradient-to-r', h.edge)} aria-hidden />
      {/* On narrow cards (phones, the 4-up chart row) the header control wraps under the title instead of squeezing it into several lines. */}
      <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-2 px-5 pb-1 pt-5 sm:px-6">
        <div className="flex min-w-0 flex-1 basis-52 items-start gap-3">
          {Icon && (
            <span className={clsx('inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl', h.chip)}>
              <Icon className="h-[18px] w-[18px]" aria-hidden />
            </span>
          )}
          <div className="min-w-0">
            <h2 className="font-display text-base font-bold leading-snug tracking-tight text-slate-900 dark:text-white">{title}</h2>
            {subtitle && <p className="mt-0.5 text-xs text-slate-500 dark:text-slate-400">{subtitle}</p>}
          </div>
        </div>
        {action && <div className="ml-auto flex shrink-0 items-center gap-2">{action}</div>}
      </div>
      <div className={clsx('flex-1 px-5 pb-6 pt-4 sm:px-6', bodyClassName)}>{children}</div>
      {footer && <div className="border-t border-slate-100 px-5 py-3 text-xs text-slate-500 sm:px-6 dark:border-slate-800 dark:text-slate-400">{footer}</div>}
    </Card>
  );
}

/** Small native select used in panel headers ("Last 6 months", "This session" …). */
export function PeriodSelect<T extends string>({ value, onChange, options, label }: { value: T; onChange: (v: T) => void; options: { value: T; label: string }[]; label: string }) {
  return (
    <select
      value={value}
      onChange={(e) => onChange(e.target.value as T)}
      aria-label={label}
      className="h-8 rounded-full border border-slate-200 bg-white py-0 pl-3 pr-8 text-xs font-semibold text-slate-600 shadow-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-200"
    >
      {options.map((o) => (
        <option key={o.value} value={o.value}>
          {o.label}
        </option>
      ))}
    </select>
  );
}

/** "View all →" link in panel headers. */
export function ViewAll({ to, label = 'View all' }: { to: string; label?: string }) {
  return (
    <Link
      to={to}
      className="group/va inline-flex items-center gap-1 rounded-full bg-slate-50 px-2.5 py-1 text-xs font-semibold text-brand-700 ring-1 ring-inset ring-slate-200/80 transition hover:bg-brand-50 hover:text-brand-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500/40 dark:bg-slate-800 dark:text-brand-200 dark:ring-slate-700 dark:hover:bg-slate-700 dark:hover:text-white"
    >
      {label} <ArrowRight className="h-3.5 w-3.5 transition-transform group-hover/va:translate-x-0.5 motion-reduce:transition-none" aria-hidden />
    </Link>
  );
}

/** Compact empty state that fits inside a dashboard card. */
export function PanelEmpty({ icon, title, description, action }: { icon: LucideIcon; title: string; description?: ReactNode; action?: ReactNode }) {
  return <EmptyState icon={icon} title={title} description={description} action={action} className="!py-8" />;
}
