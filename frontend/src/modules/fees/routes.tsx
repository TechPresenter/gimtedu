import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the fees module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('fees', 'fees', () => import('./FeesDashboardPage')),
  page('fees/structures', 'fees', () => import('./FeeStructuresPage')),
  page('fees/invoices', 'fees', () => import('./InvoicesPage')),
  page('fees/payments', 'fees', () => import('./PaymentsPage')),
  page('fees/collect', 'fees', () => import('./FeeCollectionPage'), 'create'),
  page('fees/scholarships', 'fees', () => import('./ScholarshipsPage')),
  page('fees/refunds', 'fees', () => import('./RefundsPage')),
  page('fees/reports', 'fees', () => import('./FeeReportsPage')),
  page('expenses', 'expenses', () => import('./ExpensesPage')),
];
