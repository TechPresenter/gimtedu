import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { ArrowLeft, Lock, Mail, Moon, Phone, Sun, type LucideIcon } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { Logo } from './Brand';
import { usePortalTheme } from '../lib/theme';
import type { PortalApp } from '../lib/types';

interface AuthLayoutProps {
  photo: string;
  /** object-position classes for the photo (e.g. keep signage clear of the logo on phones) */
  photoClassName?: string;
  eyebrow: string;
  title: ReactNode;
  subtitle: ReactNode;
  features: { icon: LucideIcon; label: string }[];
  app?: PortalApp;
  children: ReactNode;
  /** small link shown under the form card (e.g. switch to the other portal) */
  footerLink?: ReactNode;
}

/** Premium split-screen layout for sign-in, activation and password screens (campus photo + GIMT branding). */
export function AuthLayout({ photo, photoClassName, eyebrow, title, subtitle, features, app, children, footerLink }: AuthLayoutProps) {
  const { resolved, choose } = usePortalTheme(null);
  const site = app?.site_url || appUrl('/');
  return (
    <div className="min-h-[100dvh] bg-white dark:bg-slate-950 lg:grid lg:grid-cols-[minmax(0,1.08fr)_minmax(0,1fr)]">
      {/* Brand panel (top banner on phones) */}
      <aside className="relative isolate overflow-hidden bg-brand-950 text-white lg:sticky lg:top-0 lg:h-[100dvh]">
        <img src={photo} alt="" className={clsx('absolute inset-0 -z-20 h-full w-full object-cover', photoClassName)} loading="eager" decoding="async" />
        <div className="absolute inset-0 -z-10 bg-gradient-to-t from-brand-950 via-brand-950/80 to-brand-900/45 lg:bg-gradient-to-tr lg:from-brand-950 lg:via-brand-950/85 lg:to-brand-900/30" />
        <div className="pattern-grid absolute inset-0 -z-10 opacity-40 [mask-image:linear-gradient(to_bottom,transparent,black_35%,black_70%,transparent)]" />
        <div className="absolute -right-24 -top-24 -z-10 h-72 w-72 rounded-full bg-flame-500/25 blur-3xl" aria-hidden />
        <div className="flex h-full flex-col px-5 pb-10 pt-5 sm:px-8 lg:px-12 lg:pb-12 lg:pt-10">
          <div className="flex items-center justify-between">
            <a href={site} className="rounded-xl bg-white/95 px-3 py-1.5 shadow-soft ring-1 ring-white/40 transition hover:bg-white" aria-label="Go to the GIMT website">
              <Logo variant="color" src={app?.logo} className="h-9 w-auto sm:h-10" />
            </a>
            <span className="glass hidden items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold text-white/90 sm:inline-flex">
              <Lock className="h-3.5 w-3.5" aria-hidden /> Secure sign-in
            </span>
          </div>
          <div className="mt-8 max-w-xl lg:mt-auto">
            <p className="text-[11px] font-bold uppercase tracking-[0.2em] text-flame-300">{eyebrow}</p>
            <h1 className="mt-2 font-display text-[26px] font-extrabold leading-[1.12] tracking-tight text-white text-balance sm:text-4xl lg:text-[44px]">{title}</h1>
            <p className="mt-3 hidden max-w-lg text-[15px] leading-relaxed text-white/75 sm:block">{subtitle}</p>
            <ul className="mt-6 hidden flex-wrap gap-2 sm:flex">
              {features.map((f) => (
                <li key={f.label} className="glass inline-flex items-center gap-2 rounded-full px-3.5 py-1.5 text-[13px] font-medium text-white">
                  <f.icon className="h-4 w-4 text-flame-300" aria-hidden />
                  {f.label}
                </li>
              ))}
            </ul>
          </div>
          <p className="mt-10 hidden text-xs text-white/55 lg:block">
            © {new Date().getFullYear()} {app?.institute_name ?? 'Global Institute of Management & Technology'}
          </p>
        </div>
      </aside>

      {/* Form panel */}
      <main className="relative -mt-6 flex min-h-[60dvh] flex-col rounded-t-[28px] bg-white px-5 pb-8 pt-5 dark:bg-slate-950 sm:px-8 lg:mt-0 lg:min-h-[100dvh] lg:rounded-none lg:px-14 lg:py-8">
        <div className="flex items-center justify-between gap-3">
          <a href={site} className="inline-flex items-center gap-1.5 rounded-lg px-1 py-1 text-[13px] font-semibold text-slate-500 transition hover:text-brand-900 dark:text-slate-400 dark:hover:text-white">
            <ArrowLeft className="h-4 w-4" aria-hidden /> Back to website
          </a>
          <button
            type="button"
            onClick={() => choose(resolved === 'dark' ? 'light' : 'dark')}
            className="btn-icon h-9 w-9"
            aria-label={resolved === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
            title={resolved === 'dark' ? 'Light mode' : 'Dark mode'}
          >
            {resolved === 'dark' ? <Sun className="h-[18px] w-[18px]" /> : <Moon className="h-[18px] w-[18px]" />}
          </button>
        </div>
        <div className="mx-auto flex w-full max-w-[440px] flex-1 flex-col justify-center py-8 lg:py-10">
          <div className="animate-fade-up">{children}</div>
          {footerLink && <div className="mt-6 text-center text-sm text-slate-500 dark:text-slate-400">{footerLink}</div>}
        </div>
        <footer className="mx-auto w-full max-w-[440px] border-t border-slate-100 pt-4 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400">
          <p className="font-medium text-slate-600 dark:text-slate-300">Need help signing in?</p>
          <div className="mt-1.5 flex flex-wrap gap-x-4 gap-y-1">
            {app?.support_email && (
              <a href={`mailto:${app.support_email}`} className="inline-flex items-center gap-1.5 hover:text-brand-900 dark:hover:text-white">
                <Mail className="h-3.5 w-3.5" aria-hidden />
                {app.support_email}
              </a>
            )}
            {app?.support_phone && (
              <a href={`tel:${app.support_phone.replace(/[^\d+]/g, '')}`} className="inline-flex items-center gap-1.5 hover:text-brand-900 dark:hover:text-white">
                <Phone className="h-3.5 w-3.5" aria-hidden />
                {app.support_phone}
              </a>
            )}
          </div>
        </footer>
      </main>
    </div>
  );
}

/** Form card heading used on every auth screen. */
export function AuthHeading({ icon: Icon, title, subtitle, tone = 'flame' }: { icon: LucideIcon; title: string; subtitle?: ReactNode; tone?: 'flame' | 'navy' }) {
  return (
    <div className="mb-7">
      <span
        className={clsx(
          'inline-flex h-12 w-12 items-center justify-center rounded-2xl ring-8',
          tone === 'flame' ? 'bg-flame-50 text-flame-600 ring-flame-50/60 dark:bg-flame-500/15 dark:text-flame-300 dark:ring-flame-500/5' : 'bg-brand-50 text-brand-800 ring-brand-50/60 dark:bg-brand-500/15 dark:text-brand-200 dark:ring-brand-500/5',
        )}
      >
        <Icon className="h-6 w-6" aria-hidden />
      </span>
      <h2 className="mt-5 font-display text-[26px] font-extrabold tracking-tight text-slate-900 dark:text-white">{title}</h2>
      {subtitle && <p className="mt-1.5 text-[15px] leading-relaxed text-slate-500 dark:text-slate-400">{subtitle}</p>}
    </div>
  );
}

/** Inline link styled for auth screens. */
export function AuthLink({ to, children, className }: { to: string; children: ReactNode; className?: string }) {
  return (
    <Link to={to} className={clsx('font-semibold text-flame-700 underline-offset-4 hover:text-flame-800 hover:underline dark:text-flame-300 dark:hover:text-flame-200', className)}>
      {children}
    </Link>
  );
}
