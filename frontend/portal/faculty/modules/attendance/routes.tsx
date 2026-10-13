import { lazy } from 'react';
import { ClipboardCheck } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const AttendancePage = lazy(() => import('./AttendancePage'));

export const routes: PortalRoute[] = [
  { path: 'attendance', element: <AttendancePage />, nav: { label: 'Attendance', icon: ClipboardCheck, order: 20, group: 'Teaching', mobile: true } },
];
