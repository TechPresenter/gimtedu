import type { RouteObject } from 'react-router-dom';
import { page } from '../shared';

/** Admin "Portal Access" screen (student / faculty portal logins). Requires users.manage, like its API. */
export const routes: RouteObject[] = [page('portal-access', 'users', () => import('./PortalAccessPage'), 'manage')];
