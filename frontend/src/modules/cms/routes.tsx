import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';
import { routes as siteExtra } from './site/routes';
import { routes as contentExtra } from './content/routes';

/** Routes owned by the cms module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('cms', 'cms', () => import('./CmsOverviewPage')),
  page('cms/pages', 'cms', () => import('./PagesPage')),
  page('cms/pages/:id/builder', 'cms', () => import('./PageBuilderPage'), 'edit'),
  page('cms/menus', 'cms', () => import('./MenusPage')),
  page('cms/homepage', 'cms', () => import('./HomepageSectionsPage')),
  page('cms/banners', 'cms', () => import('./BannersPage')),
  page('cms/blog', 'blog', () => import('./BlogPage')),
  page('cms/media', 'media', () => import('./MediaPage')),
  page('cms/gallery', 'media', () => import('./GalleryPage')),
  page('cms/faqs', 'cms', () => import('./FaqsPage')),
  page('cms/testimonials', 'cms', () => import('./TestimonialsPage')),
  page('cms/announcements', 'cms', () => import('./AnnouncementsPage')),
  page('cms/seo', 'seo', () => import('./SeoPage')),
  ...siteExtra, ...contentExtra,
];
