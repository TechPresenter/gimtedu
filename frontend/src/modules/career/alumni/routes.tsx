import type { RouteObject } from 'react-router-dom';

/**
 * Extra routes owned by the alumni unit (paths relative to /admin), e.g. detail pages:
 *   page('alumni/:id', 'alumni', () => import('./SomeDetailPage')),
 * (import { page } from '../../shared' when adding the first one).
 */
export const routes: RouteObject[] = [];
