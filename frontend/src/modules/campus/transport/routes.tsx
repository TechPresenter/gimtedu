import type { RouteObject } from 'react-router-dom';
import { page } from '../../shared';

/** Extra routes owned by the transport unit (paths relative to /admin). */
export const routes: RouteObject[] = [
  page('transport/routes/:id', 'transport', () => import('./RouteDetailPage')),
];
