import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Routes owned by the system module (paths are relative to /admin). */
export const routes: RouteObject[] = [
  page('users', 'users', () => import('./UsersPage')),
  page('roles', 'roles', () => import('./RolesPage')),
  page('activity-logs', 'activity_logs', () => import('./ActivityLogsPage')),
  page('settings', 'settings', () => import('./SettingsPage')),
  page('backup', 'backup', () => import('./BackupPage')),
  page('security', 'security', () => import('./SecurityPage')),
];
