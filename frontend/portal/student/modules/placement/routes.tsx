import { lazy } from 'react';
import { Briefcase } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const PlacementPage = lazy(() => import('./PlacementPage'));

export const routes: PortalRoute[] = [
  { path: 'placement', element: <PlacementPage />, nav: { label: 'Placements', icon: Briefcase, order: 610, group: 'Services' } },
];
