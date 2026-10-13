import { lazy } from 'react';
import { House, KeyRound, Megaphone, UserRound } from 'lucide-react';
import type { PortalRoute } from '../lib/routing';

/**
 * FACULTY PORTAL routes (/portal/faculty/...). The router mounts every entry inside the authenticated layout and the
 * sidebar / mobile tab bar are generated from `nav` (see lib/routing.ts).
 *
 * Extending (later units): either append an entry below, or - preferred, no shared-file edits - create
 * `faculty/modules/<name>/routes.tsx` exporting `export const routes: PortalRoute[] = [...]`; it is picked up
 * automatically, e.g.
 *   const AttendancePage = lazy(() => import('./ClassAttendancePage'));
 *   export const routes: PortalRoute[] = [
 *     { path: 'attendance', element: <AttendancePage />, nav: { label: 'Attendance', icon: ClipboardCheck, order: 20, group: 'Teaching', mobile: true } },
 *   ];
 * Dashboard KPI cards link to 'timetable', 'attendance', 'marks' and 'leave' as soon as such routes exist.
 */
const DashboardPage = lazy(() => import('./DashboardPage'));
const NoticesPage = lazy(() => import('./NoticesPage'));
const ProfilePage = lazy(() => import('./ProfilePage'));
const ChangePasswordPage = lazy(() => import('./ChangePasswordPage'));

const core: PortalRoute[] = [
  { path: '', element: <DashboardPage />, nav: { label: 'Dashboard', short: 'Home', icon: House, order: 0, mobile: true } },
  { path: 'notices', element: <NoticesPage />, nav: { label: 'Notices', icon: Megaphone, order: 70, group: 'Office', mobile: true } },
  { path: 'profile', element: <ProfilePage />, nav: { label: 'My Profile', short: 'Profile', icon: UserRound, order: 900, group: 'Account', mobile: true } },
  { path: 'change-password', element: <ChangePasswordPage />, nav: { label: 'Change Password', short: 'Password', icon: KeyRound, order: 910, group: 'Account' }, allowMustChange: true },
];

const modules = import.meta.glob<{ routes?: PortalRoute[] }>('./modules/*/routes.tsx', { eager: true });

export const facultyRoutes: PortalRoute[] = [...core, ...Object.values(modules).flatMap((m) => m.routes ?? [])];
