import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the dashboard module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  { index: true, element: page('', 'dashboard', () => import('./DashboardPage')).element },
  page('notifications', 'dashboard', () => import('./NotificationsPage')),
  page('messages', 'dashboard', () => import('./MessagesPage')),
  page('profile', 'dashboard', () => import('./ProfilePage')),
];
