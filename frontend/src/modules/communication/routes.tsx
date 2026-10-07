import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the communication module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('notices', 'notices', () => import('./NoticesPage')),
  page('events', 'events', () => import('./EventsPage')),
  page('newsletter', 'communication', () => import('./CampaignsPage')),
  page('newsletter/subscribers', 'communication', () => import('./SubscribersPage')),
  page('email-templates', 'communication', () => import('./EmailTemplatesPage')),
  page('message-logs', 'communication', () => import('./MessageLogsPage')),
];
