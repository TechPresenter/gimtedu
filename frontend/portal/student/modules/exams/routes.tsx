import { lazy } from 'react';
import { NotebookPen } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const ExamsPage = lazy(() => import('./ExamsPage'));

export const routes: PortalRoute[] = [
  { path: 'exams', element: <ExamsPage />, nav: { label: 'Examinations', short: 'Exams', icon: NotebookPen, order: 40, group: 'Academics' } },
];
