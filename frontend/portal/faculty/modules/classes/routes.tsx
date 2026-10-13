import { lazy } from 'react';
import { UsersRound } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const ClassesPage = lazy(() => import('./ClassesPage'));
const ClassRosterPage = lazy(() => import('./ClassRosterPage'));

export const routes: PortalRoute[] = [
  { path: 'classes', element: <ClassesPage />, nav: { label: 'My Classes', short: 'Classes', icon: UsersRound, order: 15, group: 'Teaching' } },
  { path: 'classes/:sectionId/:subjectId', element: <ClassRosterPage /> },
];
