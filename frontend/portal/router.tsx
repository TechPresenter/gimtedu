import { lazy, type ReactNode } from 'react';
import { createBrowserRouter, useRouteError, type RouteObject } from 'react-router-dom';
import { RefreshCw, TriangleAlert } from 'lucide-react';
import { AreaRoot, GuestOnly, RequireArea } from './components/Gates';
import { PORTAL_BASE, type Area } from './lib/config';
import type { PortalRoute } from './lib/routing';
import { studentRoutes } from './student/routes';
import { facultyRoutes } from './faculty/routes';

const Landing = lazy(() => import('./pages/Landing'));
const NotFound = lazy(() => import('./pages/NotFound'));
const StudentLogin = lazy(() => import('./student/LoginPage'));
const StudentActivate = lazy(() => import('./student/ActivatePage'));
const StudentForgot = lazy(() => import('./student/ForgotPage'));
const StudentReset = lazy(() => import('./student/ResetPage'));
const FacultyLogin = lazy(() => import('./faculty/LoginPage'));
const FacultyForgot = lazy(() => import('./faculty/ForgotPage'));
const FacultyReset = lazy(() => import('./faculty/ResetPage'));

/** Crash screen for render errors and failed lazy chunks (e.g. after a new build was deployed). */
function RouteError() {
  const error = useRouteError() as Error | undefined;
  const chunk = /dynamically imported module|Importing a module script failed|Failed to fetch/i.test(error?.message ?? '');
  return (
    <div className="flex min-h-[60vh] items-center justify-center p-6">
      <div className="card max-w-md p-8 text-center" role="alert">
        <span className="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300">
          <TriangleAlert className="h-6 w-6" aria-hidden />
        </span>
        <h1 className="mt-4 font-display text-lg font-bold">{chunk ? 'A new version is available' : 'Something went wrong'}</h1>
        <p className="mt-1.5 text-sm text-slate-500 dark:text-slate-400">{chunk ? 'Reload the page to get the latest portal.' : 'This screen hit an unexpected error. Reloading usually fixes it.'}</p>
        <button type="button" className="btn btn-primary mt-5" onClick={() => window.location.reload()}>
          <RefreshCw className="h-4 w-4" aria-hidden /> Reload
        </button>
      </div>
    </div>
  );
}

function guest(area: Area, node: ReactNode): ReactNode {
  return <GuestOnly area={area}>{node}</GuestOnly>;
}

function areaChildren(routes: PortalRoute[]): RouteObject[] {
  return [
    ...routes.map((r): RouteObject => (r.path === '' ? { index: true, element: r.element, errorElement: <RouteError /> } : { path: r.path, element: r.element, errorElement: <RouteError /> })),
    { path: '*', element: <NotFound inShell /> },
  ];
}

export const router = createBrowserRouter(
  [
    { path: '/', element: <Landing />, errorElement: <RouteError /> },
    {
      path: '/student',
      element: <AreaRoot area="student" />,
      errorElement: <RouteError />,
      children: [
        { path: 'login', element: guest('student', <StudentLogin />) },
        { path: 'activate', element: guest('student', <StudentActivate />) },
        { path: 'forgot', element: guest('student', <StudentForgot />) },
        { path: 'reset', element: <StudentReset /> },
        { element: <RequireArea area="student" routes={studentRoutes} />, children: areaChildren(studentRoutes) },
      ],
    },
    {
      path: '/faculty',
      element: <AreaRoot area="faculty" />,
      errorElement: <RouteError />,
      children: [
        { path: 'login', element: guest('faculty', <FacultyLogin />) },
        { path: 'forgot', element: guest('faculty', <FacultyForgot />) },
        { path: 'reset', element: <FacultyReset /> },
        { element: <RequireArea area="faculty" routes={facultyRoutes} />, children: areaChildren(facultyRoutes) },
      ],
    },
    { path: '*', element: <NotFound /> },
  ],
  { basename: PORTAL_BASE },
);
