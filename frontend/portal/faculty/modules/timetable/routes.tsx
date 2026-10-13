import { lazy } from 'react';
import { CalendarDays } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const TimetablePage = lazy(() => import('./TimetablePage'));

export const routes: PortalRoute[] = [
  { path: 'timetable', element: <TimetablePage />, nav: { label: 'My Timetable', short: 'Timetable', icon: CalendarDays, order: 10, group: 'Teaching', mobile: true } },
];
