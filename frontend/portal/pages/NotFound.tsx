import { Link, useLocation } from 'react-router-dom';
import { ArrowLeft, Compass } from 'lucide-react';
import { areaFromPath, areaPath, PORTAL_BASE } from '../lib/config';
import { usePortalTitle } from '../lib/hooks';

/** 404 inside the portal (inside an area it renders within the app shell). */
export default function NotFound({ inShell = false }: { inShell?: boolean }) {
  const location = useLocation();
  const area = areaFromPath(PORTAL_BASE + location.pathname);
  usePortalTitle('Page not found', area);
  const home = area ? areaPath(area) : '/';
  return (
    <div className={inShell ? 'py-10' : 'flex min-h-[100dvh] items-center justify-center bg-slate-50 px-4 dark:bg-slate-950'}>
      <div className="card mx-auto max-w-md p-8 text-center">
        <span className="mx-auto inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-flame-50 text-flame-600 dark:bg-flame-500/15 dark:text-flame-300">
          <Compass className="h-7 w-7" aria-hidden />
        </span>
        <p className="mt-4 font-display text-4xl font-extrabold text-brand-900 dark:text-white">404</p>
        <h1 className="mt-1 font-display text-lg font-bold">This page doesn't exist</h1>
        <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">The link may be old or mistyped. Head back to your dashboard to continue.</p>
        <Link to={home} className="btn btn-primary mt-5">
          <ArrowLeft className="h-4 w-4" aria-hidden /> {area ? 'Back to dashboard' : 'Choose a portal'}
        </Link>
      </div>
    </div>
  );
}
