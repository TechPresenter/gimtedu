import { lazy } from 'react';
import { CalendarCheck } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const AttendancePage = lazy(() => import('./AttendancePage'));

export const routes: PortalRoute[] = [
  { path: 'attendance', element: <AttendancePage />, nav: { label: 'Attendance', icon: CalendarCheck, order: 20, group: 'Academics', mobile: true } },
];
