import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the reports module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('reports', 'reports', () => import('./ReportsPage')),
];
