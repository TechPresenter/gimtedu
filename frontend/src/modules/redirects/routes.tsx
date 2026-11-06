import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the SEO platform unit (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('redirects', 'seo', () => import('./RedirectsPage')),
];
