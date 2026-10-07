import type { RouteObject } from 'react-router-dom';

/**
 * Extra routes owned by the placement unit (paths relative to /admin), e.g. detail pages:
 *   page('placement/:id', 'placement', () => import('./SomeDetailPage')),
 * (import { page } from '../../shared' when adding the first one).
 */
export const routes: RouteObject[] = [];
