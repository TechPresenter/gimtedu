import { lazy } from 'react';
import { Plane } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const LeavePage = lazy(() => import('./LeavePage'));

export const routes: PortalRoute[] = [
  { path: 'leave', element: <LeavePage />, nav: { label: 'Leave & Attendance', short: 'Leave', icon: Plane, order: 50, group: 'Office' } },
];
