import { Suspense, useMemo, useState, type ReactNode } from 'react';
import { NavLink, Outlet, useLocation } from 'react-router-dom';
import { Dialog, DialogPanel, DialogTitle } from '@headlessui/react';
import clsx from 'clsx';
import { CalendarRange, Globe, LayoutGrid, LifeBuoy, LogOut, Mail, Phone, X } from 'lucide-react';
import { appUrl } from '@/lib/config';
import { AREA_LABEL, areaPath, type Area } from '../lib/config';
import { navEntries, type NavEntry, type PortalRoute } from '../lib/routing';
import { usePortal } from '../lib/session';
import { Logo, LogoMark } from './Brand';
import { NoticeBell } from './Notices';
import { ThemeSwitch, UserMenu } from './UserMenu';
import { PortalAvatar } from './Ui';

function groupEntries(entries: NavEntry[]): { group: string | null; items: NavEntry[] }[] {
  const out: { group: string | null; items: NavEntry[] }[] = [];
  for (const e of entries) {
    const g = e.group ?? null;
    const bucket = out.find((b) => b.group === g);
    if (bucket) bucket.items.push(e);
    else out.push({ group: g, items: [e] });
  }
  return out;
}

function SideLink({ area, entry, onClick }: { area: Area; entry: NavEntry; onClick?: () => void }) {
  return (
    <NavLink to={areaPath(area, entry.to)} end={entry.to === ''} onClick={onClick} className={({ isActive }) => clsx('nav-link', isActive && 'nav-link-active')}>
      <entry.icon className="h-[18px] w-[18px] shrink-0" aria-hidden />
      <span className="truncate">{entry.label}</span>
    </NavLink>
  );
}

function SupportCard() {
  const { session } = usePortal();
  const app = session.app;
  return (
    <div className="rounded-2xl bg-gradient-to-br from-brand-900 to-brand-950 p-4 text-white shadow-soft">
      <div className="flex items-center gap-2">
        <span className="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-white/10">
          <LifeBuoy className="h-4 w-4 text-flame-300" aria-hidden />
        </span>
        <p className="font-display text-sm font-bold">Need help?</p>
      </div>
      <p className="mt-2 text-xs leading-relaxed text-white/70">Contact the academic office for corrections to your records.</p>
      <div className="mt-3 space-y-1.5 text-xs">
        {app.support_email && (
          <a href={`mailto:${app.support_email}`} className="flex items-center gap-2 truncate text-white/85 hover:text-white">
            <Mail className="h-3.5 w-3.5 shrink-0" aria-hidden /> {app.support_email}
          </a>
        )}
        {app.support_phone && (
          <a href={`tel:${app.support_phone.replace(/[^\d+]/g, '')}`} className="flex items-center gap-2 text-white/85 hover:text-white">
            <Phone className="h-3.5 w-3.5 shrink-0" aria-hidden /> {app.support_phone}
          </a>
        )}
      </div>
    </div>
  );
}

function Sidebar({ area, groups }: { area: Area; groups: { group: string | null; items: NavEntry[] }[] }) {
  const { identity, session } = usePortal();
  return (
    <aside className="fixed inset-y-0 left-0 z-30 hidden w-[272px] flex-col border-r border-slate-200/80 bg-white dark:border-slate-800 dark:bg-slate-900 lg:flex" aria-label="Portal navigation">
      <div className="flex h-16 items-center gap-3 px-5">
        <Logo src={session.app.logo} srcWhite={session.app.logo_white} className="h-10 w-auto" />
      </div>
      <div className="mx-4 mb-3 flex items-center gap-3 rounded-2xl border border-slate-200/70 bg-slate-50 p-3 dark:border-slate-800 dark:bg-slate-800/40">
        <PortalAvatar name={identity.name} src={identity.photo} />
        <div className="min-w-0">
          <p className="truncate text-sm font-bold text-slate-900 dark:text-white">{identity.name}</p>
          <p className="truncate text-xs text-slate-500 dark:text-slate-400">{identity.sub}</p>
        </div>
      </div>
      <nav className="flex-1 space-y-5 overflow-y-auto px-4 pb-4 pt-1">
        {groups.map((g) => (
          <div key={g.group ?? 'main'}>
            {g.group && <p className="mb-1.5 px-3 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400 dark:text-slate-500">{g.group}</p>}
            <div className="space-y-1">
              {g.items.map((e) => (
                <SideLink key={e.to} area={area} entry={e} />
              ))}
            </div>
          </div>
        ))}
      </nav>
      <div className="space-y-3 p-4 pt-0">
        <SupportCard />
      </div>
    </aside>
  );
}

function MoreSheet({ open, onClose, area, groups, onSignOut, siteUrl }: { open: boolean; onClose: () => void; area: Area; groups: { group: string | null; items: NavEntry[] }[]; onSignOut: () => void; siteUrl: string }) {
  const { identity } = usePortal();
  return (
    <Dialog open={open} onClose={onClose} className="relative z-50 lg:hidden">
      <div className="fixed inset-0 bg-slate-950/50 backdrop-blur-[2px] animate-fade-in" aria-hidden />
      <div className="fixed inset-x-0 bottom-0 flex max-h-[88dvh]">
        <DialogPanel className="safe-bottom flex w-full animate-sheet-up flex-col overflow-hidden rounded-t-[28px] bg-white shadow-pop dark:bg-slate-900">
          <div className="mx-auto mt-2.5 h-1.5 w-10 rounded-full bg-slate-200 dark:bg-slate-700" aria-hidden />
          <div className="flex items-center justify-between px-5 pb-2 pt-3">
            <DialogTitle className="font-display text-lg font-bold text-slate-900 dark:text-white">Menu</DialogTitle>
            <button type="button" onClick={onClose} className="btn-icon h-9 w-9" aria-label="Close menu">
              <X className="h-5 w-5" aria-hidden />
            </button>
          </div>
          <div className="overflow-y-auto px-5 pb-5">
            <div className="flex items-center gap-3 rounded-2xl bg-slate-50 p-3 dark:bg-slate-800/60">
              <PortalAvatar name={identity.name} src={identity.photo} />
              <div className="min-w-0">
                <p className="truncate text-sm font-bold text-slate-900 dark:text-white">{identity.name}</p>
                <p className="truncate text-xs text-slate-500 dark:text-slate-400">{identity.sub}</p>
              </div>
            </div>
            {groups.map((g) => (
              <div key={g.group ?? 'main'} className="mt-4">
                {g.group && <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">{g.group}</p>}
                <div className="grid grid-cols-3 gap-2">
                  {g.items.map((e) => (
                    <NavLink
                      key={e.to}
                      to={areaPath(area, e.to)}
                      end={e.to === ''}
                      onClick={onClose}
                      className={({ isActive }) =>
                        clsx(
                          'flex flex-col items-center gap-1.5 rounded-2xl border px-2 py-3 text-center text-xs font-semibold transition',
                          isActive ? 'border-brand-900 bg-brand-900 text-white dark:border-white/20 dark:bg-white/10' : 'border-slate-200 text-slate-700 hover:bg-slate-50 dark:border-slate-800 dark:text-slate-200 dark:hover:bg-slate-800',
                        )
                      }
                    >
                      <e.icon className="h-5 w-5" aria-hidden />
                      <span className="line-clamp-2 leading-tight">{e.label}</span>
                    </NavLink>
                  ))}
                </div>
              </div>
            ))}
            <div className="mt-5">
              <p className="mb-2 text-[11px] font-bold uppercase tracking-[0.14em] text-slate-400">Appearance</p>
              <ThemeSwitch />
            </div>
            <div className="mt-5 grid grid-cols-2 gap-2">
              <a href={siteUrl} className="btn btn-secondary">
                <Globe className="h-4 w-4" aria-hidden /> Website
              </a>
              <button type="button" onClick={onSignOut} className="btn btn-secondary !text-red-600 dark:!text-red-400">
                <LogOut className="h-4 w-4" aria-hidden /> Sign out
              </button>
            </div>
          </div>
        </DialogPanel>
      </div>
    </Dialog>
  );
}

function BottomTabs({ area, tabs, onMore, moreOpen }: { area: Area; tabs: NavEntry[]; onMore: () => void; moreOpen: boolean }) {
  return (
    <nav className="safe-bottom fixed inset-x-0 bottom-0 z-40 border-t border-slate-200/80 bg-white/90 px-2 pt-1.5 backdrop-blur-lg dark:border-slate-800 dark:bg-slate-900/90 lg:hidden" aria-label="Primary">
      <div className="mx-auto flex max-w-lg items-stretch gap-1">
        {tabs.map((t) => (
          <NavLink key={t.to} to={areaPath(area, t.to)} end={t.to === ''} className={({ isActive }) => clsx('tabbar-link', isActive && !moreOpen && 'tabbar-link-active')}>
            {({ isActive }) => (
              <>
                <span className={clsx('inline-flex h-8 w-12 items-center justify-center rounded-full transition-colors', isActive && !moreOpen ? 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300' : '')}>
                  <t.icon className="h-5 w-5" aria-hidden />
                </span>
                <span className="max-w-full truncate">{t.short ?? t.label}</span>
              </>
            )}
          </NavLink>
        ))}
        <button type="button" onClick={onMore} className={clsx('tabbar-link', moreOpen && 'tabbar-link-active')} aria-haspopup="dialog" aria-expanded={moreOpen}>
          <span className={clsx('inline-flex h-8 w-12 items-center justify-center rounded-full transition-colors', moreOpen && 'bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300')}>
            <LayoutGrid className="h-5 w-5" aria-hidden />
          </span>
          More
        </button>
      </div>
    </nav>
  );
}

function PageFallback() {
  return (
    <div className="space-y-4" aria-busy="true" aria-label="Loading">
      <div className="skeleton h-8 w-56" />
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {[0, 1, 2, 3].map((i) => (
          <div key={i} className="skeleton h-32 rounded-2xl" />
        ))}
      </div>
      <div className="skeleton h-64 rounded-2xl" />
    </div>
  );
}

/** Authenticated portal layout: sidebar (desktop), top bar, content, bottom tab bar + "More" sheet (mobile). */
export function PortalShell({ area, routes, onSignOut, banner }: { area: Area; routes: PortalRoute[]; onSignOut: () => void; banner?: ReactNode }) {
  const { session } = usePortal();
  const [more, setMore] = useState(false);
  const location = useLocation();
  const entries = useMemo(() => navEntries(routes), [routes]);
  const groups = useMemo(() => groupEntries(entries), [entries]);
  const tabs = useMemo(() => entries.filter((e) => e.mobile).slice(0, 4), [entries]);
  const siteUrl = session.app.site_url || appUrl('/');

  return (
    <div className="min-h-[100dvh] bg-slate-50 dark:bg-slate-950">
      <a href="#portal-main" className="sr-only z-[60] rounded-lg bg-white px-3 py-2 text-sm font-semibold text-brand-900 focus:not-sr-only focus:fixed focus:left-3 focus:top-3">
        Skip to content
      </a>
      <Sidebar area={area} groups={groups} />
      <div className="lg:pl-[272px]">
        <header className="sticky top-0 z-20 border-b border-slate-200/70 bg-white/85 backdrop-blur-lg dark:border-slate-800 dark:bg-slate-900/85">
          <div className="mx-auto flex h-16 max-w-[1400px] items-center gap-3 px-4 sm:px-6 lg:px-8">
            <div className="flex min-w-0 items-center gap-2.5 lg:hidden">
              <LogoMark className="h-9 w-9" />
              <div className="min-w-0 leading-tight">
                <p className="truncate font-display text-[15px] font-extrabold text-brand-900 dark:text-white">{session.app.short_name}</p>
                <p className="truncate text-[11px] font-semibold uppercase tracking-wider text-flame-700 dark:text-flame-300">{AREA_LABEL[area]}</p>
              </div>
            </div>
            <div className="hidden min-w-0 items-center gap-3 lg:flex">
              <span className="eyebrow">{AREA_LABEL[area]}</span>
              {session.app.academic_session && (
                <span className="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-600 dark:border-slate-700 dark:bg-slate-800 dark:text-slate-300">
                  <CalendarRange className="h-3.5 w-3.5 text-flame-600 dark:text-flame-300" aria-hidden />
                  Session {session.app.academic_session}
                </span>
              )}
            </div>
            <div className="ml-auto flex items-center gap-1 sm:gap-2">
              <NoticeBell area={area} />
              <UserMenu onSignOut={onSignOut} siteUrl={siteUrl} />
            </div>
          </div>
        </header>
        {banner}
        <main id="portal-main" className="mx-auto max-w-[1400px] px-4 pb-28 pt-5 sm:px-6 sm:pt-6 lg:px-8 lg:pb-12" tabIndex={-1}>
          <Suspense fallback={<PageFallback />}>
            <div key={location.pathname} className="page-enter">
              <Outlet />
            </div>
          </Suspense>
        </main>
      </div>
      <BottomTabs area={area} tabs={tabs} onMore={() => setMore(true)} moreOpen={more} />
      <MoreSheet open={more} onClose={() => setMore(false)} area={area} groups={groups} onSignOut={onSignOut} siteUrl={siteUrl} />
    </div>
  );
}
