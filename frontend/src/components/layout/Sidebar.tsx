import { useEffect, useMemo, useState } from 'react';
import { Link, NavLink, useLocation } from 'react-router-dom';
import clsx from 'clsx';
import { ChevronDown, ExternalLink, Globe, GraduationCap, LifeBuoy, UserRound, X } from 'lucide-react';
import { NAVIGATION, type NavChild, type NavItem } from '@/navigation';
import { useAuth } from '@/lib/auth';
import { appUrl } from '@/lib/config';

function childActive(child: NavChild, pathname: string, search: URLSearchParams) {
  const [path, qs] = child.to.split('?');
  if (pathname !== path) return false;
  if (!qs) return !search.get('tab');
  const want = new URLSearchParams(qs);
  return [...want.entries()].every(([k, v]) => search.get(k) === v);
}

/** Score how well a nav item matches the current path (longest / exact match wins). */
function itemScore(item: NavItem, pathname: string): number {
  let score = 0;
  const consider = (path: string, exactBonus: number) => {
    if (path === '/') {
      if (pathname === '/') score = Math.max(score, 1000);
      return;
    }
    if (pathname === path) score = Math.max(score, path.length + exactBonus);
    else if (pathname.startsWith(`${path}/`)) score = Math.max(score, path.length);
  };
  if (item.to) consider(item.to, 500);
  (item.children ?? []).forEach((c) => consider(c.to.split('?')[0], 400));
  (item.match ?? []).forEach((m) => consider(m, 0));
  return score;
}

interface SidebarProps {
  collapsed: boolean;
  mobileOpen: boolean;
  onCloseMobile: () => void;
}

export function Sidebar({ collapsed, mobileOpen, onCloseMobile }: SidebarProps) {
  const { can, session } = useAuth();
  const location = useLocation();
  const search = useMemo(() => new URLSearchParams(location.search), [location.search]);
  const groups = useMemo(
    () =>
      NAVIGATION.map((g) => ({
        ...g,
        items: g.items
          .map((it) => (it.children ? { ...it, children: it.children.filter((c) => can(c.perm, c.action ?? 'view')) } : it))
          .filter((it) => (it.children ? it.children.length > 0 : can(it.perm, it.action ?? 'view'))),
      })).filter((g) => g.items.length > 0),
    [can],
  );
  const activeKey = useMemo(() => {
    let best: { key: string; score: number } | null = null;
    groups.flatMap((g) => g.items).forEach((i) => {
      const sc = itemScore(i, location.pathname);
      if (sc > 0 && (!best || sc > best.score)) best = { key: i.key, score: sc };
    });
    return (best as { key: string; score: number } | null)?.key;
  }, [groups, location.pathname]);
  const [open, setOpen] = useState<string | null>(activeKey ?? null);
  useEffect(() => {
    if (activeKey) setOpen(activeKey);
  }, [activeKey]);
  useEffect(() => {
    onCloseMobile();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname, location.search]);

  const mini = collapsed && !mobileOpen;
  const logo = session?.app.logo_white ?? appUrl('assets/images/logo-white.svg');

  return (
    <>
      {mobileOpen && <div className="fixed inset-0 z-40 bg-slate-900/50 lg:hidden" onClick={onCloseMobile} aria-hidden />}
      <aside
        className={clsx(
          'fixed inset-y-0 left-0 z-50 flex flex-col bg-gradient-to-b from-[#0B2A5B] via-[#0A2554] to-[#071A3B] text-white shadow-xl transition-all duration-200 lg:z-30',
          mini ? 'lg:w-[76px]' : 'lg:w-[272px]',
          mobileOpen ? 'w-[284px] translate-x-0' : 'w-[284px] -translate-x-full lg:translate-x-0',
        )}
        aria-label="Main navigation"
      >
        <div className={clsx('flex shrink-0 items-center gap-2 border-b border-white/10', mini ? 'h-16 justify-center px-2' : 'px-5 pb-4 pt-5')}>
          <Link to="/" className="flex min-w-0 flex-col" aria-label="GIMT SmartCampus dashboard">
            {mini ? (
              <img src={session?.app.logo_icon ?? appUrl('assets/images/logo-icon.svg')} alt="GIMT" className="h-10 w-10 rounded-lg bg-white p-1" />
            ) : (
              <>
                <img src={logo} alt="Global IMT" className="h-12 w-auto self-start" />
                <span className="mt-2 text-[10.5px] font-bold uppercase leading-tight tracking-wide text-white/90">Global Institute of<br />Management &amp; Technology</span>
              </>
            )}
          </Link>
          <button type="button" className="ml-auto rounded-lg p-1.5 text-white/70 hover:bg-white/10 lg:hidden" onClick={onCloseMobile} aria-label="Close menu">
            <X className="h-5 w-5" />
          </button>
        </div>

        <nav className="flex-1 overflow-y-auto overflow-x-hidden px-3 py-4 scrollbar-none">
          {groups.map((g) => (
            <div key={g.group} className="mb-3">
              {!mini && <p className="mb-1.5 px-3 text-[10px] font-semibold uppercase tracking-[0.14em] text-white/40">{g.group}</p>}
              <ul className="space-y-0.5">
                {g.items.map((item) => {
                  const active = item.key === activeKey;
                  const Icon = item.icon;
                  const base = clsx(
                    'group flex w-full items-center gap-3 rounded-xl text-[13.5px] font-medium transition',
                    mini ? 'h-11 justify-center' : 'px-3 py-2.5',
                    active && !item.children ? 'bg-accent-600 text-white shadow-lg shadow-accent-900/30' : active ? 'bg-white/10 text-white' : 'text-white/75 hover:bg-white/[.07] hover:text-white',
                  );
                  if (!item.children) {
                    return (
                      <li key={item.key}>
                        <NavLink to={item.to ?? '/'} end={item.to === '/'} className={base} title={mini ? item.label : undefined}>
                          <Icon className="h-[18px] w-[18px] shrink-0" aria-hidden />
                          {!mini && <span className="truncate">{item.label}</span>}
                        </NavLink>
                      </li>
                    );
                  }
                  const expanded = open === item.key && !mini;
                  return (
                    <li key={item.key}>
                      {mini ? (
                        <Link to={item.children[0].to} className={base} title={item.label}>
                          <Icon className="h-[18px] w-[18px] shrink-0" aria-hidden />
                        </Link>
                      ) : (
                        <button type="button" className={base} aria-expanded={expanded} onClick={() => setOpen(expanded ? null : item.key)}>
                          <Icon className="h-[18px] w-[18px] shrink-0" aria-hidden />
                          <span className="flex-1 truncate text-left">{item.label}</span>
                          <ChevronDown className={clsx('h-4 w-4 shrink-0 opacity-60 transition-transform', expanded ? 'rotate-180' : '-rotate-90')} aria-hidden />
                        </button>
                      )}
                      {expanded && (
                        <ul className="relative ml-[22px] mt-0.5 space-y-0.5 border-l border-white/10 py-1 pl-3">
                          {item.children.map((c) => {
                            const cActive = childActive(c, location.pathname, search);
                            return (
                              <li key={c.to}>
                                <Link
                                  to={c.to}
                                  aria-current={cActive ? 'page' : undefined}
                                  className={clsx('block rounded-lg px-3 py-1.5 text-[13px] transition', cActive ? 'bg-accent-600/90 font-semibold text-white' : 'text-white/65 hover:bg-white/[.07] hover:text-white')}
                                >
                                  {c.label}
                                </Link>
                              </li>
                            );
                          })}
                        </ul>
                      )}
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </nav>

        {!mini && (
          <div className="m-3 rounded-2xl border border-white/10 bg-white/[.06] p-3.5">
            <p className="mb-2 text-xs font-semibold text-white">Quick Links</p>
            <div className="grid grid-cols-2 gap-x-2 gap-y-1.5 text-[11.5px] text-white/75">
              <a href={appUrl('')} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 hover:text-white"><Globe className="h-3.5 w-3.5" />Visit Website</a>
              <a href={appUrl('student-portal')} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 hover:text-white"><GraduationCap className="h-3.5 w-3.5" />Student Portal</a>
              <a href={appUrl('faculty-portal')} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 hover:text-white"><UserRound className="h-3.5 w-3.5" />Faculty Portal</a>
              <a href={appUrl('contact')} target="_blank" rel="noreferrer" className="inline-flex items-center gap-1.5 hover:text-white"><LifeBuoy className="h-3.5 w-3.5" />Help &amp; Support</a>
            </div>
          </div>
        )}
        {mini && (
          <a href={appUrl('')} target="_blank" rel="noreferrer" className="mx-auto mb-4 rounded-lg p-2 text-white/70 hover:bg-white/10" title="Visit website">
            <ExternalLink className="h-4 w-4" />
          </a>
        )}
      </aside>
    </>
  );
}
