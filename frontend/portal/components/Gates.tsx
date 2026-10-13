import { useCallback, useEffect, type ReactNode } from 'react';
import { Navigate, Outlet, useLocation, useSearchParams } from 'react-router-dom';
import { KeyRound, LogOut, RefreshCw, WifiOff } from 'lucide-react';
import { useToast } from '@/components/ui';
import { useTheme } from '@/lib/theme';
import { areaPath, type Area } from '../lib/config';
import { errorMessage } from '../lib/errors';
import { usePortalTitle } from '../lib/hooks';
import type { PortalRoute } from '../lib/routing';
import { AreaProvider, useLogout, useSession } from '../lib/session';
import type { AnySession } from '../lib/types';
import { Logo } from './Brand';
import { ChangePasswordForm } from './ChangePasswordForm';
import { SessionWatcher } from './SessionWatcher';
import { PortalShell } from './Shell';

/** Full-screen brand loader while the session boots. */
export function Splash({ label = 'Loading your portal…' }: { label?: string }) {
  return (
    <div className="flex min-h-[100dvh] flex-col items-center justify-center gap-5 bg-slate-50 dark:bg-slate-950" role="status" aria-live="polite">
      <Logo className="h-12 w-auto animate-pulse" />
      <div className="h-1 w-40 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-800">
        <div className="h-full w-1/3 animate-loader-x rounded-full bg-flame-500" />
      </div>
      <span className="sr-only">{label}</span>
    </div>
  );
}

function BootError({ error, onRetry }: { error: unknown; onRetry: () => void }) {
  return (
    <div className="flex min-h-[100dvh] items-center justify-center bg-slate-50 p-6 dark:bg-slate-950">
      <div className="card max-w-md p-8 text-center" role="alert">
        <span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300">
          <WifiOff className="h-6 w-6" aria-hidden />
        </span>
        <h1 className="mt-4 font-display text-lg font-bold">We can't reach the portal</h1>
        <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{errorMessage(error)}</p>
        <button type="button" onClick={onRetry} className="btn btn-primary mt-5">
          <RefreshCw className="h-4 w-4" aria-hidden /> Try again
        </button>
      </div>
    </div>
  );
}

/** Loads the area session (GET /api/portal/{area}/session) before anything inside the area renders. */
export function AreaRoot({ area }: { area: Area }) {
  const { data, isLoading, error, refetch } = useSession(area);
  if (isLoading && !data) return <Splash />;
  if (!data) return <BootError error={error} onRetry={() => void refetch()} />;
  return <Outlet />;
}

/** Sign-in pages: redirect into the area when already signed in (honours ?next=). */
export function GuestOnly({ area, children }: { area: Area; children: ReactNode }) {
  const { data } = useSession(area);
  const [params] = useSearchParams();
  if (data?.authenticated) {
    const next = params.get('next') ?? '';
    const safe = next.startsWith(`/${area}`) && !next.startsWith('//') && !/\/(login|activate|forgot|reset)(\/|$|\?)/.test(next) ? next : areaPath(area);
    return <Navigate to={safe} replace />;
  }
  return <>{children}</>;
}

function ForcedPasswordChange({ area, session, onSignOut }: { area: Area; session: AnySession; onSignOut: () => void }) {
  usePortalTitle('Set a new password', area);
  return (
    <div className="min-h-[100dvh] bg-slate-50 dark:bg-slate-950">
      <header className="border-b border-slate-200/70 bg-white dark:border-slate-800 dark:bg-slate-900">
        <div className="mx-auto flex h-16 max-w-3xl items-center justify-between px-4 sm:px-6">
          <Logo src={session.app.logo} srcWhite={session.app.logo_white} className="h-9 w-auto" />
          <button type="button" onClick={onSignOut} className="btn btn-ghost btn-sm">
            <LogOut className="h-4 w-4" aria-hidden /> Sign out
          </button>
        </div>
      </header>
      <main className="mx-auto max-w-xl px-4 py-8 sm:py-12">
        <div className="card overflow-hidden">
          <div className="hero-navy relative px-6 py-6 text-white sm:px-8">
            <div className="pattern-dots absolute inset-0 opacity-40" aria-hidden />
            <div className="relative flex items-start gap-4">
              <span className="glass inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl">
                <KeyRound className="h-5 w-5 text-flame-300" aria-hidden />
              </span>
              <div>
                <h1 className="font-display text-xl font-extrabold text-white">Set your own password</h1>
                <p className="mt-1 text-sm text-white/75">
                  You signed in with a temporary password issued by the institute. Choose a new private password to continue to your {area === 'student' ? 'student' : 'faculty'} portal.
                </p>
              </div>
            </div>
          </div>
          <div className="p-6 sm:p-8">
            <ChangePasswordForm area={area} policy={session.app.password_policy} submitLabel="Save and continue" />
          </div>
        </div>
      </main>
    </div>
  );
}

/** Applies the theme saved on the account when this browser has no local choice yet. */
function ThemeSync({ theme }: { theme: 'light' | 'dark' | 'system' | null | undefined }) {
  const { setTheme } = useTheme();
  useEffect(() => {
    let local: string | null = null;
    try {
      local = localStorage.getItem('gimt.theme');
    } catch {
      /* private mode */
    }
    if (theme && !local) setTheme(theme, false);
  }, [theme, setTheme]);
  return null;
}

/** Authenticated area: redirects to sign-in, enforces the temporary-password change, renders the app shell. */
export function RequireArea({ area, routes }: { area: Area; routes: PortalRoute[] }) {
  const { data } = useSession(area);
  const location = useLocation();
  const logout = useLogout(area);
  const toast = useToast();
  const signOut = useCallback(async () => {
    try {
      await logout();
      toast.success('You have been signed out securely.');
    } catch {
      toast.error('Sign out failed. Please try again.');
    }
  }, [logout, toast]);

  if (!data) return null;
  const identityOk = area === 'student' ? data.area === 'student' && !!data.student : data.area === 'faculty' && !!data.faculty;
  if (!data.authenticated || !identityOk) {
    const here = `${location.pathname}${location.search}`;
    const next = data.signed_out || here === areaPath(area) ? '' : `?next=${encodeURIComponent(here)}`;
    return <Navigate to={`${areaPath(area, 'login')}${next}`} replace state={{ expired: !!data.expired && !data.signed_out }} />;
  }
  if (data.account?.must_change_password) {
    return (
      <AreaProvider area={area} session={data}>
        <ForcedPasswordChange area={area} session={data} onSignOut={() => void signOut()} />
      </AreaProvider>
    );
  }
  return (
    <AreaProvider area={area} session={data}>
      <ThemeSync theme={data.account?.theme} />
      <SessionWatcher area={area} timeout={data.expires_in ?? data.app.session_timeout} />
      <PortalShell area={area} routes={routes} onSignOut={() => void signOut()} />
    </AreaProvider>
  );
}
