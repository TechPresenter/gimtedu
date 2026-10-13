import { lazy } from 'react';
import { WalletCards } from 'lucide-react';
import type { PortalRoute } from '../../../lib/routing';

const PayrollPage = lazy(() => import('./PayrollPage'));

export const routes: PortalRoute[] = [
  { path: 'payroll', element: <PayrollPage />, nav: { label: 'Payroll', icon: WalletCards, order: 65, group: 'Office' } },
];
