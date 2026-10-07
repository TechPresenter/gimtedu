import type { RouteObject } from 'react-router-dom';

/**
 * Extra routes owned by the CMS site (pages, menus, homepage, banners, SEO) unit (paths relative to /admin), e.g. detail pages:
 *   page('cms/pages/:id', 'cms', () => import('./SomeDetailPage')),
 * (import { page } from '../../shared' when adding the first one).
 */
export const routes: RouteObject[] = [];
