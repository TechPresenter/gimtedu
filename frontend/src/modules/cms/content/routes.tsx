import type { RouteObject } from 'react-router-dom';
import { page } from '../../shared';

/** Extra routes owned by the CMS content unit (blog editor, gallery album manager). Paths are relative to /admin. */
export const routes: RouteObject[] = [
  page('cms/blog/new', 'blog', () => import('./BlogPostEditorPage'), 'create'),
  page('cms/blog/:id', 'blog', () => import('./BlogPostEditorPage')),
  page('cms/gallery/:id', 'media', () => import('./GalleryDetailPage')),
];
