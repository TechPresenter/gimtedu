import type { RouteObject } from 'react-router-dom';
import { page } from '../../shared';

/** Extra routes owned by the placement unit (paths relative to /admin). */
export const routes: RouteObject[] = [
  page('placement/drives/:id', 'placement', () => import('./DriveDetailPage')),
];
