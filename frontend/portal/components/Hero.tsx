import type { ComponentType, ReactNode } from 'react';
import clsx from 'clsx';

/** Navy welcome banner used at the top of both dashboards. */
export function WelcomeHero({ eyebrow, title, subtitle, chips, aside, className }: { eyebrow: ReactNode; title: ReactNode; subtitle?: ReactNode; chips?: ReactNode; aside?: ReactNode; className?: string }) {
  return (
    <section className={clsx('hero-navy relative isolate overflow-hidden rounded-3xl px-5 py-6 text-white shadow-soft sm:px-7 sm:py-7', className)}>
      <div className="pattern-grid absolute inset-0 -z-10 opacity-60 [mask-image:radial-gradient(120%_100%_at_100%_0%,black,transparent_70%)]" aria-hidden />
      <div className="absolute -bottom-24 -left-16 -z-10 h-56 w-56 rounded-full bg-brand-500/25 blur-3xl" aria-hidden />
      <svg className="absolute -right-10 -top-10 -z-10 h-48 w-48 text-white/[.06]" viewBox="0 0 200 200" aria-hidden>
        <circle cx="100" cy="100" r="80" fill="none" stroke="currentColor" strokeWidth="26" />
      </svg>
      <div className="flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
        <div className="min-w-0">
          <p className="text-[12px] font-semibold uppercase tracking-[0.16em] text-flame-300">{eyebrow}</p>
          <h1 className="mt-1.5 font-display text-[26px] font-extrabold leading-tight tracking-tight text-white sm:text-[32px]">{title}</h1>
          {subtitle && <p className="mt-1.5 text-sm text-white/75 sm:text-[15px]">{subtitle}</p>}
          {chips && <div className="mt-4 flex flex-wrap gap-2">{chips}</div>}
        </div>
        {aside && <div className="w-full shrink-0 lg:w-[340px]">{aside}</div>}
      </div>
    </section>
  );
}

export function HeroChip({ icon: Icon, children }: { icon?: ComponentType<{ className?: string }>; children: ReactNode }) {
  return (
    <span className="glass inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold text-white">
      {Icon && <Icon className="h-3.5 w-3.5 text-flame-300" />}
      {children}
    </span>
  );
}
