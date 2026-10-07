import type { RouteObject } from 'react-router-dom';

/**
 * Extra routes owned by the CMS content (blog, media, gallery, FAQs, testimonials) unit (paths relative to /admin), e.g. detail pages:
 *   page('cms/blog/:id', 'blog', () => import('./SomeDetailPage')),
 * (import { page } from '../../shared' when adding the first one).
 */
export const routes: RouteObject[] = [];
