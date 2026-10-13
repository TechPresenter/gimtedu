import { lazy } from 'react';
import { BedDouble } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const HostelPage = lazy(() => import('./HostelPage'));

export const routes: PortalRoute[] = [
  { path: 'hostel', element: <HostelPage />, nav: { label: 'Hostel', icon: BedDouble, order: 70, group: 'Campus' } },
];
