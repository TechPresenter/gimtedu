import { lazy } from 'react';
import { Bus } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const TransportPage = lazy(() => import('./TransportPage'));

export const routes: PortalRoute[] = [
  { path: 'transport', element: <TransportPage />, nav: { label: 'Transport', icon: Bus, order: 80, group: 'Campus' } },
];
