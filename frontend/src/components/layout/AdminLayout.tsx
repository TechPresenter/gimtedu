import { Suspense, useState } from 'react';
import { Navigate, Outlet, useLocation } from 'react-router-dom';
import clsx from 'clsx';
import { useAuth } from '@/lib/auth';
import { useHotkey, useLocalStorage } from '@/lib/hooks';
import { PageLoader } from '@/components/ui';
import { Sidebar } from './Sidebar';
import { Topbar } from './Topbar';
import { GlobalSearch } from './GlobalSearch';
import { SessionTimeout } from './SessionTimeout';
import { ErrorBoundary } from './ErrorBoundary';

/** Authenticated application shell: sidebar + topbar + routed page. */
export function AdminLayout() {
  const { session, loading } = useAuth();
  const location = useLocation();
  const [collapsed, setCollapsed] = useLocalStorage('gimt.sidebar.collapsed', false);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  useHotkey('k', (e) => {
    e.preventDefault();
    setSearchOpen(true);
  }, { meta: true });

  if (loading) return <Splash />;
  if (!session?.authenticated) {
    const redirect = location.pathname + location.search;
    return <Navigate to={`/login${redirect && redirect !== '/' ? `?redirect=${encodeURIComponent(redirect)}` : ''}`} replace />;
  }

  return (
    <div className="min-h-screen">
      <a href="#main" className="sr-only focus:not-sr-only focus:fixed focus:left-3 focus:top-3 focus:z-[100] focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:shadow">
        Skip to content
      </a>
      <Sidebar collapsed={collapsed} mobileOpen={mobileOpen} onCloseMobile={() => setMobileOpen(false)} />
      <div className={clsx('flex min-h-screen flex-col transition-all duration-200', collapsed ? 'lg:pl-[76px]' : 'lg:pl-[272px]')}>
        <Topbar collapsed={collapsed} onToggleCollapse={() => setCollapsed(!collapsed)} onOpenMobile={() => setMobileOpen(true)} onOpenSearch={() => setSearchOpen(true)} />
        <main id="main" className="mx-auto w-full max-w-[1600px] flex-1 px-4 py-6 sm:px-6 lg:px-8">
          <ErrorBoundary key={location.pathname}>
            <Suspense fallback={<PageLoader />}>
              {/* subtle page-enter transition on every route change */}
              <div key={location.pathname} className="motion-safe:animate-slide-up">
                <Outlet />
              </div>
            </Suspense>
          </ErrorBoundary>
        </main>
        <footer className="border-t border-slate-200/70 px-6 py-4 text-center text-xs text-slate-500 dark:border-slate-800">
          © {new Date().getFullYear()} {session.app.institute_name} · GIMT SmartCampus Admin v{session.app.version}
        </footer>
      </div>
      <GlobalSearch open={searchOpen} onClose={() => setSearchOpen(false)} />
      <SessionTimeout />
    </div>
  );
}

export function Splash() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-4 bg-slate-50 dark:bg-slate-950">
      <img src={`${(window.__GIMT__?.basePath ?? '')}/assets/images/logo.svg`} alt="Global IMT" className="h-16 w-auto animate-pulse dark:hidden" />
      <img src={`${(window.__GIMT__?.basePath ?? '')}/assets/images/logo-white.svg`} alt="Global IMT" className="hidden h-16 w-auto animate-pulse dark:block" />
      <p className="text-sm text-slate-500">Loading SmartCampus…</p>
    </div>
  );
}
