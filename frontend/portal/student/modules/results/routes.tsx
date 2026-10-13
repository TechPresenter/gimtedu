import { lazy } from 'react';
import { Award } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const ResultsPage = lazy(() => import('./ResultsPage'));

export const routes: PortalRoute[] = [
  { path: 'results', element: <ResultsPage />, nav: { label: 'Results', icon: Award, order: 30, group: 'Academics', mobile: true } },
];
