import type { RouteObject } from 'react-router-dom';
import { page } from '../../shared';

/** Extra routes owned by the alumni unit (paths relative to /admin). Static paths (alumni/events …) rank above :id. */
export const routes: RouteObject[] = [page('alumni/:id', 'alumni', () => import('./AlumniProfilePage'))];
