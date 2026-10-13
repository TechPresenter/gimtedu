import { lazy } from 'react';
import { Wallet } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const FeesPage = lazy(() => import('./FeesPage'));

export const routes: PortalRoute[] = [
  { path: 'fees', element: <FeesPage />, nav: { label: 'Fees & Payments', short: 'Fees', icon: Wallet, order: 50, group: 'Campus' } },
];
