import type { RouteObject } from 'react-router-dom';

/**
 * Extra routes owned by the hostel unit (paths relative to /admin), e.g. detail pages:
 *   page('hostel/:id', 'hostel', () => import('./SomeDetailPage')),
 * (import { page } from '../../shared' when adding the first one).
 */
export const routes: RouteObject[] = [];
